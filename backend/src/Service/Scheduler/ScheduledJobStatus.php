<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

final readonly class ScheduledJobStatus
{
    public function __construct(
        public string $command,
        public string $lane,
        public ?int $lastStartedAt,
        public ?int $lastFinishedAt,
        public ?int $lastExitCode,
        public ?string $host,
    ) {
    }
}
