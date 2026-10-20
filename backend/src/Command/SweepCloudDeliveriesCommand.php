<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PlatformLink\CloudDeliveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:cloud-deliveries:sweep', description: 'Fail copies the cloud never confirmed')]
final class SweepCloudDeliveriesCommand extends Command
{
    public function __construct(
        private CloudDeliveryService $deliveries,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Unconfirmed copies: %d', $this->deliveries->sweep()));

        return Command::SUCCESS;
    }
}
