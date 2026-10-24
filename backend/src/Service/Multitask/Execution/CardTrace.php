<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

/**
 * The card fields a step reports besides its text, file and error: the trace
 * under Details, the ask-user question and the folder-read summary. One list,
 * so the live `task_update` event, the reload state and a task run's snapshot
 * carry the same fields.
 */
final class CardTrace
{
    /**
     * @return array{step_input?: string, step_output?: string, step_output_truncated?: true, duration_ms?: int, folder_read?: array<string, mixed>}
     */
    public static function fromResult(NodeResult $result): array
    {
        $fields = self::fromArray($result->metadata);
        unset($fields['ask_user']);

        return $fields;
    }

    /**
     * @param array<string, mixed> $source a `task_update` event's metadata or an assembled card
     *
     * @return array{step_input?: string, step_output?: string, step_output_truncated?: true, duration_ms?: int, ask_user?: array<string, mixed>, folder_read?: array<string, mixed>}
     */
    public static function fromArray(array $source): array
    {
        $fields = [];
        foreach (['step_input', 'step_output'] as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) && '' !== $value) {
                $fields[$key] = $value;
            }
        }
        if (true === ($source['step_output_truncated'] ?? false)) {
            $fields['step_output_truncated'] = true;
        }
        $duration = $source['duration_ms'] ?? null;
        if (is_int($duration)) {
            $fields['duration_ms'] = $duration;
        }
        foreach (['ask_user', 'folder_read'] as $key) {
            $value = $source[$key] ?? null;
            if (is_array($value)) {
                $fields[$key] = self::stringKeys($value);
            }
        }

        return $fields;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
