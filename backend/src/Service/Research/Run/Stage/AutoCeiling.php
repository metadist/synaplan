<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchBudgetStore;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchRequest;
use App\Service\Research\Run\ResearchRunState;

/**
 * How far an automatic run goes, decided once from its plan.
 *
 * Up to two points, or a request that asked for a short look, is a quick
 * run; anything else keeps the standard row. "Look deeper" never shrinks.
 * The budget is lowered in place, so the lead mode, the stop rules and the
 * minutes named in the chat all read the same ceiling.
 */
final readonly class AutoCeiling
{
    /** A plan this small is answered with the quick row. */
    public const QUICK_POINTS = 2;

    public function __construct(private ?ResearchBudgetStore $budgets = null)
    {
    }

    /**
     * How many points the plan may have for this run. A short look asked
     * for by the person is one or two points, whatever the row says.
     *
     * @return array{0: int, 1: int} min and max
     */
    public function pointsRange(ResearchRunState $state, int $min, int $max): array
    {
        if (ResearchDepth::Auto === $state->ref->depth && ResearchRequest::HINT_BRIEF === $state->hint) {
            return [1, min($max, self::QUICK_POINTS)];
        }

        return [$min, $max];
    }

    /**
     * Size the run after its plan. Returns the ceiling that was applied, or
     * null for a run whose depth was chosen by hand.
     */
    public function apply(ResearchRunState $state, int $points): ?ResearchDepth
    {
        if (ResearchDepth::Auto !== $state->ref->depth) {
            return null;
        }
        $ceiling = $this->choose($state, $points);
        if (ResearchDepth::Quick === $ceiling) {
            $state->budget->shrinkTo($this->quickRow());
        }
        $state->ceiling = $ceiling;

        return $ceiling;
    }

    private function choose(ResearchRunState $state, int $points): ResearchDepth
    {
        if (ResearchRequest::HINT_DEEPER === $state->hint) {
            return ResearchDepth::Standard;
        }
        if (ResearchRequest::HINT_BRIEF === $state->hint || $points <= self::QUICK_POINTS) {
            return ResearchDepth::Quick;
        }

        return ResearchDepth::Standard;
    }

    private function quickRow(): ResearchBudgetConfig
    {
        $row = $this->budgets?->forDepth(ResearchDepth::Quick) ?? ResearchBudgetConfig::defaults(ResearchDepth::Quick);

        return $row->withoutQuestions();
    }
}
