<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Policy when an {@see AiCallHookInterface} throws or exceeds its declared budget
 * in {@see AiCallHookInterface::before()}.
 */
enum HookFailure: string
{
    case Open = 'open';
    case Closed = 'closed';
}
