<?php

declare(strict_types=1);

namespace App\Realtime\Exception;

/**
 * Marker base for all exceptions thrown from the realtime framework.
 *
 * Catching this in a controller means "the realtime subsystem misbehaved" —
 * every realtime feature is built as an enhancement on top of the REST
 * endpoints, so callers should log + degrade rather than fail the request.
 */
class RealtimeException extends \RuntimeException
{
}
