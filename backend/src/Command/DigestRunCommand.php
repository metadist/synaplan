<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Digest\MessageDigestRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Daily message-digest tick — wired into the scheduler role next to
 * app:updates:check (see _docker/backend/lib/container-runtime.sh).
 */
#[AsCommand(
    name: 'app:digest:run',
    description: 'Digest new user messages into the searchable deep-memory index (self-locking)'
)]
final class DigestRunCommand extends Command
{
    private const LOCK_TTL_SECONDS = 3600;

    public function __construct(
        private readonly MessageDigestRunner $runner,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Digest only this user id')
            ->addOption('max-batches', 'b', InputOption::VALUE_REQUIRED, 'Override the per-user batch cap for this run')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ask the model but store nothing and keep the cursor unchanged');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('message-digest-run', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->writeln('Another digest run is still in progress, skipping.');

            return Command::SUCCESS;
        }

        try {
            $userOption = $input->getOption('user');
            $maxBatchesOption = $input->getOption('max-batches');

            $summary = $this->runner->run(
                onlyUserId: is_numeric($userOption) ? (int) $userOption : null,
                dryRun: (bool) $input->getOption('dry-run'),
                maxBatchesPerUser: is_numeric($maxBatchesOption) ? max(1, (int) $maxBatchesOption) : null,
            );
        } finally {
            $lock->release();
        }

        $io->success(sprintf(
            'Digest run: %d users processed, %d skipped, %d batches, %d messages scanned, %d digests created.',
            $summary['users'],
            $summary['skipped_users'],
            $summary['batches'],
            $summary['scanned'],
            $summary['created'],
        ));

        return Command::SUCCESS;
    }
}
