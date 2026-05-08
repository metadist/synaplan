<?php

declare(strict_types=1);

namespace App\Realtime\Channel;

/**
 * Per-user notification channel.
 *
 * Reserved for cross-cutting features like in-app notifications, presence,
 * push-to-browser alerts. Currently unused by Live Chat takeover but
 * declared up-front so the framework stays generic.
 *
 * Channel name format: `user:{userId}`.
 */
final readonly class UserChannel implements ChannelInterface
{
    public const NAMESPACE = 'user';

    public function __construct(
        public int $userId,
    ) {
    }

    public function name(): string
    {
        return sprintf('%s:%d', self::NAMESPACE, $this->userId);
    }

    public function namespace(): string
    {
        return self::NAMESPACE;
    }
}
