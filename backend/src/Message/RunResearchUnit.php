<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One bounded step of a research run.
 *
 * The worker claims it, runs it, checkpoints, and returns. The following
 * step is a new message, so a run never holds a worker while it waits.
 */
final readonly class RunResearchUnit
{
    public function __construct(
        private string $runId,
        private string $unit,
        private int $seq,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function seq(): int
    {
        return $this->seq;
    }
}
