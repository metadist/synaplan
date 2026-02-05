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
    ) {
    }

    /**
     * Generate a unique point ID for Qdrant.
     */
    public function getPointId(): string
    {
        return sprintf('doc_%d_%d_%d', $this->userId, $this->fileId, $this->chunkIndex);
    }
}
