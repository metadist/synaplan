<?php

declare(strict_types=1);

namespace App\Service\Multitask\Plan;

/**
 * Strict, dependency-free validator for a decoded task-plan payload.
 *
 * We hand-roll validation (instead of pulling a JSON-Schema library) to avoid a
 * new composer dependency for v1 — the rule set is small and fully covered by
 * unit tests. {@see validate()} returns a list of human-readable errors (empty =
 * valid) so the planner can log adversarial model output before falling back to
 * a safe single-`chat` plan.
 *
 * Rules enforced:
 *   - version === 1
 *   - tasks is a non-empty list of objects
 *   - each node: unique non-empty string id; known capability; depends_on is a
 *     list of existing node ids with no self-reference; inputs/params are objects
 *   - the dependency graph is acyclic (a cyclic plan is rejected, never executed)
 *   - reply_node references an existing node id
 */
final class TaskPlanValidator
{
    private const MAX_NODES = 24;

    /**
     * @param mixed $payload decoded JSON (expected: associative array)
     *
     * @return list<string> validation errors (empty list == valid)
     */
    public function validate(mixed $payload): array
    {
        $errors = [];

        if (!is_array($payload)) {
            return ['plan must be a JSON object'];
        }

        $version = $payload['version'] ?? null;
        if (1 !== $version) {
            $errors[] = 'version must be 1';
        }

        if (isset($payload['language']) && !is_string($payload['language'])) {
            $errors[] = 'language must be a string';
        }

        $tasks = $payload['tasks'] ?? null;
        if (!is_array($tasks) || [] === $tasks || !array_is_list($tasks)) {
            $errors[] = 'tasks must be a non-empty array';

            return $errors; // nothing more we can check without nodes
        }

        if (count($tasks) > self::MAX_NODES) {
            $errors[] = sprintf('too many nodes (%d > %d)', count($tasks), self::MAX_NODES);
        }

        $ids = [];
        $capabilities = Capability::values();
        foreach ($tasks as $i => $node) {
            $label = "task[$i]";
            if (!is_array($node)) {
                $errors[] = "$label must be an object";
                continue;
            }

            $id = $node['id'] ?? null;
            if (!is_string($id) || '' === trim($id)) {
                $errors[] = "$label.id must be a non-empty string";
            } elseif (isset($ids[$id])) {
                $errors[] = "duplicate node id '$id'";
            } else {
                $ids[$id] = true;
            }

            $capability = $node['capability'] ?? null;
            if (!is_string($capability) || !in_array($capability, $capabilities, true)) {
                $errors[] = sprintf("%s.capability '%s' is not a known capability", $label, is_string($capability) ? $capability : gettype($capability));
            }

            if (array_key_exists('depends_on', $node) && !$this->isListOfStrings($node['depends_on'])) {
                $errors[] = "$label.depends_on must be a list of node ids";
            }

            if (array_key_exists('inputs', $node) && !$this->isObject($node['inputs'])) {
                $errors[] = "$label.inputs must be an object";
            }

            if (array_key_exists('params', $node) && !$this->isObject($node['params'])) {
                $errors[] = "$label.params must be an object";
            }
        }

        // Dependency references + self-reference (only meaningful once ids are known).
        foreach ($tasks as $i => $node) {
            if (!is_array($node) || !is_string($node['id'] ?? null)) {
                continue;
            }
            $id = $node['id'];
            $deps = $node['depends_on'] ?? [];
            if (!$this->isListOfStrings($deps)) {
                continue;
            }
            foreach ($deps as $dep) {
                if ($dep === $id) {
                    $errors[] = "node '$id' depends on itself";
                } elseif (!isset($ids[$dep])) {
                    $errors[] = "node '$id' depends on unknown node '$dep'";
                }
            }
        }

        // Acyclicity — only attempt when references are otherwise sound.
        if ([] === $errors && $this->hasCycle($tasks)) {
            $errors[] = 'plan contains a dependency cycle';
        }

        $replyNode = $payload['reply_node'] ?? null;
        if (!is_string($replyNode) || !isset($ids[$replyNode])) {
            $errors[] = 'reply_node must reference an existing node id';
        }

        return $errors;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     */
    private function hasCycle(array $tasks): bool
    {
        $graph = [];
        foreach ($tasks as $node) {
            $id = $node['id'];
            $graph[$id] = $node['depends_on'] ?? [];
        }

        $state = []; // 0 = unvisited, 1 = visiting, 2 = done
        $visit = function (string $id) use (&$visit, &$state, $graph): bool {
            if (1 === ($state[$id] ?? 0)) {
                return true; // back-edge → cycle
            }
            if (2 === ($state[$id] ?? 0)) {
                return false;
            }
            $state[$id] = 1;
            foreach ($graph[$id] ?? [] as $dep) {
                if (isset($graph[$dep]) && $visit($dep)) {
                    return true;
                }
            }
            $state[$id] = 2;

            return false;
        };

        foreach (array_keys($graph) as $id) {
            if ($visit((string) $id)) {
                return true;
            }
        }

        return false;
    }

    private function isObject(mixed $value): bool
    {
        // A JSON object decodes to an associative array; an empty array is acceptable.
        return is_array($value) && (!array_is_list($value) || [] === $value);
    }

    private function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
