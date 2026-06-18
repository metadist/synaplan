<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Parallel;

use App\Service\Multitask\Execution\NodeResult;

/**
 * A dispatched media node. {@see wait()} blocks until completion (or timeout) and
 * returns the node result — never throws (timeout/crash → NodeResult::failed).
 *
 * {@see cancel()} terminates the underlying work without waiting. Called by the
 * executor when the turn aborts (e.g. the streaming client disconnected) so
 * orphaned subprocesses don't keep burning provider credits. Idempotent and
 * never throws.
 */
interface MediaNodeJob
{
    public function wait(int $timeoutSeconds): NodeResult;

    public function cancel(): void;
}
