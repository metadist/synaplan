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
        public ?string $thoughtSignature = null,
    ) {
    }

    /**
     * @return array{id: string, type: string, function: array{name: string, arguments: string}, thought_signature?: string}
     */
    public function toMessage(): array
    {
        $payload = [] === $this->arguments ? new \stdClass() : $this->arguments;

        $msg = [
            'id' => '' !== $this->id ? $this->id : 'call_spike',
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'arguments' => json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        ];
        if (null !== $this->thoughtSignature && '' !== $this->thoughtSignature) {
            $msg['thought_signature'] = $this->thoughtSignature;
        }

        return $msg;
    }

    /**
     * @param mixed $call a provider tool call, either a {@see ToolCall} or the OpenAI array shape
     */
    public static function fromProvider(mixed $call): ?self
    {
        if ($call instanceof ToolCall) {
            return new self('' !== $call->id ? $call->id : 'call_'.bin2hex(random_bytes(6)), $call->name, $call->arguments, $call->thoughtSignature);
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

        $id = (string) ($call['id'] ?? '');
        $sig = $call['thought_signature'] ?? $call['thoughtSignature'] ?? null;

        return new self(
            '' !== $id ? $id : 'call_'.bin2hex(random_bytes(6)),
            (string) ($function['name'] ?? $call['name'] ?? ''),
            $arguments,
            \is_string($sig) && '' !== $sig ? $sig : null,
        );
    }
}
