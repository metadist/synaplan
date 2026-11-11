<?php

declare(strict_types=1);

namespace App\AI\Tool;

/**
 * Folds streamed `tool_call_delta` chunks (by index) into complete tool_calls.
 *
 * Gemini and some OpenAI-compatible upstreams omit `id` on later deltas or
 * altogether; missing ids become `call_<random>`. Empty `arguments` repair
 * to `{}`. Invalid JSON arguments also repair to `{}` so callers always
 * receive a JSON object string.
 */
final class ToolCallAccumulator
{
    /**
     * @var array<int, array{id: ?string, name: ?string, arguments: string, thought_signature: ?string}>
     */
    private array $byIndex = [];

    /**
     * @param array<string, mixed> $chunk Structured stream chunk
     */
    public function addDelta(array $chunk): void
    {
        if ('tool_call_delta' !== ($chunk['type'] ?? '')) {
            return;
        }

        $index = (int) ($chunk['index'] ?? 0);
        if (!isset($this->byIndex[$index])) {
            $this->byIndex[$index] = ['id' => null, 'name' => null, 'arguments' => '', 'thought_signature' => null];
        }

        if (isset($chunk['id']) && is_string($chunk['id']) && '' !== $chunk['id']) {
            $this->byIndex[$index]['id'] = $chunk['id'];
        }
        if (isset($chunk['name']) && is_string($chunk['name']) && '' !== $chunk['name']) {
            $this->byIndex[$index]['name'] = $chunk['name'];
        }
        if (isset($chunk['arguments']) && is_string($chunk['arguments'])) {
            $this->byIndex[$index]['arguments'] .= $chunk['arguments'];
        }
        $sig = $chunk['thought_signature'] ?? $chunk['thoughtSignature'] ?? null;
        if (is_string($sig) && '' !== $sig) {
            $this->byIndex[$index]['thought_signature'] = $sig;
        }
    }

    /**
     * @return list<array{id: string, type: 'function', function: array{name: string, arguments: string}, thought_signature?: string}>
     */
    public function complete(): array
    {
        ksort($this->byIndex);
        $out = [];
        foreach ($this->byIndex as $row) {
            $id = $row['id'] ?? '';
            if ('' === $id) {
                $id = 'call_'.bin2hex(random_bytes(6));
            }
            $call = [
                'id' => $id,
                'type' => 'function',
                'function' => [
                    'name' => $row['name'] ?? 'tool',
                    'arguments' => self::repairArguments($row['arguments']),
                ],
            ];
            if (null !== $row['thought_signature']) {
                $call['thought_signature'] = $row['thought_signature'];
            }
            $out[] = $call;
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return [] === $this->byIndex;
    }

    private static function repairArguments(string $arguments): string
    {
        if ('' === $arguments) {
            return '{}';
        }

        $decoded = json_decode($arguments);
        if ($decoded instanceof \stdClass) {
            return $arguments;
        }

        return '{}';
    }
}
