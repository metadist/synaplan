<?php

declare(strict_types=1);

namespace App\Message;

/**
 * A research run finished while its saved task was still running. Try the
 * hand-over again once the task has paused on the research step.
 */
final readonly class ContinueSavedTaskAfterResearch
{
    public function __construct(
        private string $runId,
        private int $attempt,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }
}
