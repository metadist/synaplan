<?php

declare(strict_types=1);

namespace App\Service\Context;

/**
 * Resolved size limits of one model: how many tokens the whole request may
 * carry and how many the provider will emit at most.
 *
 * `source` records where the numbers came from (`catalog` or `fallback`) so a
 * condensation decision can be explained in logs.
 */
final readonly class ContextWindowSpec
{
    public function __construct(
        public int $contextTokens,
        public int $maxOutputTokens,
        public string $source,
        public ?int $modelId = null,
    ) {
    }

    /**
     * Tokens available for INPUT once the output reservation is subtracted.
     */
    public function inputTokens(int $reservedOutputTokens): int
    {
        return max(0, $this->contextTokens - min($reservedOutputTokens, $this->contextTokens));
    }
}
