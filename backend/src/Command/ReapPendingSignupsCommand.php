<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Iam\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Deletes sign-ups that chose a plan and never paid.
 *
 * In admission mode a plan sign-up starts as `pending_payment`; the Stripe
 * webhook activates it once the invoice is paid. An account still pending
 * after {@see self::PENDING_DAYS} days is an abandoned checkout or a fake
 * sign-up, and goes through the regular erasure path. An account with any
 * subscription record is left alone: money may be on its way. The rule is
 * checked again on the row locked inside the erasure transaction, so a
 * payment that lands while the run is going keeps its account.
 *
 * Runs in the scheduler's hourly lane. A no-op on installs that never create
 * pending accounts (open sign-up, no billing).
 */
#[AsCommand(
    name: 'app:signup:reap-pending',
    description: 'Delete sign-ups still waiting for their first payment after 7 days'
)]
final class ReapPendingSignupsCommand extends Command
{
    public const PENDING_DAYS = 7;
    public const PAGE_SIZE = 200;
    private const MAX_DELETIONS_PER_RUN = 200;
    private const CREATED_FORMATS = ['Y-m-d H:i:s', 'Y-m-d\\TH:i:s', 'YmdHis'];

    private const DELETED = 'deleted';
    private const KEPT = 'kept';
    private const FAILED = 'failed';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserDeletionService $userDeletionService,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the accounts that would be deleted, delete nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $lock = $this->lockFactory->createLock('pending-signup-reaper', 900);
        if (!$lock->acquire()) {
            $io->note('Previous run is still active. Skipping.');

            return Command::SUCCESS;
        }

        $counts = [self::DELETED => 0, self::KEPT => 0, self::FAILED => 0];
        try {
            $cutoffTs = time() - self::PENDING_DAYS * 86400;
            $cutoff = date('Y-m-d H:i:s', $cutoffTs);
            $this->sweep($cutoff, $cutoffTs, $dryRun, $counts);

            $this->logger->info('Pending sign-up reaper finished', [
                'dry_run' => $dryRun,
                'deleted' => $dryRun ? 0 : $counts[self::DELETED],
                'would_delete' => $dryRun ? $counts[self::DELETED] : 0,
                'kept' => $counts[self::KEPT],
                'failed' => $counts[self::FAILED],
                'cutoff' => $cutoff,
            ]);
            $io->writeln(sprintf(
                '%s %d pending sign-up(s) older than %d days; %d kept (subscription on record or unreadable sign-up date), %d failed.',
                $dryRun ? 'Would delete' : 'Deleted',
                $counts[self::DELETED],
                self::PENDING_DAYS,
                $counts[self::KEPT],
                $counts[self::FAILED],
            ));
        } finally {
            $lock->release();
        }

        return $counts[self::FAILED] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Pages by id past the accounts it keeps, so kept rows never hide later
     * ones; the per-run cap counts deletions only.
     *
     * @param array<string, int> $counts
     */
    private function sweep(string $cutoff, int $cutoffTs, bool $dryRun, array &$counts): void
    {
        $afterId = 0;
        while (!self::capReached($counts)) {
            $page = $this->userRepository->findPendingPaymentCreatedBefore($cutoff, $afterId, self::PAGE_SIZE);
            foreach ($page as $user) {
                $afterId = (int) $user->getId();
                $outcome = $this->reap($user, $cutoffTs, $dryRun);
                ++$counts[$outcome];

                if (self::FAILED === $outcome) {
                    // The failed attempt may leave removals queued in the unit
                    // of work, and a failed flush closes the entity manager.
                    // Start the next page from fresh rows, or stop until the
                    // next run.
                    $this->em->clear();
                    if (!$this->em->isOpen()) {
                        return;
                    }
                    continue 2;
                }
                if (self::capReached($counts)) {
                    return;
                }
            }
            if (count($page) < self::PAGE_SIZE) {
                return;
            }
        }
    }

    /**
     * @param array<string, int> $counts
     */
    private static function capReached(array $counts): bool
    {
        return $counts[self::DELETED] >= self::MAX_DELETIONS_PER_RUN;
    }

    private function reap(User $user, int $cutoffTs, bool $dryRun): string
    {
        if (!self::isReapable($user, $cutoffTs)) {
            return self::KEPT;
        }
        if ($dryRun) {
            return self::DELETED;
        }

        try {
            $deleted = $this->userDeletionService->deleteUserIf(
                $user,
                static fn (User $fresh): bool => self::isReapable($fresh, $cutoffTs),
            );

            return $deleted ? self::DELETED : self::KEPT;
        } catch (\Throwable $e) {
            $this->logger->warning('Pending sign-up reaper: deletion failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return self::FAILED;
        }
    }

    private static function isReapable(User $user, int $cutoffTs): bool
    {
        return $user->isPendingPayment()
            && [] === $user->getSubscriptionData()
            && self::createdBefore($user->getCreated(), $cutoffTs);
    }

    /**
     * BCREATED is a string written in more than one format over the years; the
     * query compares it as text. An account whose date does not parse stays.
     */
    private static function createdBefore(string $created, int $cutoffTs): bool
    {
        foreach (self::CREATED_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $created);
            if (false !== $parsed) {
                return $parsed->getTimestamp() < $cutoffTs;
            }
        }

        return false;
    }
}
