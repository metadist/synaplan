<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AuditLogEntryRepository;
use App\Service\Iam\IamConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Deletes BAUDITLOG rows older than IAM.AUDIT_RETENTION_DAYS (default 365).
 * 0 keeps everything. Safe to schedule on every node — one lock at a time.
 *
 *   15 3 * * * cd /path/to/synaplan && docker compose exec -T backend \
 *       php bin/console app:iam:reap-audit >> /var/log/synaplan-audit-reaper.log 2>&1
 */
#[AsCommand(
    name: 'app:iam:reap-audit',
    description: 'Delete IAM audit rows older than the configured retention'
)]
final class ReapAuditLogCommand extends Command
{
    public function __construct(
        private readonly IamConfig $iamConfig,
        private readonly AuditLogEntryRepository $auditLogEntryRepository,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $this->iamConfig->auditRetentionDays(null);
        if ($days < 1) {
            $io->writeln('Audit retention is 0 (keep forever). Nothing to reap.');

            return Command::SUCCESS;
        }

        $lock = $this->lockFactory->createLock('iam-audit-reaper', 120);
        if (!$lock->acquire()) {
            $io->note('Previous audit reaper run is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $cutoff = time() - ($days * 86400);
            $deleted = $this->auditLogEntryRepository->deleteOlderThan($cutoff);
            if ($deleted > 0) {
                $io->success(sprintf('Deleted %d audit row(s) older than %d day(s).', $deleted, $days));
            } else {
                $io->writeln('No expired audit rows to reap.');
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
