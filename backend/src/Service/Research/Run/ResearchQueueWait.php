<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use Psr\Clock\ClockInterface;

/**
 * How long a run's next step has been waiting for a worker. It is read from
 * the state on every request, not recorded, so it is as fresh as the
 * client's poll and leaves the event log alone.
 */
final readonly class ResearchQueueWait
{
    /** A shorter gap is the normal pause between two steps. */
    public const AFTER_SECONDS = 20;

    /** Long enough that the workers are down, not busy. */
    public const QUIET_SECONDS = 300;

    public function __construct(
        private ResearchStateStore $store,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int|null seconds the step has waited, or null when nothing is waiting for a worker
     */
    public function since(ResearchRunState $state): ?int
    {
        if ($state->status->isTerminal() || ResearchReply::isWaiting($state)) {
            return null;
        }
        $since = $this->clock->now()->getTimestamp() - ($state->updatedAt ?? $state->ref->createdAt)->getTimestamp();
        if ($since < self::AFTER_SECONDS || $this->store->isClaimed($state->ref->id, $state->expectedSeq)) {
            return null;
        }

        return $since;
    }

    public function quiet(ResearchRunState $state): bool
    {
        return ($this->since($state) ?? 0) >= self::QUIET_SECONDS;
    }
}
