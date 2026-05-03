<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

final readonly class VectorChunk
{
    public function __construct(
        public int $userId,
        public int $fileId,
        public string $groupKey,
        public int $fileType,
        public int $chunkIndex,
        public int $startLine,
        public int $endLine,
        public string $text,
        /** @var float[] */
        public array $vector,
        public ?int $createdAt = null,
        // Embedding-stack metadata so SafeModelChange can:
        //   1. detect stale chunks when the active VECTORIZE model changes
        //      (different model_id or vector_dim → re-vectorize required);
        //   2. show in the admin UI which model produced which chunks.
        // Nullable for backward compatibility with legacy chunks that were
        // stored before this metadata existed.
        public ?int $embeddingModelId = null,
        public ?string $embeddingProvider = null,
        public ?string $embeddingModelName = null,
        public ?int $vectorDim = null,
    ) {
    }

    /**
     * Get the creation timestamp, falling back to current time if not set.
     */
    public function getCreatedTimestamp(): int
    {
        return $this->createdAt ?? time();
    }

    /**
     * Generate a unique point ID for Qdrant.
     */
    public function getPointId(): string
    {
        return sprintf('doc_%d_%d_%d', $this->userId, $this->fileId, $this->chunkIndex);
    }
}
