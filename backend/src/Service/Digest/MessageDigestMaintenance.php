<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\Entity\MessageDigest;
use App\Repository\MessageDigestRepository;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Housekeeping for the message digest index (Sprint 5).
 *
 * Two invariants are enforced here:
 *  - a user never holds more than `DIGEST.MAX_PER_USER` ACTIVE digests
 *    (oldest-by-source-date entries are deactivated first), and
 *  - deleting a chat, or a long-term memory entry, deactivates its digests
 *    so `[Message:ID]` references into it stop resolving and its vectors
 *    leave the search index.
 *
 * MariaDB is authoritative: the DB soft-delete always happens; the Qdrant
 * point deletes are best-effort. Search drops a hit unless BMESSAGEDIGESTS
 * still has an active row for it, and `app:digest:reindex` removes the point.
 */
final readonly class MessageDigestMaintenance
{
    /** Prune in slices so one badly-over-cap user cannot hold a request or job hostage. */
    private const PRUNE_SLICE = 500;

    public function __construct(
        private MessageDigestRepository $digestRepository,
        private MessageDigestConfig $config,
        private QdrantClientInterface $qdrantClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Deactivate the oldest active digests above the per-user cap.
     *
     * @return int number of digests pruned
     */
    public function pruneOverflow(int $userId): int
    {
        $cap = $this->config->getMaxPerUser();
        $active = $this->digestRepository->countActiveForUser($userId);
        $overflow = $active - $cap;

        if ($overflow <= 0) {
            return 0;
        }

        $pruned = 0;
        while ($overflow > 0) {
            $slice = $this->digestRepository->findOldestActive($userId, min($overflow, self::PRUNE_SLICE));
            if ([] === $slice) {
                break;
            }

            $ids = array_map(static fn ($d): int => $d->getId(), $slice);
            $this->digestRepository->deactivateByIds($ids);
            $this->deletePoints($userId, $ids);

            $pruned += count($ids);
            $overflow -= count($ids);
        }

        $this->logger->info('Message digest prune: user over cap, oldest entries deactivated', [
            'user_id' => $userId,
            'cap' => $cap,
            'pruned' => $pruned,
        ]);

        return $pruned;
    }

    /**
     * Deletion hygiene: deactivate every digest of a chat (DB) and drop the
     * points (Qdrant, best-effort). Called before the chat itself is removed.
     *
     * @return int number of digests deactivated
     */
    public function deactivateForChat(int $userId, int $chatId): int
    {
        $digests = $this->digestRepository->findActiveByChat($userId, $chatId);
        if ([] === $digests) {
            return 0;
        }

        $ids = array_map(static fn ($d): int => $d->getId(), $digests);
        $this->digestRepository->deactivateByIds($ids);
        $this->deletePoints($userId, $ids);

        $this->logger->info('Message digests deactivated for deleted chat', [
            'user_id' => $userId,
            'chat_id' => $chatId,
            'count' => count($ids),
        ]);

        return count($ids);
    }

    /**
     * Deactivate one active digest owned by this user and drop its point.
     * Unknown, foreign and already inactive ids are a miss.
     */
    public function deactivateOwned(int $userId, int $digestId): bool
    {
        $ownedId = $this->digestRepository->findActiveOwnedId($userId, $digestId);
        if (null === $ownedId) {
            return false;
        }

        $this->digestRepository->deactivateByIds([$ownedId]);
        $this->deletePoints($userId, [$ownedId]);

        return true;
    }

    /**
     * Deactivate every active digest of this user and drop the points.
     *
     * Walks the table in {@see self::PRUNE_SLICE} pages so a user with
     * thousands of entries does not issue one Qdrant call per row.
     *
     * @return int number of digests deactivated
     */
    public function deactivateAllActive(int $userId): int
    {
        $deleted = 0;
        $afterId = 0;

        while (true) {
            $slice = $this->digestRepository->findActiveForUserAfterId($userId, $afterId, self::PRUNE_SLICE);
            if ([] === $slice) {
                break;
            }

            $ids = array_map(static fn (MessageDigest $digest): int => $digest->getId(), $slice);
            $nextAfterId = max($ids);
            if ($nextAfterId <= $afterId) {
                break;
            }
            $afterId = $nextAfterId;

            $deleted += $this->digestRepository->deactivateByIds($ids);
            $this->deletePoints($userId, $ids);
        }

        if ($deleted > 0) {
            $this->logger->info('Message digests deactivated for user', [
                'user_id' => $userId,
                'count' => $deleted,
            ]);
        }

        return $deleted;
    }

    /**
     * @param list<int> $digestIds
     */
    private function deletePoints(int $userId, array $digestIds): void
    {
        if ([] === $digestIds) {
            return;
        }

        $pointIds = [];
        foreach ($digestIds as $digestId) {
            $pointIds[] = MessageDigestService::qdrantPointId($userId, $digestId);
        }

        try {
            $this->qdrantClient->deleteDigests($pointIds);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to delete digest points from Qdrant (rows already deactivated)', [
                'user_id' => $userId,
                'count' => count($digestIds),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
