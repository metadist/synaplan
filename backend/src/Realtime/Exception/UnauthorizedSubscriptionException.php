<?php

declare(strict_types=1);

namespace App\Realtime\Exception;

/**
 * Thrown when a user/visitor is not allowed to subscribe to a given channel.
 * Always maps to HTTP 403.
 */
final class UnauthorizedSubscriptionException extends RealtimeException
{
}
