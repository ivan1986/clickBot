<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Carbon\Carbon;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Panther\Client;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class MeerkatBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'meerkat_coin_bot'; }

    public function runInTg(Client $client)
    {
        $client->executeScript(<<<JS
            if (document.querySelectorAll('button.reply-markup-button').length === 0) {
                document.querySelector('.autocomplete-peer-helper-list-element').click();
            }
            [...document.querySelectorAll('button.reply-markup-button')].filter(a => a.innerText.includes("Launch Meerkat"))[0].click()
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
        $token = base64_encode($tg_data);

        $this->UCSet('token', $token);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('15 min', delta: 300)]
    public function claimAndReset()
    {
        if ($this->UCGet('lock')) {
            return;
        }
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('start?invited_by=');
        $status = json_decode($resp->getBody()->getContents(), true);
        $status = $status['data'];
        $this->updateStatItem('balance', $status['state']['balance']);

        // calculate our duration
        $duration = 0;
        $boostDuration = 0;
        foreach ($status['config']['fuelLevels'] as $k => $v) {
            if ($v['level'] == $status['state']['fuelLevel']) {
                $duration = $v['duration'];
                break;
            }
        }
        foreach ($status['config']['turboLevels'] as $k => $v) {
            if ($v['level'] == $status['state']['turboLevel']) {
                $boostDuration = $v['duration'];
                break;
            }
        }

        $lastBoost = null;
        if (!empty($status['boostLogs'])) {
            foreach ($status['boostLogs'] as $boostLog) {
                if ($boostLog['createdAt'] > $lastBoost) {
                    $lastBoost = $boostLog['createdAt'];
                }
            }
            if (count($status['boostLogs']) >=3) {
                $lastBoost = null;
            }
        } else {
            $lastBoost = time() - 1000000;
        }
        if ($lastBoost && $lastBoost + $boostDuration + 5 < time()) {
            $apiClient->post('user/state/turbo/activation');
            $this->runDelay('claimAndReset', $boostDuration + 10);
            $this->markRun('turbo');
            return true;
        }

        $nextClaim = $status['state']['lastClaimAt'] + $duration;
        if ($nextClaim + 5 < time()) {
            $apiClient->post('user/claim');
            $this->runDelay('claimAndReset', $duration + 10);
            return true;
        }
        if (!$lastBoost && $nextClaim > time() + 3600) {
            $this->UCSet('lock', 1, 3000);
        }
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://tma.timesoul.com/main-api/',
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
                'x-requested-with' => 'org.telegram.messenger'
            ]
        ]);
    }
}
