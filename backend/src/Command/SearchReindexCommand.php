<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\SmartSearch\Index\SearchIndexEmbedder;
use App\Service\SmartSearch\Index\SearchIndexer;
use App\Service\SmartSearch\Index\SettingsCatalog;
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
 * no longer exists are dropped. Vectors are filled afterwards unless
 * `--no-embed` is given; rows without one are still found by keywords.
 */
#[AsCommand(
    name: 'app:search:reindex',
    description: 'Rebuild the Smart Search index for one user, all users, or the settings catalog (self-locking)'
)]
final class SearchReindexCommand extends Command
{
    private const LOCK_TTL_SECONDS = 3600;
    private const USER_PAGE_SIZE = 200;
    private const CATALOG_EMBED_ROWS = 2000;

    public function __construct(
        private readonly SearchIndexer $indexer,
        private readonly SearchIndexEmbedder $embedder,
        private readonly SettingsCatalog $catalog,
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
            ->addOption('all-users', null, InputOption::VALUE_NONE, 'Re-index every user and the settings catalog')
            ->addOption('catalog', null, InputOption::VALUE_NONE, 'Re-index only the shared settings catalog')
            ->addOption('no-embed', null, InputOption::VALUE_NONE, 'Write keyword rows only, skip the embedding step');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userOption = $input->getOption('user');
        $allUsers = (bool) $input->getOption('all-users');
        $catalogOnly = (bool) $input->getOption('catalog');
        $embed = !$input->getOption('no-embed');

        if (!$allUsers && !$catalogOnly && !is_numeric($userOption)) {
            $io->error('Pass --user=<id>, --all-users or --catalog.');

            return Command::INVALID;
        }

        $lock = $this->lockFactory->createLock('smart-search-reindex', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->writeln('Another Smart Search reindex is still in progress, skipping.');

            return Command::SUCCESS;
        }

        $totals = ['users' => 0, 'documents' => 0, 'embedded' => 0, 'failed' => 0];
        try {
            if ($allUsers || $catalogOnly) {
                $totals['documents'] += $this->indexer->replaceCatalog(SettingsCatalog::KIND, $this->catalog->documents());
                if ($embed) {
                    $totals['embedded'] += $this->embedder->embedPending(SettingsCatalog::CATALOG_USER_ID, self::CATALOG_EMBED_ROWS);
                }
            }
            if (is_numeric($userOption)) {
                $this->reindexOne((int) $userOption, $embed, $totals, $io);
            } elseif ($allUsers) {
                $afterId = 0;
                while ([] !== ($ids = $this->userRepository->findIdsAfter($afterId, self::USER_PAGE_SIZE))) {
                    foreach ($ids as $userId) {
                        $this->reindexOne($userId, $embed, $totals, $io);
                        $afterId = $userId;
                    }
                    $this->em->clear();
                }
            }
        } finally {
            $lock->release();
        }

        $io->success(sprintf(
            'Smart Search reindex: %d users, %d documents written, %d vectors filled, %d users failed.',
            $totals['users'],
            $totals['documents'],
            $totals['embedded'],
            $totals['failed'],
        ));

        return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{users: int, documents: int, embedded: int, failed: int} $totals
     */
    private function reindexOne(int $userId, bool $embed, array &$totals, SymfonyStyle $io): void
    {
        try {
            $written = $this->indexer->reindexUser($userId);
            $embedded = $embed ? $this->embedder->embedPending($userId) : 0;
            ++$totals['users'];
            $totals['documents'] += $written;
            $totals['embedded'] += $embedded;
            $io->writeln(sprintf('user %d: %d documents, %d vectors', $userId, $written, $embedded), OutputInterface::VERBOSITY_VERBOSE);
        } catch (\Throwable $e) {
            ++$totals['failed'];
            $io->warning(sprintf('User %d failed: %s', $userId, $e->getMessage()));
        }
    }
}
