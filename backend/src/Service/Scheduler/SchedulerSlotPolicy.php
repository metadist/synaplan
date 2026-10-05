<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

/**
 * Decides whether a scheduler slot is due. No I/O, so the rules can be tested
 * without a database.
 *
 * The stored value is the start time of the last claimed run, never a planned
 * next run, so it is only ahead of now through clock skew between nodes. One
 * more than a day ahead is treated as unreadable so the slot repairs itself
 * instead of staying silent until that date. Saved Task schedules keep their
 * own next-run time and are not evaluated here.
 */
final readonly class SchedulerSlotPolicy
{
    public const RETRY_DELAY_SECONDS = 60;

    /**
     * Two daily runs are never closer than this. Without it, an install whose
     * first claim lands shortly before the daily time (a fresh install, or the
     * first start after an upgrade) runs the daily jobs twice within minutes.
     */
    public const MIN_DAILY_GAP_SECONDS = 43200;

    private const SECONDS_PER_DAY = 86400;

    private const MAX_FUTURE_SECONDS = 86400;

    /**
     * Due when the slot has never run, or at least $intervalSeconds have passed
     * since the last claim.
     */
    public function evaluateInterval(?int $lastStart, int $now, int $intervalSeconds): SchedulerSlotDecision
    {
        if ($intervalSeconds < 1) {
            throw new \InvalidArgumentException('Scheduler slot interval must be an integer greater than or equal to 1.');
        }

        if (null === $lastStart || $this->isImplausible($lastStart, $now)) {
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
     * claim is before the latest HH:MM that is already in the past (or now)
     * and at least {@see MIN_DAILY_GAP_SECONDS} ago.
     */
    public function evaluateAt(?int $lastStart, int $now, int $hour, int $minute): SchedulerSlotDecision
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new \InvalidArgumentException('Scheduler slot time must be a UTC hour 0-23 and minute 0-59.');
        }

        if (null === $lastStart || $this->isImplausible($lastStart, $now)) {
            return SchedulerSlotDecision::due();
        }

        $slot = $this->mostRecentOccurrence($now, $hour, $minute);
        if ($lastStart < $slot && ($now - $lastStart) >= self::MIN_DAILY_GAP_SECONDS) {
            return SchedulerSlotDecision::due();
        }

        $next = $slot + self::SECONDS_PER_DAY;
        while (($next - $lastStart) < self::MIN_DAILY_GAP_SECONDS) {
            $next += self::SECONDS_PER_DAY;
        }

        return SchedulerSlotDecision::waiting($next - $now);
    }

    private function isImplausible(int $lastStart, int $now): bool
    {
        return $lastStart > $now + self::MAX_FUTURE_SECONDS;
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
