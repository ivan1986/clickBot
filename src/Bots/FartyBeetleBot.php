<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class FartyBeetleBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'fart_beetle_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName()))->withJitter(7200));
        $schedule->add(RecurringMessage::every('2 hour', new CustomFunction($this->getName(), 'craft')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $this->UCSet('tgData', $tg_data);

        parent::saveUrl($client, $url);
    }

    public function craft()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('api/boot/', [
            'json' => [
                'timezone' => 'Europe/Moscow',
            ]
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $this->updateStat($info['burgers_count']);

        if (count($info['completed_tasks']) >= 40) {
            return;
        }

        $resp = $apiClient->get('api/factory/', [
            'query' => [
                'locale' => 'ru',
            ]
        ]);
        $factory = json_decode($resp->getBody()->getContents(), true);

        $completed = array_map(fn($item) => $item['factory_id'], $info['completed_tasks']);
        $factory = array_filter($factory, fn($item) => !in_array($item['id'], $completed));

        $newTasks = array_values($factory);
        shuffle($newTasks);
        $newTasks = array_slice($newTasks, 0, 20);
        foreach ($newTasks as $task) {
            sleep(rand(9,15));
            $resp = $apiClient->post('api/factory/' . $task['id'] . '/clock', [
                'json' => $task['current_task']
            ]);
            $taskDone = json_decode($resp->getBody()->getContents(), true);
            if (empty($taskDone[0])) {
                return;
            }
        }
    }

    protected function updateStat($balance)
    {
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'balance',
            'Balance',
            ['user']
        );
        $gauge->set($balance, [$this->curProfile]);
        $this->cache->hSet($this->userKey('status'), 'balance', $balance);
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('tgData');

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://factory.fireheadz.games',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
