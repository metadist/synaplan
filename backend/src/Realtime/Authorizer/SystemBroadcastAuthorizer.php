<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Channel\SystemBroadcastChannel;

/**
 * Authorise subscriptions to {@see SystemBroadcastChannel}.
 *
 * Anyone (anonymous or authenticated) may subscribe — the publisher side is
 * what's locked down. Payloads MUST stay non-sensitive (e.g. "maintenance
 * starts in 5 minutes").
 */
final class SystemBroadcastAuthorizer implements ChannelAuthorizerInterface
{
    public function supports(ChannelInterface $channel): bool
    {
        return $channel instanceof SystemBroadcastChannel;
    }

    public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void
    {
        // Public channel — accept everyone, including unauthenticated.
    }
}
