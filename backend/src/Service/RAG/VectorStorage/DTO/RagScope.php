<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

/**
 * One owner-bounded slice of a RAG query.
 *
 * A search is always a non-empty list of these. There is never a query
 * without an owner filter (CORE-5 / IAM C2).
 */
final readonly class RagScope
{
    /**
     * @param list<int> $fileIds empty = every file in this owner (+ optional folder)
     */
    public function __construct(
        public int $ownerId,
        public ?string $groupKey = null,
        public array $fileIds = [],
    ) {
    }

    public function isLegacyOwn(?int $searcherId, ?string $searcherGroupKey): bool
    {
        return [] === $this->fileIds
            && $this->ownerId === $searcherId
            && $this->groupKey === $searcherGroupKey;
    }
}
