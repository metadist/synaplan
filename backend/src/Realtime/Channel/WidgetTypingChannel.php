<?php

declare(strict_types=1);

namespace App\Realtime\Channel;

/**
 * Ephemeral channel that carries typing indicators for a single widget chat
 * session.
 *
 * Why a dedicated namespace instead of reusing {@see WidgetSessionChannel}:
 *
 *   * Typing frames are high-frequency, ephemeral, and do not need history
 *     replay or recovery on reconnect — so the namespace is configured with
 *     `history_size: 0`. Mixing them with persisted message events on the
 *     `widget` namespace would force us to either drop history for messages
 *     or pay the storage cost for noise.
 *   * Typing is the ONLY event type both peers (visitor + operator) are
 *     allowed to publish directly from the browser. Keeping it on its own
 *     namespace lets Centrifugo enforce `allow_publish_for_subscriber: true`
 *     here without weakening the rule on `widget:*`, where browser publishes
 *     would be a privilege-escalation vector (a visitor could otherwise
 *     impersonate an operator message).
 *
 * Channel name format: `widgettyping:{widgetId}.{sessionId}`. Authorisation
 * mirrors {@see WidgetSessionChannel} exactly (same widget+session trust
 * checks via {@see \App\Realtime\Authorizer\WidgetTypingAuthorizer}).
 *
 * NB: the namespace is `widgettyping` (no hyphen) on purpose — Centrifugo
 * v6 accepts hyphens in namespace names per the docs but in practice
 * rejected `widget-typing:*` channels with `unknown channel`. Sticking
 * with `[a-z]+` keeps us in the boring, well-trodden subset.
 */
final readonly class WidgetTypingChannel implements ChannelInterface
{
    public const NAMESPACE = 'widgettyping';

    public function __construct(
        public string $widgetId,
        public string $sessionId,
    ) {
    }

    public function name(): string
    {
        return sprintf('%s:%s.%s', self::NAMESPACE, $this->widgetId, $this->sessionId);
    }

    public function namespace(): string
    {
        return self::NAMESPACE;
    }
}
