<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final readonly class SpikeTurn
{
    /**
     * @param list<SpikeToolCall> $calls
     */
    public function __construct(
        public string $content,
        public array $calls,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function fromProvider(array $response): self
    {
        $calls = [];
        foreach ($response['tool_calls'] ?? [] as $call) {
            $parsed = SpikeToolCall::fromProvider($call);
            if (null !== $parsed && '' !== $parsed->name) {
                $calls[] = $parsed;
            }
        }

        $usage = \is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new self(
            (string) ($response['content'] ?? ''),
            $calls,
            \is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : 0,
            \is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : 0,
        );
    }
}
