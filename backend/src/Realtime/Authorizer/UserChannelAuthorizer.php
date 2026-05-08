<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Exception\UnauthorizedSubscriptionException;

/**
 * Authorise subscriptions to {@see UserChannel}.
 *
 * Only the matching authenticated user can subscribe to their own channel.
 * Admins are NOT given a backdoor here — if we ever need cross-user
 * presence in the dashboard, that should live on its own channel under
 * the `admin:` namespace with a dedicated authorizer.
 */
final class UserChannelAuthorizer implements ChannelAuthorizerInterface
{
    public function supports(ChannelInterface $channel): bool
    {
        return $channel instanceof UserChannel;
    }

    public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void
    {
        if (!$channel instanceof UserChannel) {
            throw new UnauthorizedSubscriptionException('UserChannelAuthorizer received unexpected channel');
        }

        if (!$subscriber->isAuthenticatedUser()) {
            throw new UnauthorizedSubscriptionException('User channel requires authentication');
        }

        if ($subscriber->user?->getId() !== $channel->userId) {
            throw new UnauthorizedSubscriptionException('User mismatch on user channel');
        }
    }
}
