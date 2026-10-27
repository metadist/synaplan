<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Terminal outcome of an {@see AiCall}.
 */
enum AiCallOutcome: string
{
    case Ok = 'ok';
    case Refused = 'refused';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
