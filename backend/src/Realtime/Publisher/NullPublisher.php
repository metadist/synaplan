<?php

declare(strict_types=1);

namespace App\Realtime\Publisher;

use App\Realtime\Channel\ChannelInterface;

/**
 * No-op publisher used in test environments and when realtime is disabled.
 *
 * Tests should bind this implementation explicitly via the test container so
 * a missing Centrifugo never makes assertions flaky.
 */
final class NullPublisher implements RealtimePublisherInterface
{
    public function publish(ChannelInterface $channel, string $eventType, array $payload): void
    {
        // intentionally empty
    }
}
