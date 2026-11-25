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
     * Read the `usage` block of an OpenAI-style chat completion.
     */
    public static function fromChatCompletion(string $content, mixed $usage): self
    {
        if (!\is_array($usage)) {
            return new self($content);
        }

        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);

        return new self($content, self::tokens($prompt, $completion, (int) ($usage['total_tokens'] ?? 0)));
    }

    /**
     * Read the `usage` block of an Anthropic Messages response.
     */
    public static function fromAnthropic(string $content, mixed $usage): self
    {
        if (!\is_array($usage)) {
            return new self($content);
        }

        return new self($content, self::tokens((int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0), 0));
    }

    /**
     * @return array<string, int>
     */
    private static function tokens(int $prompt, int $completion, int $total): array
    {
        if (0 === $prompt + $completion + $total) {
            return [];
        }

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total > 0 ? $total : $prompt + $completion,
        ];
    }
}
