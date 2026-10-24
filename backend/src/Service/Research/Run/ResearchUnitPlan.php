<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * The four steps a research run takes in order.
 *
 * A round that still has open points queues another round. A fact-check
 * is a later step, so the report still follows the last round.
 */
final class ResearchUnitPlan
{
    public const BRIEF_PLAN = 'brief_plan';
    public const ROUND = 'round';
    public const WRITE = 'write';
    public const DELIVER = 'deliver';

    public static function known(string $unit): bool
    {
        return in_array($unit, [self::BRIEF_PLAN, self::ROUND, self::WRITE, self::DELIVER], true);
    }

    /**
     * The step that follows a finished one. Waiting and a finished run
     * stay where they are; nobody is dispatched to poll them.
     */
    public static function nextUnit(string $finished, string $stageNext): ?string
    {
        if ('done' === $stageNext || str_starts_with($stageNext, 'wait_')) {
            return null;
        }

        return match ($finished) {
            self::BRIEF_PLAN => 'gather' === $stageNext ? self::ROUND : null,
            self::ROUND => match ($stageNext) {
                'write' => self::WRITE,
                'round' => self::ROUND,
                default => null,
            },
            self::WRITE => 'deliver' === $stageNext ? self::DELIVER : null,
            default => null,
        };
    }

    /**
     * Which step to run again after its lease was lost. The stored status
     * is the last checkpoint, so a report that is already there means the
     * writer finished and only the closing step is left.
     */
    public static function resume(ResearchRunState $state): string
    {
        if (ResearchStatus::Writing === $state->status && null !== $state->ledger->report()) {
            return self::DELIVER;
        }

        return match ($state->status) {
            ResearchStatus::Gathering => self::ROUND,
            ResearchStatus::Writing, ResearchStatus::Checking => self::WRITE,
            default => self::BRIEF_PLAN,
        };
    }
}
