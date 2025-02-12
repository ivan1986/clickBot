<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use Carbon\Carbon;

class ZooBot extends BaseBot implements BotInterface
{
    const HOST = 'https://api.zoo.team/';

    public function getTgBotName() { return 'zoo_story_bot'; }

    #[ScheduleCallback('1 hour', delta: 600, browser: true)]
    public function checkEat()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForVisibility('div.flyBtn', 60);

        $client->executeScript(<<<JS
            document.querySelector('.pointChest')?.click();
        JS);

        $needFood = $client->executeScript(<<<JS
            let text = document.querySelector('div#tokens').innerHTML
            return text.indexOf('Добыча остановлена') !== -1 || text.indexOf('Mining stopped') !== -1;
        JS);
        if ($needFood) {
            $client->executeScript(<<<JS
                const sleep = ms => new Promise(r => setTimeout(r, ms));
                var f1 = async function (tabs) {
                    document.querySelector('div#tokens').click();
                    await sleep(100);
                    document.querySelector('div.panelRed button').click();
                    await sleep(100);
                    document.querySelector('div.panelRed button span.coin25').click();
                    await sleep(100);
                };
                return await f1();
            JS);
            return true;
        }
    }

    #[ScheduleCallback('4 hour', delta: 600, browser: true)]
    public function daily()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForVisibility('div.flyBtn', 60);
        $client->executeScript(<<<JS
            [...document.querySelectorAll('div.flyBtn')].filter(a => a.innerText.includes("Задачи") || a.innerText.includes("Tasks"))[0].click();
        JS);

        sleep(1);
        $claimed = $client->executeScript(<<<JS
            return document.querySelector('div.dailyReward').classList.contains('grayscale');
        JS);
        if (!$claimed) {
            $client->executeScript(<<<JS
                const sleep = ms => new Promise(r => setTimeout(r, ms));
                var f1 = async function (tabs) {
                    document.querySelector('div.dailyReward').click();
                    await sleep(100);
                    document.querySelector('div.dailyRewardPopupBottomClaim button').click();
                    await sleep(100);
                };
                return await f1();
            JS);
            sleep(5);
        }

        $hasTask = $client->executeScript(<<<JS
            let item = [...document.querySelectorAll('.van-cell')].filter(d => d.innerText.includes('Загадка') || d.innerText.includes('Riddle'))[0];
            return item && !item.classList.contains('finished');
        JS);
        $hasRebus = $client->executeScript(<<<JS
            let item = [...document.querySelectorAll('.van-cell')].filter(d => d.innerText.includes('Ребус') || d.innerText.includes('Rebus'))[0];
            return item && !item.classList.contains('finished');
        JS);
        $today = Carbon::today()->format('Y-m-d');

        $rebus = $this->botKey('rebus-'.$today);
        $rebusAns = $this->cache->get($rebus);
        if ($rebusAns && $hasRebus) {
            $this->inputAns($client, 'Ребус', 'Rebus', $rebusAns);
            sleep(5);
        }

        $task = $this->botKey('task-'.$today);
        $taskAns = $this->cache->get($task);
        if ($taskAns && $hasTask) {
            $this->inputAns($client, 'Загадка', 'Riddle', $taskAns);
            sleep(5);
        }
    }

    private function inputAns($client, $name, $nameEn, $ans)
    {
        $client->executeScript(<<<JS
            const name = arguments[0];
            const nameEn = arguments[1];
            const param = arguments[2];
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            var f1 = async function (tabs) {
                let item = [...document.querySelectorAll('.van-cell')].filter(d => d.innerText.includes(name) || d.innerText.includes(nameEn))[0];
                item.click();
                await sleep(2000);
                document.querySelector('.van-popup input').value = param;
                await sleep(2000);
                document.querySelector('.van-popup input').dispatchEvent(new Event('input', { bubbles: true }));
                await sleep(2000);
                document.querySelector('.van-popup button').click();
                await sleep(2000);
                document.querySelector('.van-popup button').click()
            };
            return await f1();
        JS, [$name, $nameEn, $ans]);
    }
}
