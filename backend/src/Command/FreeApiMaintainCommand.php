<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FreeApi\FreeApiActivator;
use App\Service\FreeApi\FreeApiMaintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:freeapi:maintain',
    description: 'Scheduler job: end an expired or switched-off Synaplan Demo and deliver a pending key revoke'
)]
final class FreeApiMaintainCommand extends Command
{
    public function __construct(
        private readonly FreeApiActivator $activator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->activator->maintain();
        if (FreeApiMaintenance::LEFT_EXPIRED === $result->leftBecause) {
            $output->writeln('The Synaplan Demo has ended, so the previous AI settings were restored.');
        } elseif (FreeApiMaintenance::LEFT_DISABLED === $result->leftBecause) {
            $output->writeln('The Synaplan Demo module was switched off, so the previous AI settings were restored.');
        }
        if ($result->revokePending) {
            $output->writeln('The Synaplan Demo key could not be revoked yet; this is retried every hour until the key expires.');
        }

        return Command::SUCCESS;
    }
}
