<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Service\Multitask\Plan\TaskPlan;

/**
 * Outcome of a {@see TaskPlanner::plan()} call.
 *
 * Carries the plan plus enough metadata for shadow-mode persistence/logging:
 * whether we fell back to a safe single-`chat` plan, the model id used, the raw
 * model response, and any validation errors that triggered the fallback.
 */
final readonly class TaskPlanResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public TaskPlan $plan,
        public bool $fallback,
        public ?int $modelId = null,
        public string $rawResponse = '',
        public array $errors = [],
    ) {
    }
}
