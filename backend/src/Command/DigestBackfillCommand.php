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
 * One-shot historical backfill of the message digest index.
 *
 * Unlike app:digest:run this never advances the per-user cursor — it walks
 * the requested window from the beginning and relies on the one-digest-per-
 * message unique key for idempotency, so it can safely revisit ranges the
 * daily job already scanned. Run it per user (or --all-users) after enabling
 * the feature on an install with existing history.
 */
#[AsCommand(
    name: 'app:digest:backfill',
    description: 'Backfill the message digest index over historical messages (LIVE model calls)'
)]
final class DigestBackfillCommand extends Command
{
    private const LOCK_TTL_SECONDS = 7200;
    private const DEFAULT_SINCE_DAYS = 90;
    private const DEFAULT_MAX_BATCHES = 20;

    public function __construct(
        private readonly MessageDigestRunner $runner,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Backfill only this user id')
            ->addOption('all-users', null, InputOption::VALUE_NONE, 'Backfill every user (required when --user is omitted)')
            ->addOption('since-days', 's', InputOption::VALUE_REQUIRED, 'How far back to look, in days', (string) self::DEFAULT_SINCE_DAYS)
            ->addOption('max-batches', 'b', InputOption::VALUE_REQUIRED, 'Max model calls per user', (string) self::DEFAULT_MAX_BATCHES)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ask the model but store nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userOption = $input->getOption('user');
        $onlyUserId = is_numeric($userOption) ? (int) $userOption : null;

        if (null === $onlyUserId && !$input->getOption('all-users')) {
            $io->error('Refusing to backfill every user implicitly — pass --user=<id> or the explicit --all-users flag (this makes live model calls per user).');

            return Command::FAILURE;
        }

        $sinceDays = max(1, (int) $input->getOption('since-days'));
        $maxBatches = max(1, (int) $input->getOption('max-batches'));

        $lock = $this->lockFactory->createLock('message-digest-backfill', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->writeln('Another digest backfill is still in progress, skipping.');

            return Command::SUCCESS;
        }

        try {
            $summary = $this->runner->backfill(
                onlyUserId: $onlyUserId,
                sinceUnix: time() - ($sinceDays * 86400),
                dryRun: (bool) $input->getOption('dry-run'),
                maxBatchesPerUser: $maxBatches,
            );
        } finally {
            $lock->release();
        }

        $io->success(sprintf(
            'Digest backfill (%dd window): %d users processed, %d skipped, %d batches, %d messages scanned, %d digests created.',
            $sinceDays,
            $summary['users'],
            $summary['skipped_users'],
            $summary['batches'],
            $summary['scanned'],
            $summary['created'],
        ));

        return Command::SUCCESS;
    }
}
