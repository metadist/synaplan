<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Exception\UnauthorizedSubscriptionException;

/**
 * Dispatches a channel to the first registered authorizer that claims it.
 *
 * If NO authorizer supports the channel, subscription is denied. This
 * fail-closed default means adding a channel namespace without registering
 * an authorizer for it cannot accidentally expose data.
 */
final readonly class ChannelAuthorizerLocator
{
    /**
     * @param iterable<ChannelAuthorizerInterface> $authorizers
     */
    public function __construct(
        private iterable $authorizers,
    ) {
    }

    public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void
    {
        foreach ($this->authorizers as $authorizer) {
            if ($authorizer->supports($channel)) {
                $authorizer->authorize($channel, $subscriber);

                return;
            }
        }

        throw new UnauthorizedSubscriptionException(sprintf('No authorizer registered for channel "%s" — refusing subscription', $channel->name()));
    }
}
