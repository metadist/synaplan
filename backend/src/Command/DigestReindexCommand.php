<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Repository\UserRepository;
use App\Service\Digest\MessageDigestService;
use App\Service\VectorSearch\QdrantClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Rebuild the Qdrant `user_message_digests` collection from the
 * authoritative MariaDB rows — the recovery path after an embedding-model
 * change or a lost/corrupted Qdrant volume.
 *
 * Point ids are deterministic ({@see MessageDigestService::qdrantPointId}),
 * so re-indexing overwrites the previous points in place. Points whose row
 * is inactive or missing are deleted. `--dry-run` reports those deletions
 * and does not embed or delete anything.
 */
#[AsCommand(
    name: 'app:digest:reindex',
    description: 'Rebuild the Qdrant digest index from MariaDB (LIVE embedding calls, self-locking)'
)]
final class DigestReindexCommand extends Command
{
    private const LOCK_TTL_SECONDS = 7200;
    private const DEFAULT_PAGE_SIZE = 100;

    public function __construct(
        private readonly MessageDigestRepository $digestRepository,
        private readonly UserRepository $userRepository,
        private readonly MessageDigestService $digestService,
        private readonly QdrantClientInterface $qdrantClient,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Re-index only this user id')
            ->addOption('all-users', null, InputOption::VALUE_NONE, 'Re-index every user with digest rows')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Digests hydrated per page', (string) self::DEFAULT_PAGE_SIZE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report stale points that would be deleted; do not embed or delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userOption = $input->getOption('user');
        $allUsers = (bool) $input->getOption('all-users');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$allUsers && !is_numeric($userOption)) {
            $io->error('Pass --user=<id> or --all-users (embedding every digest again costs real model calls).');

            return Command::INVALID;
        }

        $lock = $this->lockFactory->createLock('message-digest-reindex', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->writeln('Another digest reindex is still in progress, skipping.');

            return Command::SUCCESS;
        }

        $pageSize = max(10, (int) $input->getOption('page-size'));
        $totals = ['users' => 0, 'reindexed' => 0, 'failed' => 0, 'removed' => 0];

        try {
            $userIds = $allUsers
                ? $this->digestRepository->findDistinctUserIds()
                : [(int) $userOption];

            foreach ($userIds as $userId) {
                $user = $this->userRepository->find($userId);
                if (null === $user) {
                    $io->warning(sprintf('User %d not found, skipping rebuild.', $userId));
                } else {
                    ++$totals['users'];
                    if (!$dryRun) {
                        $this->rebuildActive($userId, $user, $pageSize, $io, $totals);
                    }
                }

                $this->removeStalePoints($userId, $pageSize, $dryRun, $io, $totals);
            }
        } finally {
            $lock->release();
        }

