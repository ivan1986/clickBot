<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Message\UpdateUrlUser;
use App\Service\ProfileService;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class BumsBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'bums_ton_bot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $authClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.bums.bot/',
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $authClient->post('miniapps/api/user/telegram_auth', [
            'multipart' => [
                [
                    'name' => 'initData',
                    'contents' => $tg_data,
                ],
            ]
        ]);
        $auth = json_decode($resp->getBody()->getContents(), true);
        $token = $auth['data']['token'];

        $this->UCSet('token', $token);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('3 hour', delta: 1800)]
    public function update()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        try {
            $resp = $apiClient->get('miniapps/api/user_game_level/getGameInfo');
        } catch (ClientException $e) {
            $this->logger->error('{bot} for {profile}: auth error', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
            ]);
            $this->runUpdate();
            return;
        }
        $info = json_decode($resp->getBody()->getContents(), true);
        $pph = $info['data']['mineInfo']['minePower'];
        $info = $info['data']['gameInfo'];

        $this->updateStatItem('coins', $info['coin']);
        $this->updateStatItem('profit', $pph);
        $this->updateStatItem('level', $info['level']);

        $resp = $apiClient->post('miniapps/api/mine/getMineLists');
        $list = json_decode($resp->getBody()->getContents(), true);
        $list = $list['data']['lists'];
        $list = array_filter($list, fn ($item) => $item['status'] > 0);
        $list = array_filter($list, fn ($item) => $item['nextLevelCost'] < $info['coin']);

        usort($list, fn ($a, $b) => $b['distance'] / $b['nextLevelCost'] <=> $a['distance'] / $a['nextLevelCost']);

        if (empty($list)) {
            return;
        }

        $list = current($list);
        $apiClient->post('miniapps/api/mine/upgrade', [
            'multipart' => [
                [
                    'name' => 'mineId',
                    'contents' => $list['mineId'],
                ],
            ]
        ]);
        $this->logger->info('{bot} for {profile}: update', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
        ]);
        $this->runDelay('update');
        return true;
    }

    #[ScheduleCallback('2 hour', delta: 1800)]
    public function spin()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }
        $resp = $apiClient->get('miniapps/api/game_slot/stamina');
        $info = json_decode($resp->getBody()->getContents(), true);
        $info = $info['data'];

        $this->updateStatItem('spins', $info['staminaNow']);
        $count = min($info['staminaNow'], 20);
        $this->logger->info('{bot} for {profile}: left {count} spins', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'count' => $info['staminaNow'],
        ]);
        if (!$count) {
            return;
        }
        while ($count--) {
            $reward = $this->oneSpin($apiClient);
            $this->logger->info('{bot} for {profile}: spin - {reward}', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
                'reward' => $reward,
            ]);
            sleep(random_int(2, 4));
        }
        return true;
    }

    protected function oneSpin($apiClient)
    {
        $resp = $apiClient->post('miniapps/api/game_slot/start', [
            'multipart' => [
                [
                    'name' => 'count',
                    'contents' => 1,
                ],
            ]
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $info = $info['data'];
        return $info['rewardLists']['rewardList'][0]['name'] ?? '';
    }

    #[ScheduleCallback('8 hour', delta: 7200)]
    public function bonus()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('miniapps/api/prop_shop/Lists', [
            'query' => [
                'showPages' => 'expedition',
                'page' => 1,
                'pageSize' => 10,
            ]
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $expeditions = $info['data'];
        foreach ($expeditions as $expedition) {
            if (empty($expedition['sellLists'])) {
                continue;
            }
            foreach ($expedition['sellLists'] as $sellList) {
                if ($sellList['oldAmount'] > 0 || $sellList['newAmount'] > 0) {
                    continue 2;
                }
            }
            if ($expedition['toDayUse']) {
                continue;
            }
            $apiClient->post('miniapps/api/user_prop/UseProp', [
                'multipart' => [
                    [
                        'name' => 'propId',
                        'contents' => $expedition['propId'],
                    ],
                ]
            ]);
            $this->logger->info('{bot} for {profile}: get free expedition', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
            ]);
            return true;
        }

        $resp = $apiClient->get('miniapps/api/prop_shop/Lists', [
            'query' => [
                'showPages' => 'spin',
                'page' => 1,
                'pageSize' => 10,
            ]
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $expeditions = $info['data'];
        foreach ($expeditions as $expedition) {
            if (empty($expedition['sellLists'])) {
                continue;
            }
            foreach ($expedition['sellLists'] as $sellList) {
                if ($sellList['oldAmount'] > 0 || $sellList['newAmount'] > 0) {
                    continue 2;
                }
            }
            if ($expedition['toDayUse']) {
                continue;
            }
            $apiClient->post('miniapps/api/game_spin/Start', [
                'multipart' => [
                    [
                        'name' => 'propId',
                        'contents' => $expedition['propId'],
                    ],
                    [
                        'name' => 'count',
                        'contents' => 1,
                    ],
                ]
            ]);
            $this->logger->info('{bot} for {profile}: get free box', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
            ]);
            return true;
        }

        return false;
    }

    #[ScheduleCallback('8 hour', delta: 7200)]
    public function daily()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('miniapps/api/sign/getSignLists');
        $info = json_decode($resp->getBody()->getContents(), true);
        $signStatus = $info['data']['signStatus'];

        if ($signStatus) {
            return;
        }
        $apiClient->post('miniapps/api/sign/sign');
        return true;
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            $this->runUpdate();
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://api.bums.bot/',
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
