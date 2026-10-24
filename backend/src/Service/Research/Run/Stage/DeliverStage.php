<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchStatus;

/**
 * Closes the run with a sentence. Putting the report into the chat is a
 * later step; the report text stays on the run. A citation to evidence that
 * does not exist is removed here, so the closing numbers count real sources.
 */
final readonly class DeliverStage implements ResearchStage
{
    public function run(StageContext $ctx): StageResult
    {
        $ledger = $ctx->state->ledger;
        $grouped = $ledger->evidenceForWriter();
        $report = $ledger->report();
        if ([] === $grouped || null === $report) {
            return StageEnds::nothingFound($ctx);
        }

        $known = [];
        foreach ($grouped as $cards) {
            foreach ($cards as $card) {
                $known[] = (string) $card['id'];
            }
        }
        $cited = ReportCitations::clean($report, $known);
        $ledger->setReport($cited['report']);

        $open = 0;
        foreach ($ledger->points() as $point) {
            $status = [] !== ($grouped[$point->id] ?? []) ? PlanPoint::ANSWERED : PlanPoint::UNCONFIRMED;
            if (PlanPoint::UNCONFIRMED === $status) {
                ++$open;
            }
            $ledger->markPoint($point->id, $status);
            $ctx->events->append($ctx->state->ref->id, 'point', ['id' => $point->id, 'status' => $status]);
        }

        $outcome = $open > 0
            ? new ResearchOutcome(ResearchOutcome::COMPLETE_PARTIAL, [
                'open' => $open,
                'points' => count($ledger->points()),
            ])
            : new ResearchOutcome(ResearchOutcome::COMPLETE, [
                'statements' => $cited['statements'],
                'supported' => $cited['supported'],
            ]);

        return StageEnds::finish($ctx, ResearchStatus::Complete, $outcome, 'complete');
    }
}
