<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

/**
 * Decides whether a scheduler slot is due. No I/O, so the rules can be tested
 * without a database.
 */
final readonly class SchedulerSlotPolicy
{
    public const RETRY_DELAY_SECONDS = 60;

    private const SECONDS_PER_DAY = 86400;

    /**
     * Due when the slot has never run, or at least $intervalSeconds have passed
     * since the last claim.
     */
    public function evaluateInterval(?int $lastStart, int $now, int $intervalSeconds): SchedulerSlotDecision
    {
        if ($intervalSeconds < 1) {
            throw new \InvalidArgumentException('Scheduler slot interval must be an integer greater than or equal to 1.');
        }

        if (null === $lastStart) {
            return SchedulerSlotDecision::due();
        }

        $elapsed = $now - $lastStart;
        if ($elapsed >= $intervalSeconds) {
            return SchedulerSlotDecision::due();
        }

        return SchedulerSlotDecision::waiting($intervalSeconds - $elapsed);
    }

    /**
     * $hour and $minute are UTC. Due when the slot has never run, or the last
     * claim is before the latest HH:MM that is already in the past (or now).
     * A fresh install therefore runs at once; after that, once per day.
     */
    public function evaluateAt(?int $lastStart, int $now, int $hour, int $minute): SchedulerSlotDecision
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new \InvalidArgumentException('Scheduler slot time must be a UTC hour 0-23 and minute 0-59.');
        }

        $slot = $this->mostRecentOccurrence($now, $hour, $minute);
        if (null === $lastStart || $lastStart < $slot) {
            return SchedulerSlotDecision::due();
        }

        return SchedulerSlotDecision::waiting(($slot + self::SECONDS_PER_DAY) - $now);
    }

    private function mostRecentOccurrence(int $now, int $hour, int $minute): int
    {
        $offset = ($hour * 3600) + ($minute * 60);
        $today = (intdiv($now, self::SECONDS_PER_DAY) * self::SECONDS_PER_DAY) + $offset;
        if ($today > $now) {
            return $today - self::SECONDS_PER_DAY;
        }

        return $today;
    }
}
