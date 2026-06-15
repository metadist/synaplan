<?php

declare(strict_types=1);

namespace App\Realtime\Exception;

/**
 * Thrown when a channel name from the client is malformed or refers to a
 * namespace the framework doesn't know about. Always maps to HTTP 400.
 */
final class InvalidChannelException extends RealtimeException
{
}
