<?php

namespace App\Bots;

use App\Message\UpdateUrl;
use App\Model\ActionStatusDto;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Panther\Client;
use Symfony\Component\Scheduler\Schedule;

#[AutoconfigureTag('bot')]
interface BotInterface
{
    public function getName();
    public function getProxy();

    public function addSchedule(Schedule $schedule): void;
    public function setProfile(string $profile);

    public function getTgBotName();
    public function runInTg(Client $client);
    public function saveUrl($client, $url);
    public function getUrl();

    /**
     * @return ActionStatusDto[]
     */
    public function getActions();

    public function UCSet($key, $value);
    public function UCGet($key);
}
