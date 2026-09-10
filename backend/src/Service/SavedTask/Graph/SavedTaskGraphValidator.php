<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Service\Multitask\Plan\Capability;
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\Security\SsrfGuard;

final class SavedTaskGraphValidator
{
    public const VERSION = 1;
    public const MAX_NODES = 16;

    public function __construct(
        private readonly ?WorkflowsConfig $workflowsConfig = null,
        private readonly ?SsrfGuard $ssrfGuard = null,
    ) {
    }

    /**
     * @param array<string, mixed>      $graph
     * @param array<string, mixed>|null $columnConfig
     *
     * @return list<string>
     */
    public function validate(array $graph, string $columnTriggerType, ?array $columnConfig, ?int $ownerId = null): array
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

        $builderOn = null !== $this->workflowsConfig && $this->workflowsConfig->isBuilderEnabled($ownerId);
        $allowed = Capability::values();
        if (!$builderOn) {
            $allowed = array_values(array_diff($allowed, Capability::builderOnlyValues()));
        }

        $ids = [];
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
            if (!is_string($capability) || !in_array($capability, $allowed, true)) {
                $errors[] = "step[$i] has an unknown action";
            }
        }

        if ($builderOn) {
            foreach ($nodes as $i => $node) {
                if (is_array($node)) {
                    array_push($errors, ...$this->validateBuilderNode($i, $node));
                }
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

        $settings = $graph['settings'] ?? null;
        if (null !== $settings && !is_array($settings)) {
            $errors[] = 'graph settings must be an object';
        } elseif (is_array($settings) && isset($settings['approvalExpiryHours'])) {
            $hours = $settings['approvalExpiryHours'];
            if (!is_int($hours) && !(is_string($hours) && ctype_digit($hours))) {
                $errors[] = 'approvalExpiryHours must be a whole number of hours';
            } else {
                $value = (int) $hours;
                if ($value < 1 || $value > 720) {
                    $errors[] = 'approvalExpiryHours must be between 1 and 720';
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private function validateBuilderNode(int $i, array $node): array
    {
        $errors = [];
        $depends = is_array($node['depends_on'] ?? null) ? $node['depends_on'] : [];
        $params = is_array($node['params'] ?? null) ? $node['params'] : [];
        $inputs = is_array($params['inputs'] ?? null) ? $params['inputs'] : (is_array($node['inputs'] ?? null) ? $node['inputs'] : []);
        foreach ($inputs as $key => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $from = $spec['from'] ?? null;
            if (!is_string($from) || '' === $from) {
                continue;
            }
            if ('trigger' === $from) {
                continue;
            }
            if (!in_array($from, $depends, true)) {
                $errors[] = "step[$i] input '$key' must come from an earlier step this step depends on";
            }
        }

        $approval = $params['approval'] ?? null;
        if (null !== $approval) {
            if (!in_array($approval, ['approve', 'block'], true)) {
                $errors[] = "step[$i] can only tighten approval (ask me, or block)";
            }
        }

        $capability = $node['capability'] ?? null;
        if (Capability::OutboundWebhook->value === $capability) {
            $url = is_string($params['url'] ?? null) ? trim($params['url']) : '';
            if ('' === $url || !str_starts_with(strtolower($url), 'https://')) {
                $errors[] = "step[$i] needs an https address";
            } elseif (null !== $this->ssrfGuard && $this->ssrfGuard->isBlockedUrl($url)) {
                $errors[] = "step[$i] cannot send to that address";
            }
        }
        if (Capability::ToolCall->value === $capability) {
            $tool = is_string($params['tool'] ?? null) ? trim($params['tool']) : '';
            if ('' === $tool) {
                $errors[] = "step[$i] needs a tool";
            }
        }
        if (Capability::Condition->value === $capability) {
            $operator = $params['operator'] ?? 'not_empty';
            if (!in_array($operator, ['equals', 'contains', 'matches', 'not_empty'], true)) {
                $errors[] = "step[$i] has an unknown condition";
            }
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
