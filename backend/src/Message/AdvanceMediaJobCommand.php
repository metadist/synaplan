<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Advance one {@see \App\Service\Media\MediaJob} by exactly ONE non-blocking
 * step (submit, a single poll, or finalize) and — while the job is still
 * active — re-dispatch itself with a short delay for the next step.
 *
 * Carrying only the opaque job key (not a snapshot) is deliberate: the handler
 * always re-loads the job from Redis so it advances the latest state, never a
 * stale copy that another worker or the reaper may have already moved on.
 *
 * Queued in: async_index
 * Handled by: {@see \App\MessageHandler\AdvanceMediaJobCommandHandler}
 */
final readonly class AdvanceMediaJobCommand
{
    public function __construct(
        private string $jobKey,
    ) {
    }

    public function getJobKey(): string
    {
        return $this->jobKey;
    }
}