        if ($dryRun) {
            $io->success(sprintf(
                'Digest reindex dry-run: %d users, %d stale points would be removed. Nothing was deleted.',
                $totals['users'],
                $totals['removed'],
            ));

            return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $io->success(sprintf(
            'Digest reindex: %d users, %d points rebuilt, %d failed, %d stale points removed.',
            $totals['users'],
            $totals['reindexed'],
            $totals['failed'],
            $totals['removed'],
        ));

        return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{users: int, reindexed: int, failed: int, removed: int} $totals
     */
    private function rebuildActive(int $userId, User $user, int $pageSize, SymfonyStyle $io, array &$totals): void
    {
        $afterId = 0;
        while (true) {
            $page = $this->digestRepository->findActiveForUserAfterId($userId, $afterId, $pageSize);
            if ([] === $page) {
                break;
            }

            foreach ($page as $digest) {
                if ($this->digestService->mirrorToQdrant($user, $digest)) {
                    ++$totals['reindexed'];
                } else {
                    ++$totals['failed'];
                }
                $afterId = $digest->getId();
            }

            $io->writeln(sprintf(
                'user %d: %d points rebuilt so far (%d failed)',
                $userId,
                $totals['reindexed'],
                $totals['failed'],
            ), OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    /**
     * @param array{users: int, reindexed: int, failed: int, removed: int} $totals
     */
    private function removeStalePoints(int $userId, int $pageSize, bool $dryRun, SymfonyStyle $io, array &$totals): void
    {
        $afterId = 0;
        while (true) {
            $page = $this->digestRepository->findInactiveForUserAfterId($userId, $afterId, $pageSize);
            if ([] === $page) {
                break;
            }

            foreach ($page as $digest) {
                $afterId = $digest->getId();
                $this->dropPoint(
                    MessageDigestService::qdrantPointId($userId, $digest->getId()),
                    'inactive',
                    $dryRun,
                    $io,
                    $totals,
                );
            }
        }

        try {
            $points = $this->qdrantClient->scrollDigests($userId);
        } catch (\Throwable $e) {
            ++$totals['failed'];
            $io->warning(sprintf('Could not list digest points for user %d: %s', $userId, $e->getMessage()));

            return;
        }

        /** @var array<int, string> $byDigestId */
        $byDigestId = [];
        /** @var array<int, list<string>> $byMessageId */
        $byMessageId = [];
        foreach ($points as $point) {
            $logical = $this->logicalDigestPointId($point);
            if ('' === $logical) {
                continue;
            }
            $digestId = MessageDigestService::digestIdFromPointId($userId, $logical);
            if (null !== $digestId) {
                $byDigestId[$digestId] = $logical;
                continue;
            }
            $messageId = (int) ($point['payload']['message_id'] ?? 0);
            if ($messageId > 0) {
                $byMessageId[$messageId][] = $logical;
                continue;
            }
            $io->warning(sprintf(
                'Skipping digest point %s for user %d: cannot tell which row it belongs to.',
                $logical,
                $userId,
            ));
        }

        if ([] !== $byDigestId) {
            $existingIds = array_fill_keys(
                $this->digestRepository->findExistingIds($userId, array_map(intval(...), array_keys($byDigestId))),
                true,
            );
            foreach ($byDigestId as $digestId => $logical) {
                if (!isset($existingIds[$digestId])) {
                    $this->dropPoint($logical, 'orphan', $dryRun, $io, $totals);
                }
            }
        }

        if ([] !== $byMessageId) {
            $existingMessages = array_fill_keys(
                $this->digestRepository->findExistingMessageIds($userId, array_map(intval(...), array_keys($byMessageId))),
                true,
            );
            foreach ($byMessageId as $messageId => $logicals) {
                if (isset($existingMessages[$messageId])) {
                    continue;
                }
                foreach ($logicals as $logical) {
                    $this->dropPoint($logical, 'orphan', $dryRun, $io, $totals);
                }
            }
        }
    }

    /**
     * @param array{id?: string, payload?: array<string, mixed>} $point
     */
    private function logicalDigestPointId(array $point): string
    {
        $payload = $point['payload'] ?? [];
        if (isset($payload['_point_id']) && is_string($payload['_point_id']) && '' !== $payload['_point_id']) {
            return $payload['_point_id'];
        }

        return is_string($point['id'] ?? null) ? $point['id'] : '';
    }

    /**
     * @param array{users: int, reindexed: int, failed: int, removed: int} $totals
     */
    private function dropPoint(string $pointId, string $kind, bool $dryRun, SymfonyStyle $io, array &$totals): void
    {
        if ($dryRun) {
            ++$totals['removed'];
            $io->writeln(sprintf('would delete %s digest point %s', $kind, $pointId));

            return;
        }

        try {
            $this->qdrantClient->deleteDigest($pointId);
            ++$totals['removed'];
        } catch (\Throwable $e) {
            ++$totals['failed'];
            $io->warning(sprintf('Failed to delete %s digest point %s: %s', $kind, $pointId, $e->getMessage()));
        }
    }
}
