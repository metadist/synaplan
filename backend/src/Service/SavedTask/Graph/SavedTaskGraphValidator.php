<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Service\Multitask\Plan\Capability;

final class SavedTaskGraphValidator
{
    public const VERSION = 1;
    public const MAX_NODES = 16;

    /**
     * @param array<string, mixed>      $graph
     * @param array<string, mixed>|null $columnConfig
     *
     * @return list<string>
     */
    public function validate(array $graph, string $columnTriggerType, ?array $columnConfig): array
    {
        $errors = [];
        if (($graph['version'] ?? null) !== self::VERSION) {
            $errors[] = 'graph version must be 1';
        }

        $trigger = $graph['trigger'] ?? null;
        if (!is_array($trigger) || !is_string($trigger['type'] ?? null)) {
            $errors[] = 'graph trigger type is required';
        } elseif ($trigger['type'] !== $columnTriggerType) {
            $errors[] = 'graph trigger must match the Saved Task trigger';
        }

        $nodes = $graph['nodes'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes)) {
            $errors[] = 'graph nodes must be a list';

            return $errors;
        }
        if (count($nodes) > self::MAX_NODES) {
            $errors[] = sprintf('too many steps (%d > %d)', count($nodes), self::MAX_NODES);
        }

        $ids = [];
        $capabilities = Capability::values();
        foreach ($nodes as $i => $node) {
            if (!is_array($node)) {
                $errors[] = "step[$i] must be an object";
                continue;
            }
            $id = $node['id'] ?? null;
            if (!is_string($id) || '' === $id) {
                $errors[] = "step[$i] needs an id";
            } elseif (isset($ids[$id])) {
                $errors[] = "duplicate step id '$id'";
            } else {
                $ids[$id] = true;
            }
            $capability = $node['capability'] ?? null;
            if (!is_string($capability) || !in_array($capability, $capabilities, true)) {
                $errors[] = "step[$i] has an unknown action";
            }
        }

        foreach ($nodes as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            $depends = $node['depends_on'] ?? [];
            if (!is_array($depends)) {
                $errors[] = "step[$i] depends_on must be a list";
                continue;
            }
            foreach ($depends as $dep) {
                if (!is_string($dep) || !isset($ids[$dep])) {
                    $errors[] = "step[$i] depends on an unknown step";
                }
                if (is_string($dep) && $dep === ($node['id'] ?? null)) {
                    $errors[] = "step[$i] cannot depend on itself";
                }
            }
        }

        if ($this->hasCycle($nodes)) {
            $errors[] = 'steps contain a cycle';
        }

        return $errors;
    }

    /**
     * @param list<mixed> $nodes
     */
    private function hasCycle(array $nodes): bool
    {
        $edges = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['id'] ?? null)) {
                continue;
            }
            $deps = $node['depends_on'] ?? [];
            $edges[$node['id']] = is_array($deps) ? array_values(array_filter($deps, 'is_string')) : [];
        }

        $state = [];
        $visit = function (string $id) use (&$visit, &$state, $edges): bool {
            $state[$id] = 1;
            foreach ($edges[$id] ?? [] as $dep) {
                if (($state[$dep] ?? 0) === 1) {
                    return true;
                }
                if (($state[$dep] ?? 0) === 0 && $visit($dep)) {
                    return true;
                }
            }
            $state[$id] = 2;

            return false;
        };

        foreach (array_keys($edges) as $id) {
            if (($state[$id] ?? 0) === 0 && $visit($id)) {
                return true;
            }
        }

        return false;
    }
}
