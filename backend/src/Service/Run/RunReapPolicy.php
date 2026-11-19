<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * What the reaper may not decide alone for one kind of run: when a waiting
 * run has waited too long, which unit resumes it, and how it ends when its
 * unit was lost twice.
 */
interface RunReapPolicy
{
    /** The run waits for a person (an answer, an approval), not for a worker. */
    public function isWaiting(RunState $state): bool;

    /**
     * A waiting run, $ageSeconds after its last write. True when the policy
     * moved it on (continued or ended it); it takes the claim itself.
     */
    public function reapWait(RunState $state, int $ageSeconds): bool;

    /** The unit that picks up a run whose last unit was lost. */
    public function resumeUnit(RunState $state): string;

    /**
     * Called under the claim after the unit was lost twice: end the run as
     * interrupted, keeping what was done. False leaves it as it is.
     */
    public function interrupt(RunState $fresh, int $attempt): bool;

    /** The reaper ended the run: deliver it, so nobody waits for an answer that will not come. */
    public function closed(RunState $state): void;
}
