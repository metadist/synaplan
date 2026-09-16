<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Desktop\DesktopJobStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Requeues (or fails) desktop jobs whose lease expired because the paired
 * computer went to sleep, lost its network, or crashed mid-run. Without this a
 * leased job would sit forever and the web "waiting" card never resolves.
 *
 * A job under its attempt budget goes back to `queued` (the next check-in leases
 * it again); a job that exhausted its attempts is failed with `timeout`.
 *
 * Intended to run as a cron job (e.g. every minute). The cluster-wide lock makes
 * it safe to schedule on every node — only one run executes at a time.
 *
 *   * * * * * cd /path/to/synaplan && docker compose exec -T backend \
 *       php bin/console app:desktop:reap-jobs >> /var/log/synaplan-desktop-reaper.log 2>&1
 */
#[AsCommand(
    name: 'app:desktop:reap-jobs',
    description: 'Requeue or fail desktop jobs whose device lease expired'
)]
final class ReapDesktopJobsCommand extends Command
{
    public function __construct(
        private readonly DesktopJobStore $jobStore,
        private readonly DesktopAgentConfig $desktopAgentConfig,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Flag off means idle, not broken (C8): shipping this to main before any
        // device exists must be a no-op on every production install.
        if (!$this->desktopAgentConfig->isEnabled(null)) {
            $io->writeln('Desktop agent feature is disabled. Nothing to reap.');

            return Command::SUCCESS;
        }

        $lock = $this->lockFactory->createLock('desktop-job-reaper', 120);
        if (!$lock->acquire()) {
            $io->note('Previous desktop reaper run is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->jobStore->requeueExpiredLeases();
            $requeued = $result['requeued'];
            $failed = $result['failed'];

            if ($requeued > 0 || $failed > 0) {
                $io->success(sprintf('Requeued %d and failed %d expired desktop job(s).', $requeued, $failed));
            } else {
                $io->writeln('No expired desktop job leases to reap.');
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
