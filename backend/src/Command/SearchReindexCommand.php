<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\SmartSearch\Index\SearchIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Rebuild the Smart Search index (BSEARCHINDEX) from the sources of truth —
 * the backfill after an upgrade and the repair path when a queued refresh was
 * lost. Idempotent: rows are upserted by (user, kind, ref) and rows whose item
 * no longer exists are dropped.
 */
#[AsCommand(
    name: 'app:search:reindex',
    description: 'Rebuild the Smart Search index for one user or all users (self-locking)'
)]
final class SearchReindexCommand extends Command
{
    private const LOCK_TTL_SECONDS = 3600;
    private const USER_PAGE_SIZE = 200;

    public function __construct(
        private readonly SearchIndexer $indexer,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Re-index only this user id')
            ->addOption('all-users', null, InputOption::VALUE_NONE, 'Re-index every user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userOption = $input->getOption('user');
        $allUsers = (bool) $input->getOption('all-users');

        if (!$allUsers && !is_numeric($userOption)) {
            $io->error('Pass --user=<id> or --all-users.');

            return Command::INVALID;
        }

        $lock = $this->lockFactory->createLock('smart-search-reindex', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->writeln('Another Smart Search reindex is still in progress, skipping.');

            return Command::SUCCESS;
        }

        $totals = ['users' => 0, 'documents' => 0, 'failed' => 0];
        try {
            if (!$allUsers) {
                $this->reindexOne((int) $userOption, $totals, $io);
            } else {
                $afterId = 0;
                while ([] !== ($ids = $this->userRepository->findIdsAfter($afterId, self::USER_PAGE_SIZE))) {
                    foreach ($ids as $userId) {
                        $this->reindexOne($userId, $totals, $io);
                        $afterId = $userId;
                    }
                    $this->em->clear();
                }
            }
        } finally {
            $lock->release();
        }

        $io->success(sprintf(
            'Smart Search reindex: %d users, %d documents written, %d users failed.',
            $totals['users'],
            $totals['documents'],
            $totals['failed'],
        ));

        return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{users: int, documents: int, failed: int} $totals
     */
    private function reindexOne(int $userId, array &$totals, SymfonyStyle $io): void
    {
        try {
            $written = $this->indexer->reindexUser($userId);
            ++$totals['users'];
            $totals['documents'] += $written;
            $io->writeln(sprintf('user %d: %d documents', $userId, $written), OutputInterface::VERBOSITY_VERBOSE);
        } catch (\Throwable $e) {
            ++$totals['failed'];
            $io->warning(sprintf('User %d failed: %s', $userId, $e->getMessage()));
        }
    }
}
