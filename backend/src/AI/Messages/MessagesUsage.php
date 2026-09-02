<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Token usage extracted from an Anthropic Messages API response (or synthesized
 * by a non-Anthropic translator into the same shape).
 *
 * Field mapping into {@see \App\Service\CostCalculationService::calculateCost()}:
 *   cachedTokens          = cache_read_input_tokens
 *   cacheCreationTokens   = cache_creation_input_tokens (TOTAL, both TTLs)
 *   cacheCreation1hTokens = cache_creation.ephemeral_1h_input_tokens (subset of the above)
 *   promptTokens          = input_tokens + cache_read + cache_creation
 *
 * Anthropic bills 1-hour cache writes (opt-in via a `cache_control` block with
 * `"ttl": "1h"`) at 2x the base input price, versus 1.25x for the default
 * 5-minute TTL. The `usage.cache_creation` object breaks the aggregate down by
 * TTL so both rates can be billed correctly instead of assuming everything is
 * 5-minute (see docs: https://platform.claude.com/docs/en/build-with-claude/prompt-caching).
 */
final readonly class MessagesUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheCreationTokens = 0,
        public int $cacheReadTokens = 0,
        public ?string $stopReason = null,
        // Appended after the original 5 params (rather than inserted next to
        // cacheCreationTokens) so existing POSITIONAL constructor calls —
        // e.g. `new MessagesUsage(10, 5, 0, 0, 'tool_use')` in tests — keep
        // binding to the same parameters instead of silently shifting.
        public int $cacheCreation1hTokens = 0,
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
     *     cache_creation_tokens: int,
     *     cache_creation_1h_tokens: int
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
            'cache_creation_1h_tokens' => $this->cacheCreation1hTokens,
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
            cacheCreation1hTokens: self::extractCacheCreation1hTokens($usage),
            cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            stopReason: $stopReason,
        );
    }

    /**
     * Pulls the 1-hour-TTL slice out of Anthropic's `cache_creation` breakdown
     * object, e.g. `{"ephemeral_5m_input_tokens": 148, "ephemeral_1h_input_tokens": 100}`.
     * Shared by every call site that hand-parses a raw usage array (SSE
     * `message_start` events don't go through fromAnthropicUsage()).
     *
     * @param array<string, mixed> $usage Anthropic usage object
     */
    public static function extractCacheCreation1hTokens(array $usage): int
    {
        $breakdown = $usage['cache_creation'] ?? null;

        return \is_array($breakdown) ? (int) ($breakdown['ephemeral_1h_input_tokens'] ?? 0) : 0;
    }

    public function withStopReason(?string $stopReason): self
    {
        return new self(
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            cacheCreationTokens: $this->cacheCreationTokens,
            cacheReadTokens: $this->cacheReadTokens,
            stopReason: $stopReason,
            cacheCreation1hTokens: $this->cacheCreation1hTokens,
        );
    }
}
