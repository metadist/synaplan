<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Token, unit and cost accounting of an {@see AiCallResult} (`03_contracts.md` §1.7.2).
 */
final readonly class AiUsage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public int $cachedTokens = 0,
        public int $cacheCreationTokens = 0,
        public int $cacheCreation1hTokens = 0,
        public int $imageCount = 0,
        public float $durationSeconds = 0.0,
        public int $characterCount = 0,
        public ?string $chargedCost = null,
    ) {
    }

    /**
     * @param array<string, mixed> $usage
     */
    public static function fromTokenArray(array $usage, ?string $chargedCost = null): self
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));

        return new self(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
            cachedTokens: (int) ($usage['cached_tokens'] ?? 0),
            cacheCreationTokens: (int) ($usage['cache_creation_tokens'] ?? 0),
            cacheCreation1hTokens: (int) ($usage['cache_creation_1h_tokens'] ?? 0),
            chargedCost: $chargedCost,
        );
    }

    public function withChargedCost(?string $chargedCost): self
    {
        return new self(
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
            totalTokens: $this->totalTokens,
            cachedTokens: $this->cachedTokens,
            cacheCreationTokens: $this->cacheCreationTokens,
            cacheCreation1hTokens: $this->cacheCreation1hTokens,
            imageCount: $this->imageCount,
            durationSeconds: $this->durationSeconds,
            characterCount: $this->characterCount,
            chargedCost: $chargedCost,
        );
    }
}
