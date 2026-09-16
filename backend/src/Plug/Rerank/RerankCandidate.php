<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

/**
 * One retrieval hit offered to a reranker. No adapter in S1.
 */
final readonly class RerankCandidate
{
    public function __construct(
        public string $id,
        public string $text,
        public float $score,
    ) {
    }
}
