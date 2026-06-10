<?php

declare(strict_types=1);

namespace App\Realtime\Channel;

/**
 * Channel for a single widget chat session.
 *
 * Subscribers: the visitor running the embedded widget AND the operator(s)
 * viewing this session in the dashboard. Publishers: backend services only
 * (visitor messages, AI tokens, operator replies, takeover/handback events,
 * typing indicators).
 *
 * Channel name format: `widget:session.{widgetId}.{sessionId}`.
 */
final readonly class WidgetSessionChannel implements ChannelInterface
{
    public const NAMESPACE = 'widget';

    public function __construct(
        public string $widgetId,
        public string $sessionId,
    ) {
    }

    public function name(): string
    {
        // Centrifugo namespace separator is ':', so we use '.' inside the
        // identifier to keep parsing trivial on both ends.
        return sprintf('%s:session.%s.%s', self::NAMESPACE, $this->widgetId, $this->sessionId);
    }

    public function namespace(): string
    {
        return self::NAMESPACE;
    }
}
