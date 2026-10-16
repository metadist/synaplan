<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Compute\ComputeWorkspaceExpirer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Expires idle file-work workspaces (CS29): warns the owner once, then
 * deletes the sidecar folder after the grace period.
 *
 * Intended to be run as a cron job (e.g. daily). The cluster-wide lock means
 * it is safe to schedule on every node — only one run executes at a time.
 * Exits immediately (idle, not broken) when compute is disabled.
 *
 *   0 3 * * * cd /path/to/synaplan && docker compose exec -T backend \
 *       php bin/console app:compute:expire-workspaces >> /var/log/synaplan-compute-expiry.log 2>&1
 */
#[AsCommand(
    name: 'app:compute:expire-workspaces',
    description: 'Warn owners of idle workspaces, delete past-grace folders'
)]
final class ComputeExpireWorkspacesCommand extends Command
{
    public function __construct(
        private readonly ComputeWorkspaceExpirer $expirer,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('compute-workspace-expirer', 300);
        if (!$lock->acquire()) {
            $io->note('Previous expirer run is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->expirer->expire();
            if ($result['notified'] > 0 || $result['deleted'] > 0) {
                $io->success(sprintf(
                    'Notified %d owner(s), deleted %d workspace(s), skipped %d.',
                    $result['notified'],
                    $result['deleted'],
                    $result['skipped']
                ));
            } else {
                $io->writeln('No idle workspaces to expire.');
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
