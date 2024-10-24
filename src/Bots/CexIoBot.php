<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Carbon\Carbon;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Panther\Client;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class CexIoBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'cexio_tap_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName()))->withJitter(7200));
        $schedule->add(RecurringMessage::every('6 hour', new CustomFunction($this->getName(), 'claimAndExchange')));
        $schedule->add(RecurringMessage::every('4 hour', new CustomFunction($this->getName(), 'upgrade')));
    }

    public function runInTg(Client $client)
    {
        $client->executeScript(<<<JS
            if (document.querySelectorAll('button.reply-markup-button').length === 0) {
                document.querySelector('.autocomplete-peer-helper-list-element').click();
            }
            [...document.querySelectorAll('button.reply-markup-button')].filter(a => a.innerText.includes("Запустить приложение"))[0].click()
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

        $hash = $data['hash'];
        $data = json_decode($data['user'], true);
        $id = $data['id'];

        $this->UCSet('tgData', $tg_data);
        $this->UCSet('tgId', $id);
        $this->UCSet('tgHash', $hash);

        parent::saveUrl($client, $url);
    }

    public function claimAndExchange()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('getUserInfo');
        $user = json_decode($resp->getBody()->getContents(), true);
        $user = $user['data'];
        $this->updateStatItem('USD', $user['balance_USD']);
        $this->updateStatItem('mBTC', $user['balance_BTC']);
        $this->updateStatItem('CEXP', $user['balance_CEXP']);

        $apiClient->post('claimCrypto');

        $resp = $apiClient->post('getConvertData');
        $convert = json_decode($resp->getBody()->getContents(), true);
        $convert = $convert['convertData']['lastPrices'];
        $convert = $convert[count($convert) - 1];

        if ($user['balance_BTC'] < 1000) {
            return;
        }
        $fromAmount = $user['balance_BTC'] * 0.9 / 100000;

        $resp = $apiClient->post('convert', [
            'json' => $this->getJsonData() + [
                'data' => [
                    'fromAmount' => $fromAmount,
                    'fromCcy' => 'BTC',
                    'price' => $convert,
                    'toCcy' => 'USD',
                ]
            ]
        ]);
        $convert = json_decode($resp->getBody()->getContents(), true);

        $resp = $apiClient->post('getUserInfo');
        $user = json_decode($resp->getBody()->getContents(), true);
        $user = $user['data'];
        $this->updateStatItem('USD', $user['balance_USD']);
        $this->updateStatItem('mBTC', $user['balance_BTC']);
        $this->updateStatItem('CEXP', $user['balance_CEXP']);

        $this->cache->hSet(
            $this->userKey('run'),
            'realExchange',
            Carbon::now()->getTimestamp()
        );
    }

    public function upgrade()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('getUserInfo');
        $user = json_decode($resp->getBody()->getContents(), true);
        $balance = $user['data']['balance_USD'];

        $resp = $apiClient->post('getUserCards');
        $cards = json_decode($resp->getBody()->getContents(), true);
        $userCards = $cards['cards'];

        $resp = $apiClient->post('getGameConfig');
        $config = json_decode($resp->getBody()->getContents(), true);
        $config = $config['upgradeCardsConfig'];

        $cards = [];
        foreach ($config as $group) {
            foreach ($group['upgrades'] as $card) {
                $card['categoryId'] = $group['categoryId'];
                $cards[] = $card;
            }
        }

        foreach ($cards as &$card) {
            $nextLevel = $userCards[$card['upgradeId']]['lvl'] ?? 0;
            if (empty($card['levels'][$nextLevel])) {
                continue;
            }
            $card['level'] = $card['levels'][$nextLevel];
            $card['nextLevel'] = $nextLevel + 1;
            unset($card['levels']);
        }
        $cards = array_filter($cards, function ($card) use ($userCards) {
            if ($card['dependency']) {
                if (empty($userCards[$card['dependency']['upgradeId']])) {
                    return false;
                }
                if ($userCards[$card['dependency']['upgradeId']]['lvl'] < $card['dependency']['level']) {
                    return false;
                }
            }
            return true;
        });
        $cards = array_filter($cards, function ($card) use ($balance) {
            if (empty($card['level']) || !$card['level'][0]) {
                return false;
            }
            return $balance >= $card['level'][0];
        });
        usort($cards, function ($a, $b) {
            return $b['level'][2] / $b['level'][0] <=> $a['level'][2] / $a['level'][0];
        });

        if (empty($cards)) {
            return;
        }
        $cards = current($cards);

        $resp = $apiClient->post('buyUpgrade', [
            'json' => $this->getJsonData() + [
                'data' => [
                    'categoryId' => $cards['categoryId'],
                    'upgradeId' => $cards['upgradeId'],
                    'ccy' => 'USD',
                    'cost' => $cards['level'][0],
                    'effect' => $cards['level'][2],
                    'effectCcy' => 'CEXP',
                    'nextLevel' => $cards['nextLevel'],
                ]
            ]
        ]);

        $this->cache->hSet(
            $this->userKey('run'),
            'realUpgrade',
            Carbon::now()->getTimestamp()
        );
        $this->bus->dispatch(
            new CustomFunctionUser($this->curProfile, $this->getName(), 'upgrade'),
            [new DelayStamp(10 * 1000)]
        );
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $hash = $this->UCGet('tgHash');

        if (!$hash) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://app.cexptap.com/api/v2/',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
                'x-request-userhash' => $hash,
                'x-appl-version' => '0.19.0',
                'x-requested-with' => 'org.telegram.messenger',
            ],
            'json' => $this->getJsonData()
        ]);
    }

    protected function getJsonData()
    {
        $token = $this->UCGet('tgData');
        $tgId = $this->UCGet('tgId');

        if (!$token) {
            return [];
        }

        return [
            'authData' => $token,
            'devAuthData' => $tgId,
            'platform' => 'android',
        ];
    }
}
