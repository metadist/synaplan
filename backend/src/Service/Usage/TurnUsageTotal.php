<?php

declare(strict_types=1);

namespace App\Service\Usage;

/**
 * Aggregated usage across all provider rounds of one turn (`03_contracts.md` §1.5).
 */
final readonly class TurnUsageTotal
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public int $cachedTokens = 0,
        public int $cacheCreationTokens = 0,
        public int $cacheCreation1hTokens = 0,
        public string $chargedCost = '0.000000',
        public bool $priceKnown = true,
        public bool $facadeMetered = false,
        public int $callCount = 0,
    ) {
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int, cache_creation_1h_tokens: int}
     */
    public function toTokenArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'cached_tokens' => $this->cachedTokens,
            'cache_creation_tokens' => $this->cacheCreationTokens,
            'cache_creation_1h_tokens' => $this->cacheCreation1hTokens,
        ];
    }
}
