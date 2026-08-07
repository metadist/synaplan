<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Token usage extracted from an Anthropic Messages API response (or synthesized
 * by a non-Anthropic translator into the same shape).
 *
 * Field mapping into {@see \App\Service\CostCalculationService::calculateCost()}:
 *   cachedTokens         = cache_read_input_tokens
 *   cacheCreationTokens  = cache_creation_input_tokens
 *   promptTokens         = input_tokens + cache_read + cache_creation
 */
final readonly class MessagesUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheCreationTokens = 0,
        public int $cacheReadTokens = 0,
        public ?string $stopReason = null,
    ) {
    }

    /**
     * Shape expected by RateLimitService::recordUsage metadata['usage'].
     *
     * @return array{
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     total_tokens: int,
     *     cached_tokens: int,
     *     cache_creation_tokens: int
     * }
     */
    public function toRateLimitUsage(): array
    {
        $prompt = $this->inputTokens + $this->cacheCreationTokens + $this->cacheReadTokens;

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $this->outputTokens,
            'total_tokens' => $prompt + $this->outputTokens,
            'cached_tokens' => $this->cacheReadTokens,
            'cache_creation_tokens' => $this->cacheCreationTokens,
        ];
    }

    /**
     * @param array<string, mixed> $usage Anthropic usage object
     */
    public static function fromAnthropicUsage(array $usage, ?string $stopReason = null): self
    {
        return new self(
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheCreationTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            stopReason: $stopReason,
        );
    }
}
