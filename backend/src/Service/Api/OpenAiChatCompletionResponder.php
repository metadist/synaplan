<?php

declare(strict_types=1);

namespace App\Service\Api;

/**
 * Shapes OpenAI Chat Completions JSON and SSE chunks.
 *
 * First stream chunk is always `delta.role`. Tool-call deltas follow the
 * OpenAI convention: the first chunk per index carries id/type/name +
 * partial arguments; later chunks carry index + arguments only.
 */
final class OpenAiChatCompletionResponder
{
    /**
     * @param array<string, mixed> $result Facade/provider chat() payload
     *
     * @return array<string, mixed>
     */
    public static function nonStreamPayload(string $id, int $created, string $model, array $result): array
    {
        $toolCalls = self::normalizeToolCalls($result['tool_calls'] ?? []);
        $content = is_string($result['content'] ?? null) ? $result['content'] : '';
        $finish = $result['finish_reason'] ?? null;
        if (!is_string($finish) || '' === $finish) {
            $finish = [] !== $toolCalls ? 'tool_calls' : 'stop';
        }

        $message = [
            'role' => 'assistant',
            'content' => ('' === $content && [] !== $toolCalls) ? null : $content,
        ];
        if ([] !== $toolCalls) {
            $message['tool_calls'] = $toolCalls;
        }

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];

        return [
            'id' => $id,
            'object' => 'chat.completion',
            'created' => $created,
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => $message,
                    'finish_reason' => $finish,
                ],
            ],
            'usage' => self::usageBlock($usage),
        ];
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public static function usageBlock(array $usage): array
    {
        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ];
    }

    /**
     * Session / metering text: visible content plus one note per tool call.
     *
     * @param list<array<string, mixed>> $toolCalls
     */
    public static function responseTextForMetering(string $content, array $toolCalls): string
    {
        $notes = [];
        foreach ($toolCalls as $call) {
            $fn = is_array($call['function'] ?? null) ? $call['function'] : [];
            $name = is_string($fn['name'] ?? null) ? $fn['name'] : 'tool';
            $arguments = is_string($fn['arguments'] ?? null) ? $fn['arguments'] : '{}';
            $notes[] = sprintf('[tool_call %s(%s)]', $name, $arguments);
        }

        $parts = [];
        if ('' !== $content) {
            $parts[] = $content;
        }
        if ([] !== $notes) {
            $parts[] = implode("\n", $notes);
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public static function roleChunk(string $id, int $created, string $model): array
    {
        return self::chunk($id, $created, $model, ['role' => 'assistant'], null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function contentChunk(string $id, int $created, string $model, string $content): array
    {
        return self::chunk($id, $created, $model, ['content' => $content], null);
    }

    /**
     * Map a provider `tool_call_delta` to an OpenAI SSE chunk.
     *
     * @param array<string, mixed> $chunk
     * @param array<int, true>     $announcedIndexes
     *
     * @return array<string, mixed>
     */
    public static function toolCallDeltaChunk(
        string $id,
        int $created,
        string $model,
        array $chunk,
        array &$announcedIndexes,
    ): array {
        $index = (int) ($chunk['index'] ?? 0);
        $delta = ['index' => $index];
        $function = [
            'arguments' => is_string($chunk['arguments'] ?? null) ? $chunk['arguments'] : '',
        ];

        if (!isset($announcedIndexes[$index])) {
            $announcedIndexes[$index] = true;
            $callId = $chunk['id'] ?? null;
            $delta['id'] = is_string($callId) && '' !== $callId
                ? $callId
                : 'call_'.bin2hex(random_bytes(6));
            $delta['type'] = 'function';
            $deltaName = $chunk['name'] ?? null;
            $function = [
                'name' => is_string($deltaName) && '' !== $deltaName ? $deltaName : 'tool',
                'arguments' => $function['arguments'],
            ];
        }

        $delta['function'] = $function;

        return self::chunk($id, $created, $model, ['tool_calls' => [$delta]], null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function finishChunk(string $id, int $created, string $model, string $finishReason): array
    {
        return self::chunk($id, $created, $model, new \stdClass(), $finishReason);
    }

    /**
     * Trailing usage chunk for `stream_options.include_usage`.
     *
     * @param array<string, mixed> $usage
     *
     * @return array<string, mixed>
     */
    public static function usageChunk(string $id, int $created, string $model, array $usage): array
    {
        return [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [],
            'usage' => self::usageBlock($usage),
        ];
    }

    /**
     * @param array<string, mixed>|\stdClass $delta
     *
     * @return array<string, mixed>
     */
    private static function chunk(
        string $id,
        int $created,
        string $model,
        array|\stdClass $delta,
        ?string $finishReason,
    ): array {
        return [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => $delta,
                    'finish_reason' => $finishReason,
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeToolCalls(mixed $toolCalls): array
    {
        if (!is_array($toolCalls)) {
            return [];
        }

        $out = [];
        foreach ($toolCalls as $call) {
            if (is_array($call)) {
                $out[] = $call;
            }
        }

        return $out;
    }
}
