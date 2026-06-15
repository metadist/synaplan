<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Realtime\Channel\ChannelInterface;

/**
 * Authorisation strategy for one Centrifugo namespace.
 *
 * Implementations are tagged with `app.realtime.authorizer` and dispatched
 * by {@see ChannelAuthorizerLocator} based on {@see self::supports()}.
 *
 * Authorizers MUST be side-effect-free (no DB writes, no event publishes)
 * because they may be invoked during token issuance for many channels at
 * once.
 */
interface ChannelAuthorizerInterface
{
    /**
     * Return true if this authorizer is responsible for the given channel.
     */
    public function supports(ChannelInterface $channel): bool;

    /**
     * Throws {@see \App\Realtime\Exception\UnauthorizedSubscriptionException}
     * when the subscriber is not allowed; otherwise returns silently.
     */
    public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void;
}
