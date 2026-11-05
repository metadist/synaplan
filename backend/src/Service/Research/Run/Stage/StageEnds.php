<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchStatus;

/**
 * Ends a run with one of the closed sentences, and records that ending.
 *
 * A spent depth limit is not one of them while the run can still answer:
 * it ends the gathering, never the answer. With evidence on the table the
 * run goes to the report and the limit travels with it, so the closing
 * sentence names the limit that stopped the search. Only a run with
 * nothing to write from ends here, and then it says what was found.
 */
final class StageEnds
{
    public static function beforeCall(StageContext $ctx): ?StageResult
    {
        if ($ctx->stop->requested()) {
            return self::finish($ctx, ResearchStatus::Stopped, new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER), 'stopped');
        }
        $writing = self::writeNow($ctx);
        if ($writing instanceof StageResult) {
            return $writing;
        }
        if ($ctx->state->budget->canCall()) {
            return null;
        }
        $ctx->state->noteLimitHit();
        if (self::hasReportMaterial($ctx)) {
            $ctx->state->advance(ResearchStatus::Writing);

            return new StageResult('write');
        }

        return self::finish($ctx, ResearchStatus::Stopped, self::limitOutcome($ctx), 'stopped');
    }

    public static function noSources(StageContext $ctx): StageResult
    {
        return self::finish($ctx, ResearchStatus::Failed, new ResearchOutcome(ResearchOutcome::FAILED_NO_SOURCES), 'failed');
    }

    public static function nothingFound(StageContext $ctx): StageResult
    {
        return self::finish($ctx, ResearchStatus::Failed, new ResearchOutcome(ResearchOutcome::FAILED_NOTHING_FOUND), 'failed');
    }

    /**
     * The honest end of a run the limit stopped before an answer existed:
     * which limit, for which depth, and how far the search had got. The
     * count is the points the lead marked answered, the same number the
     * person watches while the run works.
     */
    public static function limitOutcome(StageContext $ctx): ResearchOutcome
    {
        $ctx->state->noteLimitHit();
        $limit = $ctx->state->limitHit ?? 'limit';
        $snapshot = $ctx->state->budget->toArray();

        return new ResearchOutcome(ResearchOutcome::STOPPED_LIMIT, [
            'covered' => self::answered($ctx),
            'points' => count($ctx->state->ledger->points()),
            'limit' => $limit,
            'depth' => $ctx->state->effectiveDepth()->value,
            'limit_value' => $ctx->state->budget->limitValue($limit),
            'searches' => $snapshot['searches'][0],
            'sources' => $snapshot['sources_read'][0],
        ]);
    }

    /** Write now: the report uses what was already found, and this step does not search. */
    public static function writeNow(StageContext $ctx): ?StageResult
    {
        $status = $ctx->state->status;
        if (!$ctx->writeNow->requested() || $status->isTerminal() || ResearchStatus::Writing === $status || ResearchStatus::Checking === $status) {
            return null;
        }
        $ctx->state->advance(ResearchStatus::Writing);

        return new StageResult('write');
    }

    public static function finish(StageContext $ctx, ResearchStatus $status, ResearchOutcome $outcome, string $event): StageResult
    {
        $ctx->state->finish($status, $outcome);
        $ctx->events->append($ctx->state->ref->id, $event, ['outcome' => $outcome->toArray()]);

        return new StageResult('done');
    }

    /** What a report could be written from: kept evidence, or the report a follow-up revises. */
    private static function hasReportMaterial(StageContext $ctx): bool
    {
        $prior = $ctx->state->priorReport;

        return [] !== $ctx->state->ledger->evidenceForWriter() || (null !== $prior && '' !== trim($prior));
    }

    private static function answered(StageContext $ctx): int
    {
        $answered = 0;
        foreach ($ctx->state->ledger->points() as $point) {
            if (PlanPoint::ANSWERED === $point->status) {
                ++$answered;
            }
        }

        return $answered;
    }
}
