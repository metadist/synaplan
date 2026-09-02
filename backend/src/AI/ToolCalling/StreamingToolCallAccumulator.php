<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * Reassembles tool calls that arrive in fragments over a streaming response.
 *
 * Neither dialect sends a streamed tool call in one piece:
 *
 *  - OpenAI emits `choices[0].delta.tool_calls[]` entries carrying an `index`;
 *    the first entry for an index has `id` and `function.name`, and every
 *    following entry appends a slice of the JSON argument STRING. A call is
 *    only complete once the stream ends.
 *  - Anthropic opens a `content_block_start` block of `type: tool_use` (with
 *    `id` and `name`), then streams `input_json_delta` fragments of
 *    `partial_json`, then closes with `content_block_stop`.
 *
 * One instance accumulates ONE response. Feed it every raw chunk with the
 * matching `push*()` method, then read {@see self::toolCalls()} after the
 * stream finished.
 */
final class StreamingToolCallAccumulator
{
    /**
     * Partial calls keyed by their stream-local index (OpenAI `index`,
     * Anthropic content-block index).
     *
     * @var array<int|string, array{id: string, name: string, arguments: string}>
     */
    private array $partials = [];

    /**
     * Feed one decoded OpenAI streaming chunk.
     *
     * @param array<string, mixed> $chunk
     */
    public function pushOpenAiChunk(array $chunk): void
    {
        $choices = $chunk['choices'] ?? null;
        if (!is_array($choices) || !is_array($choices[0] ?? null)) {
            return;
        }

        $delta = $choices[0]['delta'] ?? null;
        $rawCalls = is_array($delta) ? ($delta['tool_calls'] ?? null) : null;
        if (!is_array($rawCalls)) {
            return;
        }

        foreach ($rawCalls as $position => $rawCall) {
            if (!is_array($rawCall)) {
                continue;
            }

            // `index` correlates the fragments of one call. Some OpenAI-dialect
            // servers omit it when only a single tool is called; the array
            // position is the documented fallback.
            $index = is_int($rawCall['index'] ?? null) ? $rawCall['index'] : (int) $position;
            $function = is_array($rawCall['function'] ?? null) ? $rawCall['function'] : [];

            $this->merge(
                $index,
                is_string($rawCall['id'] ?? null) ? $rawCall['id'] : null,
                is_string($function['name'] ?? null) ? $function['name'] : null,
                is_string($function['arguments'] ?? null) ? $function['arguments'] : null,
            );
        }
    }

    /**
     * Feed one decoded Anthropic streaming event.
     *
     * @param array<string, mixed> $event
     */
    public function pushAnthropicEvent(array $event): void
    {
        $type = $event['type'] ?? null;
        $index = $event['index'] ?? null;
        if (!is_int($index)) {
            return;
        }

        if ('content_block_start' === $type) {
            $block = $event['content_block'] ?? null;
            if (!is_array($block) || 'tool_use' !== ($block['type'] ?? null)) {
                return;
            }

            $this->merge(
                $index,
                is_string($block['id'] ?? null) ? $block['id'] : null,
                is_string($block['name'] ?? null) ? $block['name'] : null,
                null,
            );

            return;
        }

        if ('content_block_delta' === $type) {
            $delta = $event['delta'] ?? null;
            if (!is_array($delta) || 'input_json_delta' !== ($delta['type'] ?? null)) {
                return;
            }

            // Only append to a block we already saw start as `tool_use`;
            // text blocks share the same index space.
            if (!isset($this->partials[$index])) {
                return;
            }

            $this->merge(
                $index,
                null,
                null,
                is_string($delta['partial_json'] ?? null) ? $delta['partial_json'] : null,
            );
        }
    }

    public function hasToolCalls(): bool
    {
        return [] !== $this->partials;
    }

    /**
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        $calls = [];
        foreach ($this->partials as $partial) {
            if ('' === $partial['name']) {
                continue;
            }

            $calls[] = new ToolCall(
                id: $partial['id'],
                name: $partial['name'],
                arguments: ToolCallParser::decodeArguments($partial['arguments']),
            );
        }

        return $calls;
    }

    private function merge(int|string $index, ?string $id, ?string $name, ?string $argumentFragment): void
    {
        $this->partials[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];

        if (null !== $id && '' !== $id) {
            $this->partials[$index]['id'] = $id;
        }

        if (null !== $name && '' !== $name) {
            $this->partials[$index]['name'] = $name;
        }

        if (null !== $argumentFragment) {
            $this->partials[$index]['arguments'] .= $argumentFragment;
        }
    }
}
