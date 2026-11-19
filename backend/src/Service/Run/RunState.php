<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * What the kernel needs to know about a run's stored state. The kind of run
 * owns everything else in it, and its own encoding.
 *
 * `expectedSeq` is the unit the run waits for; `checkpointSeq` the last unit
 * whose result was written. A unit may only write while it holds the claim
 * for `expectedSeq`.
 */
interface RunState
{
    public function runId(): string;

    public function expectedSeq(): int;

    public function checkpointSeq(): int;

    public function isTerminal(): bool;

    /** Unix seconds of the last write, or of the start before the first one. */
    public function updatedAt(): int;

    /**
     * The stored form after a unit was written: waiting for $expectedSeq, with
     * $checkpointSeq written at $now, kept until $expiresAt (null: no expiry).
     * Does not change this object.
     */
    public function stored(int $expectedSeq, int $checkpointSeq, int $now, ?int $expiresAt): string;

    /** The write went through: move this object to the stored form. */
    public function checkpointed(int $expectedSeq, int $checkpointSeq, int $now, ?int $expiresAt): void;
}
