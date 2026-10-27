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
     * @return array{step_input?: string, step_output?: string, step_output_truncated?: true, duration_ms?: int, folder_read?: array<string, mixed>, recovery?: array{attempts: list<array{query: string, hits: int}>, matched_query?: string, empty?: true}}
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
     * @return array{step_input?: string, step_output?: string, step_output_truncated?: true, duration_ms?: int, ask_user?: array<string, mixed>, folder_read?: array<string, mixed>, recovery?: array{attempts: list<array{query: string, hits: int}>, matched_query?: string, empty?: true}}
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
        $recovery = self::recovery($source['recovery'] ?? null);
        if (null !== $recovery) {
            $fields['recovery'] = $recovery;
        }

        return $fields;
    }

    /**
     * The widened-search summary, capped so a card cannot carry a tool dump.
     *
     * @return array{attempts: list<array{query: string, hits: int}>, matched_query?: string, empty?: true}|null
     */
    private static function recovery(mixed $value): ?array
    {
        if (!is_array($value) || !is_array($value['attempts'] ?? null)) {
            return null;
        }

        $attempts = [];
        foreach ($value['attempts'] as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $query = $attempt['query'] ?? null;
            if (!is_string($query) || '' === trim($query)) {
                continue;
            }
            $hits = $attempt['hits'] ?? 0;
            $attempts[] = [
                'query' => mb_substr(trim($query), 0, 160),
                'hits' => is_int($hits) && $hits >= 0 ? $hits : 0,
            ];
            if (count($attempts) >= 4) {
                break;
            }
        }
        if ([] === $attempts) {
            return null;
        }

        $out = ['attempts' => $attempts];
        $matched = $value['matched_query'] ?? null;
        if (is_string($matched) && '' !== trim($matched)) {
            $out['matched_query'] = mb_substr(trim($matched), 0, 160);
        }
        if (true === ($value['empty'] ?? false)) {
            $out['empty'] = true;
        }

        return $out;
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
