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
        $grouped = EvidenceWindow::cards($ledger, $ctx->roles->checkerSameFamily);
        $report = $ledger->report();
        if ([] === $ledger->evidenceForWriter() || null === $report || '' === trim($report)) {
            // A run the limit stopped with nothing writable says so, with
            // the limit named, instead of claiming nothing was there.
            if (null !== $ctx->state->limitHit) {
                return StageEnds::finish($ctx, ResearchStatus::Stopped, StageEnds::limitOutcome($ctx), 'stopped');
            }

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

        $limit = $ctx->state->limitHit;
        if (null !== $limit && $open > 0) {
            // The answer stands, and the sentence above it names the limit
            // that cut the gathering short. A limit that left no point
            // open did not hurt the answer and stays unmentioned.
            $outcome = new ResearchOutcome(ResearchOutcome::COMPLETE_AT_LIMIT, [
                'covered' => count($ledger->points()) - $open,
                'points' => count($ledger->points()),
                'open' => $open,
                'limit' => $limit,
                'depth' => $ctx->state->effectiveDepth()->value,
                'limit_value' => $ctx->state->budget->limitValue($limit),
            ]);
        } elseif ($open > 0) {
            $outcome = new ResearchOutcome(ResearchOutcome::COMPLETE_PARTIAL, [
                'open' => $open,
                'points' => count($ledger->points()),
            ]);
        } else {
            $check = $ledger->check();
            $outcome = new ResearchOutcome(ResearchOutcome::COMPLETE, [
                'statements' => null !== $check ? $check['statements'] : $cited['statements'],
                'supported' => null !== $check ? $check['supported'] : $cited['supported'],
            ]);
        }

        return StageEnds::finish($ctx, ResearchStatus::Complete, $outcome, 'complete');
    }
}
