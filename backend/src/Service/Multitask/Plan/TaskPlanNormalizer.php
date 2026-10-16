<?php

declare(strict_types=1);

namespace App\Service\Multitask\Plan;

/**
 * Repairs the dependency edges of a decoded planner payload before validation.
 *
 * The planner model is asked to list every node it consumes in `depends_on`
 * AND to reference that node's output as `$nX.text` / `$nX.file` inside
 * `inputs`. Models regularly do only the second half. Without the edge, the
 * topological order is free to run the answering node BEFORE the data node:
 * `$n1.text` then resolves to an empty string, the model honestly reports
 * that it was given no data, and only afterwards does the data node succeed —
 * exactly the "tool call logged as successful, answer says nothing was
 * returned" shape reported against connected MCP systems.
 *
 * {@see inferDependencies()} scans every string inside `inputs` (recursively,
 * including `inputs.arguments` of `mcp_action`), collects the `$<id>.<field>`
 * references to OTHER existing nodes and adds them to `depends_on`. Existing
 * edges are kept, order is preserved, unknown ids are ignored (the validator
 * never sees them) and a self-reference is never added. The result is handed
 * to {@see TaskPlanValidator} unchanged otherwise, so an inferred edge that
 * would close a cycle is still rejected there.
 */
final class TaskPlanNormalizer
{
    /** `$n1.text`, `${n1.text}`, `$step_2.file`, … — same id grammar as NodeContext. */
    private const REFERENCE_PATTERN = '/\$\{?(?<id>[A-Za-z0-9_]+)\.(?<field>[A-Za-z]+)\}?/';

    /**
     * @param array<string, mixed> $payload decoded planner JSON
     *
     * @return array<string, mixed> the payload with completed `depends_on` lists
     */
    public static function inferDependencies(array $payload): array
    {
        $tasks = $payload['tasks'] ?? null;
        if (!is_array($tasks) || !array_is_list($tasks)) {
            return $payload;
        }

        $knownIds = [];
        foreach ($tasks as $task) {
            if (is_array($task) && is_string($task['id'] ?? null) && '' !== $task['id']) {
                $knownIds[$task['id']] = true;
            }
        }

        foreach ($tasks as $index => $task) {
            if (!is_array($task) || !is_string($task['id'] ?? null)) {
                continue;
            }
            $existing = $task['depends_on'] ?? [];
            if (!is_array($existing)) {
                continue; // malformed — leave it for the validator to report
            }

            $referenced = [];
            self::collectReferences($task['inputs'] ?? null, $referenced);

            $dependsOn = array_values(array_filter($existing, 'is_string'));
            foreach (array_keys($referenced) as $id) {
                $id = (string) $id;
                if ($id === $task['id'] || !isset($knownIds[$id]) || in_array($id, $dependsOn, true)) {
                    continue;
                }
                $dependsOn[] = $id;
            }

            if ($dependsOn !== $existing) {
                $tasks[$index]['depends_on'] = $dependsOn;
            }
        }

        $payload['tasks'] = $tasks;

        return $payload;
    }

    /**
     * Node ids referenced from a decoded payload's `inputs` that are missing
     * from its `depends_on` — what {@see inferDependencies()} would add. Used
     * by the planner to log the repair.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, list<string>> node id => added dependency ids
     */
    public static function missingDependencies(array $payload): array
    {
        $before = [];
        foreach ((array) ($payload['tasks'] ?? []) as $task) {
            if (is_array($task) && is_string($task['id'] ?? null)) {
                $before[$task['id']] = array_values(array_filter((array) ($task['depends_on'] ?? []), 'is_string'));
            }
        }

        $missing = [];
        foreach ((array) (self::inferDependencies($payload)['tasks'] ?? []) as $task) {
            if (!is_array($task) || !is_string($task['id'] ?? null)) {
                continue;
            }
            $added = array_values(array_diff((array) ($task['depends_on'] ?? []), $before[$task['id']] ?? []));
            if ([] !== $added) {
                $missing[$task['id']] = array_map('strval', $added);
            }
        }

        return $missing;
    }

    /**
     * @param array<string, true> $referenced
     */
    private static function collectReferences(mixed $value, array &$referenced): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::collectReferences($item, $referenced);
            }

            return;
        }
        if (!is_string($value) || !str_contains($value, '$')) {
            return;
        }
        if ((int) preg_match_all(self::REFERENCE_PATTERN, $value, $matches) > 0) {
            foreach ($matches['id'] as $id) {
                if ('message' !== $id) {
                    $referenced[$id] = true;
                }
            }
        }
    }
}
