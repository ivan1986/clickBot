<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Carbon\Carbon;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Panther\Client;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class TonStationBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'tonstationgames_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName()))->withJitter(7200));
        $schedule->add(RecurringMessage::every('2 hour', new CustomFunction($this->getName(), 'claimAndReset')));
        $schedule->add(RecurringMessage::every('8 hour', new CustomFunction($this->getName(), 'getBalance')));
    }

    public function runInTg(Client $client)
    {
        $client->executeScript(<<<JS
            if (document.querySelectorAll('button.reply-markup-button').length === 0) {
                document.querySelector('.autocomplete-peer-helper-list-element').click();
            }
            [...document.querySelectorAll('button.reply-markup-button')].filter(a => a.innerText.includes("Launch"))[0].click()
        JS
        );
        sleep(5);
        parent::runInTg($client);
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $data = urldecode($tg_data);
        parse_str($data, $data);
        $data = json_decode($data['user'], true);
        $id = $data['id'];

        $this->UCSet('tgData', $tg_data);
        $this->UCSet('tgId', $id);

        parent::saveUrl($client, $url);
    }

    public function getBalance()
    {
        if (!$this->getUrl()) {
            return;
        }
        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(10);
        $client->executeScript(<<<JS
            document.querySelector('#scroll-container button').click();
        JS);
        $client->waitForVisibility('span.title-xl');

        $balance = $client->executeScript(<<<JS
            return document.querySelector('span.title-xl').innerText;
        JS);
        $balance = (float)str_replace(',','', $balance);
        if ($balance) {
            $this->updateStatItem('balance', $balance);
        }
    }

    public function claimAndReset()
    {
        if ($this->UCGet('lock')) {
            return;
        }
        if (!$apiClient = $this->getClient()) {
            return;
        }
        $tgId = $this->UCGet('tgId');

        $resp = $apiClient->post('/farming/api/v1/user-rates/login', [
            'json' => [
                'userId' => $tgId
            ]
        ]);
        $user = json_decode($resp->getBody()->getContents(), true);
        $user = $user['data'];
        $this->updateStatItem('cps', $user['coinsInSecond']);

        $resp = $apiClient->get('/farming/api/v1/farming/' . $tgId . '/running');
        $status = json_decode($resp->getBody()->getContents(), true);
        $status = $status['data'][0];

        $lock = Carbon::createFromFormat('Y-m-d\\TH:i:s.vP', $status['timeEnd']);
        $secondsLeft = $lock->diff(null, true)->totalSeconds;
        if ($secondsLeft < 0) {
            $this->UCSet('lock', 1, abs($secondsLeft) + 1);
            $this->bus->dispatch(
                new CustomFunctionUser($this->curProfile, $this->getName(), 'claimAndReset'),
                [new DelayStamp((abs($secondsLeft) + 2) * 1000)]
            );
            $this->cache->hSet(
                $this->userKey('run'),
                'check',
                Carbon::now()->getTimestamp()
            );
        } else {
            $apiClient->post('farming/api/v1/farming/claim', [
                'json' => [
                    'userId' => $tgId,
                    'taskId' => $status['_id'],
                ]
            ]);
            sleep(2);
            $apiClient->post('farming/api/v1/farming/start', [
                'json' => [
                    'userId' => $tgId,
                    'taskId' => '1',
                ]
            ]);
            $delay = 8 * 60 * 60;
            $this->UCSet('lock', 1, $delay + 1);
            $this->bus->dispatch(
                new CustomFunctionUser($this->curProfile, $this->getName(), 'claimAndReset'),
                [new DelayStamp(($delay + 2) * 1000)]
            );
            $this->cache->hSet(
                $this->userKey('run'),
                'restart',
                Carbon::now()->getTimestamp()
            );
        }
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->getToken();

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://tonstation.app/',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }

    public function getToken()
    {
        $cached = $this->UCGet('token');
        if ($cached) {
            return $cached;
        }

        $tgData = $this->UCGet('tgData');
        if (!$tgData) {
            return null;
        }

        $authClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://tonstation.app/',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $authClient->post('userprofile/api/v1/users/auth', ['json' => ['initData' => $tgData]]);
        $auth = json_decode($resp->getBody()->getContents(), true);

        $this->UCSet('token', $auth['accessToken'], ($auth['lifetimeAccessToken'] / 1000) - 10);
        return $auth['accessToken'];
    }
}
