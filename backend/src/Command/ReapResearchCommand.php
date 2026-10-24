<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Research\Run\ResearchReaper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Recovers a research run whose worker disappeared, and closes one that
 * has been waiting too long. Safe on every scheduler tick — the lock means
 * only one node runs it.
 */
#[AsCommand(
    name: 'app:research:reap',
    description: 'Recover a stuck research run and close one that waited too long',
)]
final class ReapResearchCommand extends Command
{
    public function __construct(
        private readonly ResearchReaper $reaper,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('research-reaper', 600);
        if (!$lock->acquire()) {
            $io->note('A research recovery is already running. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $reaped = $this->reaper->reap();
            $total = $reaped['redispatched'] + $reaped['interrupted'] + $reaped['continued'] + $reaped['stopped'];
            if ($total > 0) {
                $io->success(sprintf('Recovered %d research run(s).', $total));
            } else {
                $io->writeln('No stuck research to recover.');
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
