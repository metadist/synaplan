<?php

declare(strict_types=1);

namespace App\Realtime\Channel;

/**
 * Global broadcast channel.
 *
 * Used for system-wide announcements (maintenance windows, deploy notices,
 * forced-refresh signals). Anonymous subscribers are allowed because the
 * payload is non-sensitive by definition.
 *
 * Channel name format: `system:{topic}` (e.g. `system:broadcast`).
 */
final readonly class SystemBroadcastChannel implements ChannelInterface
{
    public const NAMESPACE = 'system';

    public function __construct(
        public string $topic = 'broadcast',
    ) {
    }

    public function name(): string
    {
        return sprintf('%s:%s', self::NAMESPACE, $this->topic);
    }

    public function namespace(): string
    {
        return self::NAMESPACE;
    }
}
