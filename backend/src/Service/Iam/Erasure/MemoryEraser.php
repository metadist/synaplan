<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\MessageDigest;
use App\Entity\UserMemory;
use App\Repository\MessageDigestRepository;
use App\Repository\SearchIndexRepository;
use App\Service\Memory\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Long-term memories, message digests and the Smart Search index (which holds
 * chat questions and file text). Their vectors in Qdrant go after commit; a
 * Qdrant failure only leaves orphaned vectors.
 */
#[AsTaggedItem(priority: 70)]
final readonly class MemoryEraser implements UserDataEraserInterface
{
    public function __construct(
        private UserMemoryService $memories,
        private MessageDigestRepository $digests,
        private SearchIndexRepository $searchIndex,
        private QdrantClientInterface $qdrant,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $userId = $erasure->userId;
        $this->memories->deleteAllFromSqlForUser($userId);
        $this->digests->deleteAllForUser($userId);
        $this->searchIndex->deleteByUser($userId);

        $erasure->afterCommit(fn () => $this->memories->purgeIndexForUser($userId));
        $erasure->afterCommit(function () use ($userId): void {
            $this->qdrant->deleteAllDigestsForUser($userId);
        });
    }

    public static function covers(): array
    {
        return [UserMemory::class, MessageDigest::class];
    }
}
