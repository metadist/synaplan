<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Channel\WidgetSessionChannel;
use App\Realtime\Exception\UnauthorizedSubscriptionException;

/**
 * Authorise subscriptions to {@see WidgetSessionChannel}.
 *
 * Two valid subscriber profiles:
 *
 *   1. Anonymous visitor — proves possession of the (widgetId, sessionId)
 *      pair via the existing widget endpoints. The session id is a UUID
 *      that is only known to the original creator.
 *   2. Authenticated operator — must own the widget (mirrors the same
 *      check used by {@see \App\Controller\WidgetSessionController}).
 *
 * The actual rule lives in {@see WidgetSessionAccessGuard} so the matching
 * typing-channel authorizer cannot drift out of lockstep — both must apply
 * the EXACT same access policy, otherwise one channel could widen access
 * compared to the other.
 */
final readonly class WidgetSessionAuthorizer implements ChannelAuthorizerInterface
{
    public function __construct(
        private WidgetSessionAccessGuard $guard,
    ) {
    }

    public function supports(ChannelInterface $channel): bool
    {
        return $channel instanceof WidgetSessionChannel;
    }

    public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void
    {
        if (!$channel instanceof WidgetSessionChannel) {
            throw new UnauthorizedSubscriptionException('WidgetSessionAuthorizer received unexpected channel');
        }

        $this->guard->ensureCanAccess($channel->widgetId, $channel->sessionId, $subscriber);
    }
}
