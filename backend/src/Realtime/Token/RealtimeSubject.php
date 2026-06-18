<?php

declare(strict_types=1);

namespace App\Realtime\Token;

use App\Realtime\Authorizer\SubscriberContext;

/**
 * Single source of truth for the JWT `sub` claim.
 *
 * Centrifugo enforces that the `sub` of a subscription token matches the
 * `sub` of the connection token — otherwise it rejects the subscribe with
 * `bad subscription token`. Connection-token issuance and subscription-token
 * issuance therefore MUST derive the subject through the same code path;
 * a drift between the two is a latent bug where the WS connects fine and
 * then silently fails to subscribe to any channel.
 *
 *   * operator → `user:{id}`
 *   * visitor  → `widget:{widgetId}:{sessionId}`
 */
final readonly class RealtimeSubject
{
    public static function forOperator(int $userId): string
    {
        return sprintf('user:%d', $userId);
    }

    public static function forVisitor(string $widgetId, string $sessionId): string
    {
        return sprintf('widget:%s:%s', $widgetId, $sessionId);
    }

    public static function forSubscriber(SubscriberContext $subscriber): string
    {
        if ($subscriber->isAuthenticatedUser()) {
            return self::forOperator((int) $subscriber->user?->getId());
        }

        $widgetId = isset($subscriber->extra['widgetId']) && is_string($subscriber->extra['widgetId'])
            ? $subscriber->extra['widgetId']
            : null;
        $sessionId = $subscriber->visitorId;

        if (null === $widgetId || null === $sessionId) {
            // Defence-in-depth: the authorizer should already have rejected
            // a subscriber that doesn't carry both fields. If we get here
            // anyway, fall back to a value that will never match a real
            // connection token's sub so Centrifugo refuses the subscribe.
            return 'widget:invalid';
        }

        return self::forVisitor($widgetId, $sessionId);
    }
}
