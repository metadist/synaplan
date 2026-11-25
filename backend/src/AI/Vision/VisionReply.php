<?php

declare(strict_types=1);

namespace App\AI\Vision;

/**
 * Answer of one vision call plus the tokens the provider reported for it.
 *
 * `usage` uses the chat keys (`prompt_tokens`, `completion_tokens`,
 * `total_tokens`) so {@see \App\Service\RateLimitService::recordUsage()} can
 * price it. An empty array means the provider reported no tokens; the usage
 * row then falls back to the byte estimate of prompt and answer.
 */
final readonly class VisionReply
{
    /**
     * @param array<string, int> $usage
     */
    public function __construct(
        public string $content,
        public array $usage = [],
    ) {
    }

    /**
     * Read the `usage` block of an OpenAI-style chat completion, or the
     * already-normalized Responses usage. Cached prompt tokens are kept, so
     * they are billed at the cache-read price and not as fresh input.
     */
    public static function fromChatCompletion(string $content, mixed $usage): self
    {
        if (!\is_array($usage)) {
            return new self($content);
        }

        $details = \is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];

        return new self($content, self::tokens(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            (int) ($usage['total_tokens'] ?? 0),
            (int) ($usage['cached_tokens'] ?? $details['cached_tokens'] ?? 0),
            (int) ($usage['cache_creation_tokens'] ?? 0),
        ));
    }

    /**
     * Read the `usage` block of an Anthropic Messages response. Anthropic
     * reports cache reads and writes beside `input_tokens`; the prompt total
     * includes them, as {@see \App\Service\Usage\CostCalculationService} expects.
     */
    public static function fromAnthropic(string $content, mixed $usage): self
    {
        if (!\is_array($usage)) {
            return new self($content);
        }

        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        return new self($content, self::tokens(
            (int) ($usage['input_tokens'] ?? 0) + $cacheRead + $cacheWrite,
            (int) ($usage['output_tokens'] ?? 0),
            0,
            $cacheRead,
            $cacheWrite,
        ));
    }

    /**
     * @return array<string, int>
     */
    private static function tokens(int $prompt, int $completion, int $total, int $cached = 0, int $cacheCreation = 0): array
    {
        if (0 === $prompt + $completion + $total) {
            return [];
        }

        $tokens = [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total > 0 ? $total : $prompt + $completion,
        ];
        if ($cached > 0) {
            $tokens['cached_tokens'] = $cached;
        }
        if ($cacheCreation > 0) {
            $tokens['cache_creation_tokens'] = $cacheCreation;
        }

        return $tokens;
    }
}
