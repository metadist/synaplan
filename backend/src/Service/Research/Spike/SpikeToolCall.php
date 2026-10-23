<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

use App\AI\ToolCalling\ToolCall;

final readonly class SpikeToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {
    }

    /**
     * @return array{id: string, type: string, function: array{name: string, arguments: string}}
     */
    public function toMessage(): array
    {
        // An empty object decodes as [] and is sent back as a JSON array.
        // Anthropic rejects that, so an empty argument list is a one-key object.
        $payload = [] === $this->arguments ? ['ok' => true] : $this->arguments;

        return [
            'id' => '' !== $this->id ? $this->id : 'call_spike',
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'arguments' => json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * @param mixed $call a provider tool call, either a {@see ToolCall} or the OpenAI array shape
     */
    public static function fromProvider(mixed $call): ?self
    {
        if ($call instanceof ToolCall) {
            return new self($call->id, $call->name, $call->arguments);
        }
        if (!\is_array($call)) {
            return null;
        }
        $function = \is_array($call['function'] ?? null) ? $call['function'] : [];
        $arguments = $function['arguments'] ?? $call['arguments'] ?? [];
        if (\is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($arguments) || array_is_list($arguments)) {
            $arguments = [];
        }

        return new self(
            (string) ($call['id'] ?? ''),
            (string) ($function['name'] ?? $call['name'] ?? ''),
            $arguments,
        );
    }
}
