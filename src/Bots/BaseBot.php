<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrlUser;
use App\Model\ActionState;
use App\Model\ActionStatusDto;
use App\Service\CacheService;
use App\Service\ProfileService;
use App\Service\ProxyService;
use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar as GuzzleCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Prometheus\CollectorRegistry;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\Cookie\CookieJar as SymfonyCookieJar;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class BaseBot
{
    const TTL = 3600 * 24 * 2;
    #[Required] public LoggerInterface $logger;
    #[Required] public CacheService $cache;
    #[Required] public ProfileService $profileService;
    #[Required] public CollectorRegistry $collectionRegistry;
    #[Required] public MessageBusInterface $bus;
    protected string $curProfile = '';

    public function setProfile(string $profile)
    {
        $this->curProfile = $profile;
        return $this;
    }

    public function getName(): string
    {
        return (new ReflectionClass(static::class))->getShortName();
    }

    public function UCSet($key, $value, $ttl = self::TTL)
    {
        return $this->cache->setEx($this->userKey($key), $ttl, $value);
    }
    public function UCGet($key)
    {
        return $this->cache->get($this->userKey($key));
    }

    public function getProxy()
    {
        return $this->profileService->getGuzzleProxy($this->curProfile);
    }

    public function runInTg(Client $client)
    {
    }

    public function saveUrl($client, $url)
    {
        $this->UCSet('url', $url);
    }

    public function getUrl()
    {
        return $this->UCGet('url');
    }

    protected function platformFix($url)
    {
        return str_replace('tgWebAppPlatform=web', 'tgWebAppPlatform=android', $url);
    }

    public function addSchedule(Schedule $schedule): void
    {
    }

    protected function convertCookies(SymfonyCookieJar $symfonyCookieJar): GuzzleCookieJar
    {
        $host = parse_url($this->getUrl(), PHP_URL_HOST);
        $jar = new GuzzleCookieJar();
        foreach ($symfonyCookieJar->all() as $cookie) {
            $jar->setCookie(new SetCookie([
                'Domain' => $host,
                'Name' => $cookie->getName(),
                'Value' => $cookie->getValue(),
                'Discard' => true,
            ]));
        }
        return $jar;
    }

    /**
     * @param string $name
     * @param float $value
     */
    protected function updateStatItem($name, $value)
    {
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            $name,
            ucfirst($name),
            ['user']
        );
        $gauge->set($value, [$this->curProfile]);
        $this->cache->hSet($this->userKey('status'), $name, $value);
    }

    public function userKey(string $key)
    {
        return $this->getName() . ':' . $this->curProfile . ':' . $key;
    }

    public function botKey(string $key)
    {
        return $this->getName() . ':::' . $key;
    }

    //<editor-fold desc="actions">
    public function logAction(string $name, ActionState $status)
    {
        $key = $this->getName() . ':' . $this->curProfile . '::actions';
        $this->cache->hSet(
            $key,
            $name . ':' . $status->value,
            Carbon::now()->getTimestamp()
        );
    }

    /**
     * @return ActionStatusDto[]
     * @throws \RedisException
     */
    public function getActions(): array
    {
        $key = $this->getName() . ':' . $this->curProfile . '::actions';
        $actions = $this->cache->hGetAll($key);
        $stat = [];
        foreach ($actions as $record => $time) {
            [$name, $status] = explode(':', $record);
            $stat[$name][$status] = $time;
        }
        foreach ($stat as $k => $item) {
            $stat[$k] = new ActionStatusDto($item);
        }
        return $stat;
    }
    //</editor-fold>

    public function markRun(string $name)
    {
        $this->cache->hSet(
            $this->userKey('run'),
            $name,
            Carbon::now()->getTimestamp()
        );
    }

    protected function runDelay($callback, $delay = 10): void
    {
        $this->bus->dispatch(
            new CustomFunctionUser($this->curProfile, $this->getName(), $callback),
            [new DelayStamp($delay * 1000)]
        );
    }

    protected function runUpdate($delay = 10): void
    {
        $this->bus->dispatch(
            new UpdateUrlUser($this->curProfile, $this->getName()),
            [new DelayStamp($delay * 1000)]
        );
    }
}
