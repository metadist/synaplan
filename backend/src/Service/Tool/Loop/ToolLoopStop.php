<?php

declare(strict_types=1);

namespace App\Service\Tool\Loop;

/**
 * Why an agent tool loop ended. `Iterations` and `Deadline` come from the
 * loop itself; every other reason is the adapter's.
 */
enum ToolLoopStop: string
{
    /** The model answered without asking for a Synaplan tool. */
    case Answered = 'answered';
    /** The turn holds calls only the client can run. */
    case ClientTools = 'client_tools';
    /** The upstream turn failed. */
    case Failed = 'failed';
    /** The adapter ended the run for a reason of its own wire. */
    case Halted = 'halted';
    case Iterations = 'iterations';
    case Deadline = 'deadline';

    public function isLimit(): bool
    {
        return self::Iterations === $this || self::Deadline === $this;
    }
}
