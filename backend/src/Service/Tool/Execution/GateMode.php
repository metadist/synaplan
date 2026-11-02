<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

/**
 * How this caller consults the approval gate today.
 *
 * AU25 removes these three values and the invoker always inspects. Until
 * then each caller passes the decision it already made, so a refactor does
 * not ask anyone who was not asked before.
 */
enum GateMode
{
    /** Document edits, MCP reads in the fetch runner, and the server's own tools. */
    case AsTodayNever;

    /** Gateway loops and MCP actions: the gate runs only while approvals are on. */
    case AsTodayWhenApprovalsOn;

    /** Saved-task tool calls and file work: inspect runs even while approvals are off. */
    case AsTodayAlways;
}
