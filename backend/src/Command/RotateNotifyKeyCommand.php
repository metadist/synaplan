<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PlatformLink\NotifyKeyStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:platform-links:rotate-notify-key',
    description: 'List a new cloud-notification key now and sign with it from the next hour',
)]
final class RotateNotifyKeyCommand extends Command
{
    public function __construct(
        private NotifyKeyStore $keys,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->keys->rotate();
        $output->writeln(sprintf('Notification keys listed: %d', count($this->keys->publicKeys())));

        return Command::SUCCESS;
    }
}
