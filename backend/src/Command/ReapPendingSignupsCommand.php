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
 * subscription record is left alone: money may be on its way.
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
    private const MAX_PER_RUN = 200;
    private const CREATED_FORMATS = ['Y-m-d H:i:s', 'Y-m-d\\TH:i:s', 'YmdHis'];

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

        $reaped = 0;
        $kept = 0;
        $failed = 0;
        try {
            $cutoffTs = time() - self::PENDING_DAYS * 86400;
            $cutoff = date('Y-m-d H:i:s', $cutoffTs);
            $candidates = $this->userRepository->findPendingPaymentCreatedBefore($cutoff, self::MAX_PER_RUN);

            foreach ($candidates as $user) {
                if ([] !== $user->getSubscriptionData() || !self::createdBefore($user, $cutoffTs)) {
                    ++$kept;
                    continue;
                }
                if ($dryRun) {
                    ++$reaped;
                    continue;
                }
                if ($this->delete($user)) {
                    ++$reaped;
                    continue;
                }
                ++$failed;
                // A failed flush closes the entity manager, so every later
                // deletion in this run would fail too. The next run retries.
                if (!$this->em->isOpen()) {
                    break;
                }
            }

            $this->logger->info('Pending sign-up reaper finished', [
                'dry_run' => $dryRun,
                'deleted' => $dryRun ? 0 : $reaped,
                'would_delete' => $dryRun ? $reaped : 0,
                'kept' => $kept,
                'failed' => $failed,
                'cutoff' => $cutoff,
            ]);
            $io->writeln(sprintf(
                '%s %d pending sign-up(s) older than %d days; %d kept (subscription on record or unreadable sign-up date), %d failed.',
                $dryRun ? 'Would delete' : 'Deleted',
                $reaped,
                self::PENDING_DAYS,
                $kept,
                $failed,
            ));
        } finally {
            $lock->release();
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * BCREATED is a string written in more than one format over the years; the
     * query compares it as text. An account whose date does not parse stays.
     */
    private static function createdBefore(User $user, int $cutoffTs): bool
    {
        foreach (self::CREATED_FORMATS as $format) {
            $created = \DateTimeImmutable::createFromFormat('!'.$format, $user->getCreated());
            if (false !== $created) {
                return $created->getTimestamp() < $cutoffTs;
            }
        }

        return false;
    }

    private function delete(User $user): bool
    {
        try {
            $this->userDeletionService->deleteUser($user);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Pending sign-up reaper: deletion failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
