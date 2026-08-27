<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\Repository\MessageDigestRepository;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Housekeeping for the message digest index (Sprint 5).
 *
 * Two invariants are enforced here:
 *  - a user never holds more than `DIGEST.MAX_PER_USER` ACTIVE digests
 *    (oldest-by-source-date entries are deactivated first), and
 *  - deleting a chat deactivates its digests so `[Message:ID]` references
 *    into it stop resolving and its vectors leave the search index.
 *
 * MariaDB is authoritative: the DB soft-delete always happens; the Qdrant
 * point deletes are best-effort (an orphaned vector is filtered out at
 * search time by the `active` payload flag and removed on the next reindex).
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
     * @param list<int> $digestIds
     */
    private function deletePoints(int $userId, array $digestIds): void
    {
        foreach ($digestIds as $digestId) {
            try {
                $this->qdrantClient->deleteDigest(MessageDigestService::qdrantPointId($userId, $digestId));
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to delete digest point from Qdrant (row already deactivated)', [
                    'user_id' => $userId,
                    'digest_id' => $digestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
