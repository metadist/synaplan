<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * The part of a unit that belongs to one kind of run. The kernel claims the
 * unit, checks that it is still due, writes the checkpoint and queues the
 * next one; the handler does the work in between.
 */
interface RunUnitHandler
{
    public function knows(string $unit): bool;

    /** How long the claim of this unit lasts; a live unit renews it. */
    public function leaseMs(RunState $state, string $unit): int;

    /**
     * Do the unit's work on $state. It may make paid calls; it asks
     * $claim->cancelled() before each one.
     *
     * @throws RunNotFound when the run disappeared meanwhile
     */
    public function run(RunState $state, string $unit, RunClaim $claim): RunUnitResult;

    /** The run was asked to stop before this unit began: end it as stopped. */
    public function cancel(RunState $state): RunUnitResult;

    /** After the checkpoint: deliver a finished run, clean up. */
    public function after(RunState $state, string $unit, RunUnitResult $result): void;
}
