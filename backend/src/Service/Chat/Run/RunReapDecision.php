<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * Whether a run the pump owns should be closed.
 *
 * A queued run past its claim deadline never started. A running run whose
 * pump heartbeat is gone will not write another frame.
 */
final class RunReapDecision
{
    public static function shouldFail(string $state, int $deadline, int $now, bool $heartbeatAlive): bool
    {
        if ('queued' === $state) {
            return $now > $deadline;
        }
        if ('running' === $state) {
            return !$heartbeatAlive;
        }

        return false;
    }
}
