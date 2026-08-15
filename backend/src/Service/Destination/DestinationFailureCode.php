<?php

declare(strict_types=1);

namespace App\Service\Destination;

/**
 * Shared failure vocabulary. A new provider adds zero translation keys.
 */
enum DestinationFailureCode: string
{
    case Unauthorized = 'unauthorized';
    case NotFound = 'not_found';
    case QuotaExceeded = 'quota_exceeded';
    case TooLarge = 'too_large';
    case Unreachable = 'unreachable';
    case Conflict = 'conflict';
    case RateLimited = 'rate_limited';
}
