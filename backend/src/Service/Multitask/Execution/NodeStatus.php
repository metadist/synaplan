<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

/**
 * Lifecycle state of a single DAG node during execution.
 *
 * Mirrors the BMESSAGE_TASKS.BSTATUS vocabulary so executor state maps 1:1 to
 * persisted state.
 */
enum NodeStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    /** A dependency failed, so this node could not run. */
    case Skipped = 'skipped';
}
