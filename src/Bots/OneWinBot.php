<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ClientFactory;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class OneWinBot extends BaseBot implements BotInterface
{
    #[Required] public ClientFactory $clientFactory;

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName(), '/k/#@token1win_bot'))->withJitter(7200));
        $schedule->add(RecurringMessage::every('1 hour', new CustomFunction($this->getName(), 'passiveIncome')));
        $schedule->add(RecurringMessage::every('6 hour', new CustomFunction($this->getName(), 'dailyIncome')));
        $schedule->add(RecurringMessage::every('3 hour', new CustomFunction($this->getName(), 'update')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $client->request('GET', $url);
        $client->waitForElementToContain('#root', 'Не забудь собрать ежедневную награду');
        $client->request('GET', 'https://cryptocklicker-frontend-rnd-prod.100hp.app/' . 'earnings');
        $client->waitForElementToContain('#root', 'Ежедневные');
        $token = $client->executeScript('return window.localStorage.getItem("token");');
        $userId = $client->executeScript('return window.localStorage.getItem("tgId");');

        $item = $this->cache->getItem($this->getName() . ':token');
        $item->set($token);
        $this->cache->save($item);

        $item = $this->cache->getItem($this->getName() . ':userId');
        $item->set($userId);
        $this->cache->save($item);

        parent::saveUrl($client, $url);
    }

    public function passiveIncome()
    {
        $client = $this->clientFactory->getOrCreateBrowser();
        $client->request('GET', $this->getUrl());
        $client->waitForElementToContain('#root', 'Не забудь собрать ежедневную награду');
        sleep(10);
    }

    public function dailyIncome()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('/tasks/everydayreward');
        $exist = json_decode($resp->getBody()->getContents(), true);
        $toCollect = null;
        foreach ($exist['days'] as $k => $v) {
            if ($v['isCollected'] === false) {
                $toCollect = $v['id'];
                break;
            }
        }
        if (!$toCollect) {
            return;
        }
        $apiClient->post('/tasks/everydayreward');
    }

    public function update()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('/game/config?lang=ru');
        $config = json_decode($resp->getBody()->getContents(), true);
        $profit = $config['PassiveProfit'];

        $resp = $apiClient->get('/user/balance');
        $balance = json_decode($resp->getBody()->getContents(), true);
        $coinsBalance = $balance['coinsBalance'];
        $miningPerHour = $balance['miningPerHour'];

        $resp = $apiClient->get('/minings');
        $exist = json_decode($resp->getBody()->getContents(), true);
        foreach ($exist as $k => $v) {
            preg_match('#(\D+)(\d+)#', $v['id'], $matches);
            $exist[$matches[1]] = $matches[2];
        }

        // оставляем только следующий номер для каждого инструмента
        $profit = array_filter($profit, function ($i) use ($exist) {
            preg_match('#(\D+)(\d+)#', $i['id'], $matches);
            if (isset($exist[$matches[1]])) {
                return $matches[2] == $exist[$matches[1]] + 1;
            }
            return $matches[2] == 1;
        });
        // оставляем только те у которых есть услови других зданий
        $profit = array_filter($profit, function ($i) use ($exist) {
            if (isset($i['required'][0]['newReferralCount'])) {
                return false;
            }
            if (!isset($i['required'][0]['PassiveProfit'])) {
                return true;
            }
            preg_match('#(\D+)(\d+)#', $i['required'][0]['PassiveProfit'], $matches);
            if (isset($exist[$matches[1]])) {
                return $matches[2] <= $exist[$matches[1]];
            }
            return false;
        });
        $profit = array_filter($profit, function ($i) use ($coinsBalance) {
            return $i['cost'] <= $coinsBalance;
        });
        usort($profit, fn ($a, $b) => $b['profit'] / $b['cost'] <=> $a['profit'] / $a['cost']);

        if (empty($profit)) {
            return;
        }
        $profit = current($profit);
        $apiClient->post('/minings', [
            'json' => ['id' => $profit['id']]
        ]);
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->cache->getItem($this->getName() . ':token')->get();
        $userId = $this->cache->getItem($this->getName() . ':userId')->get();

        if (!$token || !$userId) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://crypto-clicker-backend-go-prod.100hp.app/',
            'headers' => [
                'X-User-Id' => $userId,
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ClientFactory::UA,
            ]
        ]);
    }
}
