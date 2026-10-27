<?php

declare(strict_types=1);

namespace App\Message;

/**
 * A research run that a saved task waits on ended or was deleted. Continue
 * that task on the worker, never inside the request that stopped the run.
 *
 * The saved-task link travels with the message, so a deleted run can still
 * fail the step it belonged to.
 */
final readonly class ContinueSavedTaskAfterResearch
{
    /**
     * @param array{path: string, type: string, local_path: string}|null $reportFile the report file, which the run state does not keep
     */
    public function __construct(
        private string $runId,
        private int $userId,
        private int $taskRunId,
        private string $nodeId,
        private int $attempt = 0,
        private ?array $reportFile = null,
        private bool $deleted = false,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function taskRunId(): int
    {
        return $this->taskRunId;
    }

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * @return array{path: string, type: string, local_path: string}|null
     */
    public function reportFile(): ?array
    {
        return $this->reportFile;
    }

    public function deleted(): bool
    {
        return $this->deleted;
    }

    public function nextAttempt(): self
    {
        return new self($this->runId, $this->userId, $this->taskRunId, $this->nodeId, $this->attempt + 1, $this->reportFile, $this->deleted);
    }
}
