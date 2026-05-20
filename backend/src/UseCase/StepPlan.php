<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * Runtime step plan produced by the planner (Release D).
 */
final readonly class StepPlan
{
    /**
     * @param list<PlannedStep> $steps
     */
    public function __construct(
        public string $primaryUseCaseId,
        public array $steps,
        public bool $isCompound,
    ) {
    }

    public function isMultiStep(): bool
    {
        return count($this->steps) > 1;
    }

    /**
     * @return array{primary_use_case_id: string, is_compound: bool, steps: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'primary_use_case_id' => $this->primaryUseCaseId,
            'is_compound' => $this->isCompound,
            'steps' => array_map(static fn (PlannedStep $step): array => $step->toArray(), $this->steps),
        ];
    }
}
