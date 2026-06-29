<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Media\MediaJobReaper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Times out media jobs whose render worker died mid-flight (stale heartbeat) or
 * which blew past their deadline, so no card hangs forever.
 *
 * Intended to be run as a cron job (e.g. every minute). The cluster-wide lock
 * means it is safe to schedule on every node — only one run executes at a time.
 *
 *   * * * * * cd /path/to/synaplan && docker compose exec -T backend \
 *       php bin/console app:media:reap-jobs >> /var/log/synaplan-media-reaper.log 2>&1
 */
#[AsCommand(
    name: 'app:media:reap-jobs',
    description: 'Time out stale or past-deadline async media jobs (heartbeat backstop)'
)]
final class ReapMediaJobsCommand extends Command
{
    public function __construct(
        private readonly MediaJobReaper $reaper,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('media-job-reaper', 120);
        if (!$lock->acquire()) {
            $io->note('Previous reaper run is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $reaped = $this->reaper->reap();
            if ($reaped > 0) {
                $io->success(sprintf('Timed out %d stale media job(s).', $reaped));
            } else {
                $io->writeln('No stale media jobs to reap.');
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
