<?php

namespace App\Command;

use App\Service\BotSelector;
use Carbon\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(
    name: 'test:run',
    description: 'Run command for debug',
)]
class TestRunCommand extends Command
{
    #[Required] public BotSelector $botSelector;

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::REQUIRED, 'Profile')
            ->addArgument('bot', InputArgument::REQUIRED, 'Bot')
            ->addArgument('callback', InputArgument::REQUIRED, 'Function')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bot = $this->botSelector->getBot($input->getArgument('bot'));
        $bot->setProfile($input->getArgument('profile'));
        $callback = $input->getArgument('callback');
        $bot->$callback();

        return Command::SUCCESS;
    }
}
