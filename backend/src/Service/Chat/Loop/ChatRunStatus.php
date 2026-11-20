<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Run;

/**
 * Where a chat run stands. The values are the `BRUNS.BSTATUS` values.
 */
enum ChatRunStatus: string
{
    case Running = Run::STATUS_RUNNING;
    case Waiting = Run::STATUS_WAITING;
    case Completed = Run::STATUS_COMPLETED;
    case Stopped = Run::STATUS_STOPPED;
    case BudgetUsed = Run::STATUS_BUDGET_USED;
    case Interrupted = Run::STATUS_INTERRUPTED;
    case Failed = Run::STATUS_FAILED;

    public function isTerminal(): bool
    {
        return \in_array($this->value, Run::TERMINAL, true);
    }
}
