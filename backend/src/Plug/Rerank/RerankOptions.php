<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

/**
 * Per-call rerank options. Port exists so S4 adds adapters only.
 */
final readonly class RerankOptions
{
    public function __construct(
        public int $latencyBudgetMs = 800,
        public int $maxCandidateChars = 2000,
        public ?string $modelKey = null,
    ) {
    }
}
