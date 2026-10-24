<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Stage\BriefStage;
use App\Service\Research\Run\Stage\DeliverStage;
use App\Service\Research\Run\Stage\GatherStage;
use App\Service\Research\Run\Stage\PlanStage;
use App\Service\Research\Run\Stage\ReadStage;
use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\StageResult;
use App\Service\Research\Run\Stage\WriteStage;

/**
 * Runs one research step. Brief and plan share a step, and so do search and
 * reading, so the queue carries four messages and not one per model call.
 */
final readonly class ResearchPipeline implements ResearchUnitRunner
{
    public function __construct(
        private BriefStage $brief,
        private PlanStage $plan,
        private GatherStage $gather,
        private ReadStage $read,
        private WriteStage $write,
        private DeliverStage $deliver,
    ) {
    }

    public function run(StageContext $ctx, string $unit): StageResult
    {
        return match ($unit) {
            ResearchUnitPlan::BRIEF_PLAN => $this->briefPlan($ctx),
            ResearchUnitPlan::ROUND => $this->round($ctx),
            ResearchUnitPlan::WRITE => $this->write->run($ctx),
            ResearchUnitPlan::DELIVER => $this->deliver->run($ctx),
            default => throw new \InvalidArgumentException('Unknown research unit.'),
        };
    }

    private function briefPlan(StageContext $ctx): StageResult
    {
        if (null === $ctx->state->ledger->brief()) {
            $opened = $this->brief->run($ctx);
            if ('plan' !== $opened->next || $ctx->state->status->isTerminal()) {
                return $opened;
            }
        }
        if ([] !== $ctx->state->ledger->points()) {
            return new StageResult('gather');
        }

        return $this->plan->run($ctx);
    }

    private function round(StageContext $ctx): StageResult
    {
        $gathered = $this->gather->run($ctx);
        if ('read' !== $gathered->next || $ctx->state->status->isTerminal()) {
            return $gathered;
        }

        return $this->read->run($ctx);
    }
}
