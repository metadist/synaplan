<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;

/**
 * One scripted model turn, then a plain answer. `repeat` keeps returning the
 * tool turn so an iteration cap can stop the loop.
 */
final class ScriptedMessagesTranslator implements MessagesTranslatorInterface
{
    /** @var list<array{name: string, input?: array<string, mixed>, arguments?: array<string, mixed>}> */
    public array $toolUses = [];

    public string $finalText = 'Done. https://example.test/rates';

    public bool $repeat = false;

    public int $calls = 0;

    /** @var list<string|array<string, mixed>> */
    public array $toolResults = [];

    public function supports(string $providerName): bool
    {
        return true;
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->toolResults = [];
        $this->repeat = false;
        $this->toolUses = [];
        $this->finalText = 'Done. https://example.test/rates';
    }

    public function complete(array $requestBody, array $context): array
    {
        $this->capture($requestBody);
        ++$this->calls;
        if ($this->wantsTools()) {
            return $this->toolResponse();
        }

        return $this->textResponse();
    }

    public function stream(array $requestBody, array $context, callable $emit): MessagesUsage
    {
        $this->capture($requestBody);
        ++$this->calls;
        if ($this->wantsTools()) {
            $this->emitToolUses($emit);

            return new MessagesUsage(10, 5, 0, 0, 'tool_use');
        }

        $this->emitText($emit, $this->finalText);

        return new MessagesUsage(12, 4, 0, 0, 'end_turn');
    }

    private function wantsTools(): bool
    {
        return [] !== $this->toolUses && (1 === $this->calls || $this->repeat);
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    private function capture(array $requestBody): void
    {
        $messages = $requestBody['messages'] ?? [];
        if (!is_array($messages)) {
            return;
        }
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (!is_array($block) || 'tool_result' !== ($block['type'] ?? null)) {
                    continue;
                }
                $text = is_string($block['content'] ?? null) ? $block['content'] : '';
                $packed = ToolLoopText::pack($text);
                if (true === ($block['is_error'] ?? false)) {
                    $packed = is_array($packed) ? $packed + ['error' => true] : ['text' => $packed, 'error' => true];
                }
                $this->toolResults[] = $packed;
            }
        }
    }

    /**
     * @return array{status: int, headers: array<string, list<string>>, body: array<string, mixed>, usage: MessagesUsage}
     */
    private function toolResponse(): array
    {
        $content = [];
        foreach ($this->toolUses as $index => $tool) {
            $input = $tool['input'] ?? $tool['arguments'] ?? [];
            $content[] = [
                'type' => 'tool_use',
                'id' => 'toolu_'.($index + 1),
                'name' => $tool['name'],
                'input' => $input,
            ];
        }

        return [
            'status' => 200,
            'headers' => [],
            'body' => [
                'id' => 'msg_'.$this->calls,
                'type' => 'message',
                'role' => 'assistant',
                'content' => $content,
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ],
            'usage' => new MessagesUsage(10, 5, 0, 0, 'tool_use'),
        ];
    }

    /**
     * @return array{status: int, headers: array<string, list<string>>, body: array<string, mixed>, usage: MessagesUsage}
     */
    private function textResponse(): array
    {
        return [
            'status' => 200,
            'headers' => [],
            'body' => [
                'id' => 'msg_'.$this->calls,
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => $this->finalText]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
            ],
            'usage' => new MessagesUsage(12, 4, 0, 0, 'end_turn'),
        ];
    }

    private function emitToolUses(callable $emit): void
    {
        $emit([
            'event' => 'message_start',
            'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]],
        ]);
        foreach ($this->toolUses as $index => $tool) {
            $input = $tool['input'] ?? $tool['arguments'] ?? [];
            $emit([
                'event' => 'content_block_start',
                'data' => [
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => [
                        'type' => 'tool_use',
                        'id' => 'toolu_'.($index + 1),
                        'name' => $tool['name'],
                        'input' => [],
                    ],
                ],
            ]);
            $emit([
                'event' => 'content_block_delta',
                'data' => [
                    'type' => 'content_block_delta',
                    'index' => $index,
                    'delta' => [
                        'type' => 'input_json_delta',
                        'partial_json' => json_encode($input, \JSON_THROW_ON_ERROR),
                    ],
                ],
            ]);
            $emit([
                'event' => 'content_block_stop',
                'data' => ['type' => 'content_block_stop', 'index' => $index],
            ]);
        }
        $emit([
            'event' => 'message_delta',
            'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'tool_use'],
                'usage' => ['output_tokens' => 5],
            ],
        ]);
        $emit(['event' => 'message_stop', 'data' => ['type' => 'message_stop']]);
    }

    private function emitText(callable $emit, string $text): void
    {
        $emit([
            'event' => 'message_start',
            'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12]]],
        ]);
        $emit([
            'event' => 'content_block_start',
            'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'text', 'text' => ''],
            ],
        ]);
        $emit([
            'event' => 'content_block_delta',
            'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $text],
            ],
        ]);
        $emit([
            'event' => 'content_block_stop',
            'data' => ['type' => 'content_block_stop', 'index' => 0],
        ]);
        $emit([
            'event' => 'message_delta',
            'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 4],
            ],
        ]);
        $emit(['event' => 'message_stop', 'data' => ['type' => 'message_stop']]);
    }
}
