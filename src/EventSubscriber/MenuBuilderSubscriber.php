<?php

namespace App\EventSubscriber;

use App\Service\BotSelector;
use App\Service\ProfileService;
use KevinPapst\TablerBundle\Event\MenuEvent;
use KevinPapst\TablerBundle\Model\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\Attribute\Required;

class MenuBuilderSubscriber implements EventSubscriberInterface
{
    #[Required] public ProfileService $profileService;
    #[Required] public BotSelector $botSelector;

    public static function getSubscribedEvents(): array
    {
        return [
            MenuEvent::class => ['onSetupNavbar', 100],
        ];
    }

    public function onSetupNavbar(MenuEvent $event): void
    {
        $event->addItem(
            new MenuItemModel('homepage', '', 'home', [], 'fas fa-home')
        );

        $profiles = new MenuItemModel('profiles', 'Profiles', null, [], 'fas fa-users');
        foreach ($this->profileService->list() as $profile) {
            $profiles->addChild(
                new MenuItemModel('profile-' . $profile, $profile, 'userIndex', ['profile' => $profile], '')
            );
        }
        $event->addItem($profiles);

        $bots = new MenuItemModel('bots', 'Bots', null, [], 'fas fa-robot');
        foreach ($this->botSelector->getNames() as $bot) {
            $bots->addChild(
                new MenuItemModel('bot-' . $bot, $bot, 'botIndex', ['bot' => $bot], '')
            );
        }
        $event->addItem($bots);

        $admin = new MenuItemModel('admin', 'Admin', null, [], 'fas fa-cogs');
        $admin->addChild(new MenuItemModel('admin-setup-proxy', 'Setup Proxy', 'admin-setup-proxy', [], ''));
        $admin->addChild(new MenuItemModel('admin-init-urls', 'Init URLs', 'admin-init-urls', [], ''));
        $admin->addChild(new MenuItemModel('admin-clear-profile-cache', 'Clear profile cache', 'admin-clear-profile-cache', [], ''));
        $event->addItem($admin);
    }
}
