<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

enum ToolOutcomeKind: string
{
    case Done = 'done';
    case Refused = 'refused';
    case NeedsApproval = 'needs_approval';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
}
