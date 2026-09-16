<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SelfAware\CapabilityInventory;
use App\Service\SelfAware\CapabilityReportRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Print the live capability block for a user. Admin debugging tool.
 */
#[AsCommand(
    name: 'app:selfaware:inventory',
    description: 'Print the live platform-capability prompt block for a user',
)]
final class SelfAwareInventoryCommand extends Command
{
    public function __construct(
        private readonly CapabilityInventory $inventory,
        private readonly CapabilityReportRenderer $renderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User id to build the report for', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getOption('user');
        $report = $this->inventory->build($userId);
        $io->writeln($this->renderer->render($report));
        $io->newLine();
        $io->writeln(sprintf(
            'facts=%d billing=%s admin=%s version=%s',
            count($report->facts),
            $report->billingEnabled ? 'on' : 'off',
            $report->isAdmin ? 'yes' : 'no',
            $report->version,
        ));

        return Command::SUCCESS;
    }
}
