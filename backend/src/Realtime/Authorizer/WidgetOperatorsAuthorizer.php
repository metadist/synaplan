<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Channel\WidgetOperatorsChannel;
use App\Realtime\Exception\UnauthorizedSubscriptionException;
use App\Repository\WidgetRepository;

/**
 * Authorise subscriptions to {@see WidgetOperatorsChannel}.
 *
 * Only the widget owner may subscribe — the operator notifications
 * channel mirrors the access scope of the authenticated widget REST
 * endpoints, so the same ownership rule applies here.
 */
final readonly class WidgetOperatorsAuthorizer implements ChannelAuthorizerInterface
{
    public function __construct(
        private WidgetRepository $widgetRepository,
    ) {
    }

    public function supports(ChannelInterface $channel): bool
    {
        return $channel instanceof WidgetOperatorsChannel;
    }

    public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void
    {
        if (!$channel instanceof WidgetOperatorsChannel) {
            throw new UnauthorizedSubscriptionException('WidgetOperatorsAuthorizer received unexpected channel');
        }

        if (!$subscriber->isAuthenticatedUser()) {
            throw new UnauthorizedSubscriptionException('Operator channel requires authentication');
        }

        $widget = $this->widgetRepository->findByWidgetId($channel->widgetId);
        if (null === $widget) {
            throw new UnauthorizedSubscriptionException(sprintf('Widget "%s" not found', $channel->widgetId));
        }

        if ($widget->getOwnerId() !== $subscriber->user?->getId()) {
            throw new UnauthorizedSubscriptionException('Operator does not own this widget');
        }
    }
}
