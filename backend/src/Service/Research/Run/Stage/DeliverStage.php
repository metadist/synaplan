<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchStatus;

/**
 * Closes the run with a sentence. Putting the report into the chat is a
 * later step; the report text stays on the run.
 */
final readonly class DeliverStage implements ResearchStage
{
    public function run(StageContext $ctx): StageResult
    {
        $ledger = $ctx->state->ledger;
        $grouped = $ledger->evidenceForWriter();
        $cards = 0;
        $open = 0;
        foreach ($ledger->points() as $point) {
            $found = $grouped[$point->id] ?? [];
            $cards += count($found);
            if ([] === $found) {
                ++$open;
            }
        }

        if (0 === $cards || null === $ledger->report()) {
            return StageEnds::noSources($ctx);
        }
        foreach ($ledger->points() as $point) {
            if ([] !== ($grouped[$point->id] ?? [])) {
                $ledger->markPoint($point->id, PlanPoint::ANSWERED);
            }
        }

        $total = count($ledger->points());
        if ($open > 0) {
            $outcome = new ResearchOutcome(ResearchOutcome::COMPLETE_PARTIAL, [
                'open' => $open,
                'points' => $total,
            ]);
        } else {
            $outcome = new ResearchOutcome(ResearchOutcome::COMPLETE, [
                'statements' => $cards,
                'supported' => $cards,
            ]);
        }
        $ctx->state->finish(ResearchStatus::Complete, $outcome);
        $ctx->events->append($ctx->state->ref->id, 'complete', [
            'code' => $outcome->code,
            'text' => $outcome->text(),
        ]);

        return new StageResult('done');
    }
}
