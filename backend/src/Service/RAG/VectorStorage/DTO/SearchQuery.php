<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

final readonly class SearchQuery
{
    public function __construct(
        public int $userId,
        /** @var float[] */
        public array $vector,
        public ?string $groupKey = null,
        public int $limit = 10,
        public float $minScore = 0.3,
    ) {
    }
}
