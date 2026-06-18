<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Channel\WidgetTypingChannel;
use App\Realtime\Exception\UnauthorizedSubscriptionException;

/**
 * Authorise subscriptions to {@see WidgetTypingChannel}.
 *
 * Mirrors {@see WidgetSessionAuthorizer} exactly via the shared
 * {@see WidgetSessionAccessGuard}. Keeping the two channels in lockstep
 * is a hard security requirement: typing frames are published from the
 * browser, so a subscriber must hold the SAME proof of ownership they
 * would need for the durable session channel — otherwise a stranger
 * could subscribe-then-publish typing noise into another visitor's
 * conversation.
 *
 * Centrifugo additionally enforces (via the namespace config) that
 * publishing is only allowed for current subscribers, so passing this
 * authorizer is the single trust boundary for both subscribe AND publish.
 */
final readonly class WidgetTypingAuthorizer implements ChannelAuthorizerInterface
{
    public function __construct(
        private WidgetSessionAccessGuard $guard,
    ) {
    }

    public function supports(ChannelInterface $channel): bool
    {
        return $channel instanceof WidgetTypingChannel;
    }

    public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void
    {
        if (!$channel instanceof WidgetTypingChannel) {
            throw new UnauthorizedSubscriptionException('WidgetTypingAuthorizer received unexpected channel');
        }

        $this->guard->ensureCanAccess($channel->widgetId, $channel->sessionId, $subscriber);
    }
}
