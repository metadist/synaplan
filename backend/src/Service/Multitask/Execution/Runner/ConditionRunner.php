<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\SavedTask\Graph\StepInputResolver;

/**
 * Authored condition step. Hidden from the planner. On false the node
 * {@see NodeResult::stopped()} so dependents skip and the run completes.
 */
final readonly class ConditionRunner implements TaskRunner
{
    public function __construct(
        private StepInputResolver $inputs,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::Condition];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::Condition,
                'Stop later steps when a value does not match.',
                available: static fn (): bool => false,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $params = $node->params;
        $rawInputs = is_array($params['inputs'] ?? null) ? $params['inputs'] : $node->inputs;
        $resolved = $this->inputs->resolveAll($rawInputs, $context);
        $value = $resolved['input'] ?? $this->inputs->resolve($params['input'] ?? null, $context);
        $operator = is_string($params['operator'] ?? null) ? $params['operator'] : 'not_empty';
        $expected = $params['value'] ?? $resolved['value'] ?? null;

        $ok = $this->matches($value, $operator, $expected);
        if ($ok) {
            $text = is_scalar($value) ? (string) $value : 'Condition matched';

            return NodeResult::ok('' === $text ? 'Condition matched' : $text, [], [
                'condition' => true,
                'operator' => $operator,
            ]);
        }

        return NodeResult::stopped('Condition was not met');
    }

    private function matches(mixed $value, string $operator, mixed $expected): bool
    {
        $left = $this->stringify($value);
        $right = $this->stringify($expected);

        return match ($operator) {
            'equals' => $left === $right,
            'contains' => '' !== $right && str_contains($left, $right),
            'matches' => '' !== $right && 1 === @preg_match($this->asPattern($right), $left),
            default => '' !== trim($left),
        };
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    private function asPattern(string $value): string
    {
        if (str_starts_with($value, '/') && 1 === preg_match('/^\/.*\/[imsxuADSUXJ]*$/', $value)) {
            return $value;
        }

        return '/'.str_replace('/', '\/', $value).'/u';
    }
}
