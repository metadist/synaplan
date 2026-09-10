<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;

/**
 * Resolves authored step inputs: a literal, a field from an earlier step,
 * or a field from the webhook / trigger payload.
 *
 * Shapes:
 *   { "literal": "…" }
 *   { "from": "step_2", "field": "summary" }
 *   { "from": "trigger", "field": "body.title" }
 */
final class StepInputResolver
{
    /**
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>
     */
    public function resolveAll(array $inputs, NodeContext $context): array
    {
        $resolved = [];
        foreach ($inputs as $key => $spec) {
            $resolved[$key] = $this->resolve($spec, $context);
        }

        return $resolved;
    }

    public function resolve(mixed $spec, NodeContext $context): mixed
    {
        if (!is_array($spec)) {
            return $spec;
        }
        if (array_key_exists('literal', $spec)) {
            return $spec['literal'];
        }
        $from = $spec['from'] ?? null;
        if (!is_string($from) || '' === $from) {
            return $spec;
        }
        $field = is_string($spec['field'] ?? null) ? $spec['field'] : 'text';
        if ('trigger' === $from) {
            $payload = $context->options['trigger_payload'] ?? [];

            return is_array($payload) ? $this->nested($payload, $field) : null;
        }

        $result = $context->getResult($from);
        if (!$result instanceof NodeResult) {
            return null;
        }

        return $this->fromResult($result, $field);
    }

    private function fromResult(NodeResult $result, string $field): mixed
    {
        return match ($field) {
            'text', 'summary' => $result->text,
            'error' => $result->error,
            'files' => $result->files,
            default => $this->nested($result->metadata, $field),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function nested(array $data, string $path): mixed
    {
        $cursor = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
