<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FileRepository;
use App\Service\File\FileStorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Deletes ephemeral files (created during incognito chat sessions) that
 * outlived their session — DB row AND on-disk bytes.
 *
 * The frontend already deletes ephemeral files when an incognito session
 * ends; this command is the safety net for sessions that could not clean up
 * (tab crash, network loss, killed browser). Anything older than the TTL is
 * definitively orphaned.
 *
 * Intended to be run as a cron job (e.g. hourly). The cluster-wide lock means
 * it is safe to schedule on every node — only one run executes at a time.
 *
 *   0 * * * * cd /path/to/synaplan && docker compose exec -T backend \
 *       php bin/console app:files:reap-ephemeral >> /var/log/synaplan-ephemeral-reaper.log 2>&1
 */
#[AsCommand(
    name: 'app:files:reap-ephemeral',
    description: 'Delete expired ephemeral (incognito-session) files from disk and database'
)]
final class ReapEphemeralFilesCommand extends Command
{
    private const DEFAULT_TTL_HOURS = 24;

    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly FileStorageService $fileStorageService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'ttl-hours',
            null,
            InputOption::VALUE_REQUIRED,
            'Minimum age in hours before an ephemeral file is reaped',
            (string) self::DEFAULT_TTL_HOURS
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ttlHours = max(1, (int) $input->getOption('ttl-hours'));
        $cutoff = time() - $ttlHours * 3600;

        $lock = $this->lockFactory->createLock('ephemeral-file-reaper', 300);
        if (!$lock->acquire()) {
            $io->note('Previous reaper run is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $expired = $this->fileRepository->findExpiredEphemeral($cutoff);
            $reaped = 0;

            foreach ($expired as $file) {
                try {
                    if ('' !== $file->getFilePath()) {
                        $this->fileStorageService->deleteFile($file->getFilePath());
                    }
                    $this->fileRepository->delete($file);
                    ++$reaped;
                } catch (\Throwable $e) {
                    // One broken row must not stop the sweep — log and continue.
                    $this->logger->warning('Ephemeral reaper: failed to delete file', [
                        'file_id' => $file->getId(),
                        'path' => $file->getFilePath(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($reaped > 0) {
                $io->success(sprintf('Deleted %d expired ephemeral file(s) (older than %dh).', $reaped, $ttlHours));
            } else {
                $io->writeln('No expired ephemeral files to reap.');
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
