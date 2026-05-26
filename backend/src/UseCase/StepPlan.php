<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * An ordered execution plan for a multi-step (compound) request.
 *
 * Wraps a list of PlannedStep instances with convenience accessors.
 * The orchestrator iterates these in order, feeding each step's output
 * as context into the next step's input.
 */
final readonly class StepPlan
{
    /**
     * @param list<PlannedStep> $steps      Ordered list of execution steps
     * @param string            $source     Where this plan originated from (setfit, catalog, llm)
     * @param float             $confidence Classification confidence that produced this plan
     */
    public function __construct(
        public array $steps,
        public string $source = 'unknown',
        public float $confidence = 0.0,
    ) {
    }

    public function isCompound(): bool
    {
        return count($this->steps) > 1;
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }

    public function firstStep(): ?PlannedStep
    {
        return $this->steps[0] ?? null;
    }

    /**
     * Create a single-step plan (normal, non-compound request).
     */
    public static function single(string $capability, string $source = 'classifier', float $confidence = 1.0): self
    {
        return new self(
            steps: [new PlannedStep(id: 'step_1', capability: $capability)],
            source: $source,
            confidence: $confidence,
        );
    }

    /**
     * Build from the external router's steps array.
     *
     * @param list<array> $routerSteps
     */
    public static function fromRouterResponse(array $routerSteps, string $source = 'setfit', float $confidence = 0.0): self
    {
        $steps = array_map(
            static fn (array $s) => PlannedStep::fromRouterResponse($s),
            $routerSteps,
        );

        return new self(steps: $steps, source: $source, confidence: $confidence);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (PlannedStep $step) => $step->toArray(),
            $this->steps,
        );
    }
}
