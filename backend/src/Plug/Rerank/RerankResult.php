<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

/**
 * Ordered candidates after rerank. Empty adapter set in S1.
 *
 * @phpstan-type RankedHit array{id: string, text: string, score: float}
 */
final readonly class RerankResult
{
    /**
     * @param list<RankedHit> $hits
     */
    public function __construct(
        public array $hits,
        public string $provider = '',
        public int $ms = 0,
    ) {
    }
}
