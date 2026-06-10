<?php

declare(strict_types=1);

namespace App\Realtime\Publisher;

use App\Realtime\Channel\ChannelInterface;

/**
 * Publishes a payload to a Centrifugo channel.
 *
 * Implementations MUST be safe to call from request-handling code (no long
 * blocking I/O on the hot path) and MUST NOT throw on transport failures —
 * they should swallow + log. Realtime is a best-effort UX enhancement on
 * top of the REST endpoints, which remain the source of truth.
 */
interface RealtimePublisherInterface
{
    /**
     * @param array<string, mixed> $payload arbitrary JSON-serialisable map; framework adds the standard envelope
     */
    public function publish(ChannelInterface $channel, string $eventType, array $payload): void;
}
