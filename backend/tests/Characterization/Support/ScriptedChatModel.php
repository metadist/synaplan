<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

/**
 * Scripted {@see \App\AI\Service\AiFacade} replies for the document loop and
 * the OpenAI gateway loop. Tool-role messages the loop feeds back are kept.
 */
final class ScriptedChatModel
{
    /** @var list<array{name: string, arguments: array<string, mixed>}> */
    public array $toolCalls = [];

    public string $finalText = 'Done. https://example.test/rates';

    public bool $repeat = false;

    public int $calls = 0;

    /** @var list<string|array<string, mixed>> */
    public array $toolResults = [];

    public function reset(): void
    {
        $this->calls = 0;
        $this->toolResults = [];
        $this->repeat = false;
        $this->toolCalls = [];
        $this->finalText = 'Done. https://example.test/rates';
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    public function reply(array $messages): array
    {
        $this->capture($messages);
        ++$this->calls;
        if ([] !== $this->toolCalls && (1 === $this->calls || $this->repeat)) {
            $calls = [];
            foreach ($this->toolCalls as $index => $tool) {
                $calls[] = [
                    'id' => 'call_'.($index + 1),
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'arguments' => json_encode($tool['arguments'], \JSON_THROW_ON_ERROR),
                    ],
                ];
            }

            return [
                'content' => '',
                'finish_reason' => 'tool_calls',
                'tool_calls' => $calls,
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        }

        return [
            'content' => $this->finalText,
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4, 'total_tokens' => 16],
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function capture(array $messages): void
    {
        foreach ($messages as $message) {
            if ('tool' !== ($message['role'] ?? null) || !is_string($message['content'] ?? null)) {
                continue;
            }
            $this->toolResults[] = ToolLoopText::pack($message['content']);
        }
    }
}
