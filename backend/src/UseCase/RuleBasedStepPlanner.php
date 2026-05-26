<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * @deprecated use ClassificationStepPlanner — kept as DI alias for backward compatibility
 */
final readonly class RuleBasedStepPlanner
{
    public function __construct(
        private ClassificationStepPlanner $planner,
    ) {
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function plan(string $messageText, array $classification): StepPlan
    {
        return $this->planner->plan($messageText, $classification);
    }
}
