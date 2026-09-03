<?php

declare(strict_types=1);

namespace App\AI\Provider\Concerns;

use App\AI\Tool\CatalogToolUse;

/**
 * Shared Chat Completions tool wiring for Groq, Mistral, xAI, TrustedTokens,
 * HuggingFace and OpenAI-compatible endpoints.
 *
 * Request: forwards `tools`, `tool_choice`, `parallel_tool_calls`.
 * Non-stream: reads `choices[0].message.tool_calls` and `finish_reason`.
 * Stream: emits `tool_call_delta` chunks from `choices[0].delta.tool_calls`.
 *
 * `supportsToolCalling()` follows the catalog `tool_use` flag when a chat
 * row exists. Admin-registered OpenAI-compatible models are not in the
 * static catalog; those stay provider-capable so the persisted BJSON flag
 * is the decisive gate.
 */
trait ChatCompletionsToolSupport
{
    public function supportsToolCalling(string $model): bool
    {
        if (CatalogToolUse::hasChatRow($this->getName(), $model)) {
            return CatalogToolUse::supports($this->getName(), $model);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function applyChatCompletionsToolOptions(array $request, array $options): array
    {
        if (isset($options['tools']) && is_array($options['tools'])) {
            $request['tools'] = $options['tools'];
        }
        if (array_key_exists('tool_choice', $options)) {
            $request['tool_choice'] = $options['tool_choice'];
        }
        if (array_key_exists('parallel_tool_calls', $options)) {
            $request['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    protected function mergeChatCompletionsToolResult(array $result, mixed $choice): array
    {
        if (!is_array($choice)) {
            return $result;
        }
        $finish = $choice['finish_reason'] ?? null;
        if (is_string($finish) && '' !== $finish) {
            $result['finish_reason'] = $finish;
        }

        $calls = $this->normalizeChatCompletionsToolCalls($choice['message']['tool_calls'] ?? []);
        if ([] !== $calls) {
            $result['tool_calls'] = $calls;
            $result['content'] = (string) ($result['content'] ?? '');
        }

        return $result;
    }

    protected function emitChatCompletionsToolDeltas(mixed $choice, callable $callback): void
    {
        if (!is_array($choice)) {
            return;
        }
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        foreach ($delta['tool_calls'] ?? [] as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
            $callback([
                'type' => 'tool_call_delta',
                'index' => (int) ($tc['index'] ?? 0),
                'id' => isset($tc['id']) && is_string($tc['id']) && '' !== $tc['id'] ? $tc['id'] : null,
                'name' => isset($fn['name']) && is_string($fn['name']) && '' !== $fn['name'] ? $fn['name'] : null,
                'arguments' => is_string($fn['arguments'] ?? null) ? $fn['arguments'] : '',
            ]);
        }
    }

    /**
     * @return list<array{id: string, type: 'function', function: array{name: string, arguments: string}}>
     */
    protected function normalizeChatCompletionsToolCalls(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $call) {
            if (!is_array($call)) {
                continue;
            }
            $fn = is_array($call['function'] ?? null) ? $call['function'] : [];
            $out[] = [
                'id' => (string) ($call['id'] ?? ('call_'.bin2hex(random_bytes(6)))),
                'type' => 'function',
                'function' => [
                    'name' => (string) ($fn['name'] ?? 'tool'),
                    'arguments' => is_string($fn['arguments'] ?? null) && '' !== $fn['arguments']
                        ? $fn['arguments']
                        : '{}',
                ],
            ];
        }

        return $out;
    }
}
