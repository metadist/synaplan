<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

final readonly class SearchResult
{
    public function __construct(
        public int|string $chunkId,
        public int $fileId,
        public string $groupKey,
        public string $text,
        public float $score,
        public int $startLine,
        public int $endLine,
        public ?string $fileName = null,
        public ?string $mimeType = null,
    ) {
    }
}
