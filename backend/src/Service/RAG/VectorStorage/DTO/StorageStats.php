<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

final readonly class StorageStats
{
    public function __construct(
        public int $totalChunks,
        public int $totalFiles,
        public int $totalGroups,
        /** @var array<string, int> */
        public array $chunksByGroup = [],
    ) {
    }
}
