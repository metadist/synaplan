<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Parallel;

use App\Service\Multitask\Execution\NodeResult;

/**
 * A dispatched media node. {@see wait()} blocks until completion (or timeout) and
 * returns the node result — never throws (timeout/crash → NodeResult::failed).
 */
interface MediaNodeJob
{
    public function wait(int $timeoutSeconds): NodeResult;
}
