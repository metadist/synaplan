<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One unit of a chat run: the tool calls of the last model answer, then one
 * model call. The worker claims it, runs it, checkpoints and returns.
 */
final readonly class RunChatUnit
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
