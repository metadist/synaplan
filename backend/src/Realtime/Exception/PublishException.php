<?php

declare(strict_types=1);

namespace App\Realtime\Exception;

/**
 * Thrown by {@see \App\Realtime\Publisher\RealtimePublisherInterface} when a
 * publish call fails (network error, 4xx/5xx). Callers should log and
 * swallow this — realtime is a UX enhancement on top of the REST API and
 * MUST NOT take down the originating request (e.g. a chat reply).
 *
 * The bundled {@see \App\Realtime\Publisher\CentrifugoPublisher} swallows
 * its own errors and never reaches this exception type; subclasses that
 * need stricter semantics can throw it explicitly.
 */
final class PublishException extends RealtimeException
{
}
