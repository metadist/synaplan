<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * The steps a research run takes in order.
 *
 * A round that still has open points queues another round. The fact-check
 * follows the report and comes before delivery.
 */
final class ResearchUnitPlan
{
    public const BRIEF_PLAN = 'brief_plan';
    public const ROUND = 'round';
    public const WRITE = 'write';
    public const CHECK = 'check';
    public const DELIVER = 'deliver';

    public static function known(string $unit): bool
    {
        return in_array($unit, [self::BRIEF_PLAN, self::ROUND, self::WRITE, self::CHECK, self::DELIVER], true);
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
            self::BRIEF_PLAN => match ($stageNext) {
                'gather' => self::ROUND,
                'write' => self::WRITE,
                default => null,
            },
            self::ROUND => match ($stageNext) {
                'write' => self::WRITE,
                'round' => self::ROUND,
                default => null,
            },
            self::WRITE => 'check' === $stageNext ? self::CHECK : null,
            self::CHECK => 'deliver' === $stageNext ? self::DELIVER : null,
            default => null,
        };
    }

    /**
     * Which step to run again after its lease was lost. A report that is
     * already there still needs its fact-check, unless that check was saved.
     */
    public static function resume(ResearchRunState $state): string
    {
        if (null !== $state->ledger->report()) {
            return null !== $state->ledger->check() ? self::DELIVER : self::CHECK;
        }

        return match ($state->status) {
            ResearchStatus::Gathering => self::ROUND,
            ResearchStatus::Writing => self::WRITE,
            ResearchStatus::Checking => self::CHECK,
            default => self::BRIEF_PLAN,
        };
    }
}
