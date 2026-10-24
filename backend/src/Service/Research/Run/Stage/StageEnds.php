<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchStatus;

/** Ends a run with one of the closed sentences, and records that ending. */
final class StageEnds
{
    public static function beforeCall(StageContext $ctx): ?StageResult
    {
        if ($ctx->stop->requested()) {
            return self::finish($ctx, ResearchStatus::Stopped, new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER), 'stopped');
        }
        if ($ctx->state->budget->canCall()) {
            return null;
        }

        $points = count($ctx->state->ledger->points());

        return self::finish($ctx, ResearchStatus::Stopped, new ResearchOutcome(ResearchOutcome::STOPPED_LIMIT, [
            'covered' => self::covered($ctx),
            'points' => $points,
        ]), 'stopped');
    }

    public static function model(StageContext $ctx, string $role): StageResult
    {
        return self::finish($ctx, ResearchStatus::Failed, new ResearchOutcome(ResearchOutcome::FAILED_MODEL_ERROR, [
            'role' => $role,
        ]), 'failed');
    }

    public static function roleUnavailable(StageContext $ctx, string $role): StageResult
    {
        return self::finish($ctx, ResearchStatus::Failed, new ResearchOutcome(ResearchOutcome::FAILED_ROLE_UNAVAILABLE, [
            'role' => $role,
        ]), 'failed');
    }

    public static function noSources(StageContext $ctx): StageResult
    {
        return self::finish($ctx, ResearchStatus::Failed, new ResearchOutcome(ResearchOutcome::FAILED_NO_SOURCES), 'failed');
    }

    public static function nothingFound(StageContext $ctx): StageResult
    {
        return self::finish($ctx, ResearchStatus::Failed, new ResearchOutcome(ResearchOutcome::FAILED_NOTHING_FOUND), 'failed');
    }

    public static function finish(StageContext $ctx, ResearchStatus $status, ResearchOutcome $outcome, string $event): StageResult
    {
        $ctx->state->finish($status, $outcome);
        $ctx->events->append($ctx->state->ref->id, $event, ['outcome' => $outcome->toArray()]);

        return new StageResult('done');
    }

    private static function covered(StageContext $ctx): int
    {
        $found = $ctx->state->ledger->evidenceForWriter();
        $covered = 0;
        foreach ($ctx->state->ledger->points() as $point) {
            if ([] !== ($found[$point->id] ?? [])) {
                ++$covered;
            }
        }

        return $covered;
    }
}
