<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Compute\ComputeRunReaper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Closes compute run rows the worker will never finish and purges finished
 * sidecar runs (CS28), so no run card hangs forever and no scratch lingers.
 *
 * Intended to be run as a cron job (e.g. every 5 minutes). The cluster-wide
 * lock means it is safe to schedule on every node — only one run executes
 * at a time. Exits immediately (idle, not broken) when compute is disabled.
 *
 *   *\/5 * * * * cd /path/to/synaplan && docker compose exec -T backend \
 *       php bin/console app:compute:reap-runs >> /var/log/synaplan-compute-reaper.log 2>&1
 */
#[AsCommand(
    name: 'app:compute:reap-runs',
    description: 'Close lost compute runs and purge finished sidecar runs'
)]
final class ComputeReapRunsCommand extends Command
{
    public function __construct(
        private readonly ComputeRunReaper $reaper,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('compute-run-reaper', 120);
        if (!$lock->acquire()) {
            $io->note('Previous reaper run is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->reaper->reap();
            if ($result['closed'] > 0 || $result['purged'] > 0) {
                $io->success(sprintf(
                    'Closed %d lost run(s), purged %d finished sidecar run(s), skipped %d.',
                    $result['closed'],
                    $result['purged'],
                    $result['skipped']
                ));
            } else {
                $io->writeln('No stale compute runs to reap.');
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
