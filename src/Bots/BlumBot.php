<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Service\ProfileService;

class BlumBot extends BaseBot implements BotInterface
{
    const ANSWERS = 'https://raw.githubusercontent.com/boytegar/BlumBOT/master/verif.json';

    public function getTgBotName() { return 'BlumCryptoBot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tgData = $urlData['tgWebAppData'];
        $this->UCSet('tgData', $tgData);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('4 hour', delta: 1800)]
    public function farming()
    {
        if (!$apiClient = $this->getClient('game-domain')) {
            return false;
        }

        try {
            $apiClient->get('daily-reward?offset=-180');
            echo 1;
        } catch (\Exception $e) {}

        $resp = $apiClient->get('user/balance');
        $balance = json_decode($resp->getBody()->getContents(), true);
        $this->updateStatItem('balance', $balance['availableBalance']);

        if (empty($balance['farming'])) {
            $apiClient->post('farming/start');
            return true;
        }
        if ($balance['farming']['endTime'] < $balance['timestamp']) {
            $apiClient->post('farming/claim');
            sleep(random_int(2,4));
            $apiClient->post('farming/start');
            return true;
        }
        return false;
    }

    #[ScheduleCallback('4 hour', delta: 1800)]
    public function tasks()
    {
        if (!$apiClient = $this->getClient('earn-domain')) {
            return false;
        }

        $resp = $apiClient->get('tasks');
        $tasks = json_decode($resp->getBody()->getContents(), true);

        try {
            foreach ($tasks as $section) {
                foreach ($section['tasks'] as $task) {
                    $this->processTask($task, $apiClient);
                }
                foreach ($section['subSections'] as $tasks) {
                    foreach ($tasks['tasks'] as $task) {
                        $this->processTask($task, $apiClient);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->info($this->getName() . ' for ' . $this->curProfile . ' task: {msg}', ['msg' => $e->getMessage()]);
            return true;
        }
        return false;
    }

    protected function processTask($task, $apiClient)
    {
        if (!empty($task['subTasks'])) {
            foreach ($task['subTasks'] as $stask) {
                $this->processTask($stask, $apiClient);
            }
            return;
        }

        $types = ['WALLET_CONNECTION', 'ONCHAIN_TRANSACTION', 'PROGRESS_TARGET'];
        if (in_array($task['type'], $types)) {
            return;
        }
        if ($task['status'] == 'FINISHED') {
            return;
        }
        if ($task['status'] == 'NOT_STARTED') {
            $apiClient->post('tasks/' . $task['id'] . '/start');
            throw new \Exception('start :' . $task['title']);
        }
        if ($task['status'] == 'READY_FOR_CLAIM') {
            $apiClient->post('tasks/' . $task['id'] . '/claim');
            throw new \Exception('claim :' . $task['title']);
        }
        if ($task['status'] == 'READY_FOR_VERIFY' && $task['validationType'] == 'KEYWORD') {
            $ans = $this->getAnswers();
            if (empty($ans[$task['id']])) {
                $this->logger->error(
                    $this->getName() . ' for ' . $this->curProfile . ' no answer for task: {title}',
                    ['title' => $task['title']]
                );
                return;
            }
            $keyword = $ans[$task['id']];
            $apiClient->post('tasks/' . $task['id'] . '/validate', ['json' => ['keyword' => $keyword]]);
            throw new \Exception('validate :' . $task['title']);
        }

        echo $task['status'] . PHP_EOL;
    }

    protected function getAnswers()
    {
        $ans = $this->cache->get($this->botKey('ans'));
        if ($ans) {
            return json_decode($ans, true);
        }

        $ghClient = new \GuzzleHttp\Client([
            'base_uri' => self::ANSWERS,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $ghClient->get(self::ANSWERS);
        $ans = $resp->getBody()->getContents();

        $this->cache->setEx($this->botKey('ans'), 3600 * 24, $ans);
        return json_decode($ans, true);
    }

    protected function getAccessToken($tg_data)
    {
        $authClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://user-domain.blum.codes/api/v1/',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $authClient->post('auth/provider/PROVIDER_TELEGRAM_MINI_APP', [
            'json' => [
                'query' => $tg_data,
            ]
        ]);
        $auth = json_decode($resp->getBody()->getContents(), true);
        return $auth['token']['access'];
    }

    protected function getClient($subDomain): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            $tgData = $this->UCGet('tgData');
            $token = $this->getAccessToken($tgData);
            $this->UCSet('token', $token, 1800);
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://' . $subDomain . '.blum.codes/api/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}