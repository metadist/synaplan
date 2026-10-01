<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

/**
 * Per-command status plus whether the tick lane looks alive.
 */
final readonly class ScheduledJobStatusReport
{
    /**
     * @param list<ScheduledJobStatus> $jobs
     */
    public function __construct(
        public string $state,
        public array $jobs,
    ) {
    }
}
