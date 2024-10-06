<?php

namespace App\MessageHandler;

use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Message\UpdateUrlUser;
use App\Service\BotSelector;
use App\Service\CacheService;
use App\Service\ProfileService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;

class ProfileSender
{
    #[Required] public ProfileService $profileService;
    #[Required] public BotSelector $botSelector;
    #[Required] public CacheService $cache;
    #[Required] public MessageBusInterface $bus;

    #[AsMessageHandler]
    public function urlHandler(UpdateUrl $message)
    {
        foreach($this->profiles($message->name) as $profile) {
            $this->bus->dispatch(new UpdateUrlUser(
                $profile,
                $message->name,
                $message->debug
            ));
        }
    }

    #[AsMessageHandler]
    public function customHandler(CustomFunction $message)
    {
        foreach($this->profiles($message->name) as $profile) {
            $this->bus->dispatch(new CustomFunctionUser(
                $profile,
                $message->name,
                $message->callback
            ));
        }
    }

    protected function profiles($bot)
    {
        $profiles = [];
        foreach ($this->profileService->list() as $profile) {
            if ($this->botSelector->isEnabled($profile, $bot)) {
                $profiles[] = $profile;
            }
        }
        return $profiles;
    }
}