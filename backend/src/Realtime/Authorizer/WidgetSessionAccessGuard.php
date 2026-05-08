<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Realtime\Exception\UnauthorizedSubscriptionException;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;

/**
 * Shared trust check for everything scoped to a single widget chat session.
 *
 * Both {@see WidgetSessionAuthorizer} (durable session events) and
 * {@see WidgetTypingAuthorizer} (ephemeral typing frames) need the exact
 * same access rules:
 *
 *   * The widget exists.
 *   * The session exists for that widget.
 *   * The subscriber is either the widget owner OR an anonymous visitor
 *     whose visitorId matches the sessionId.
 *
 * Centralising the rule prevents the two authorizers from drifting apart
 * (a subtle drift would let one channel widen access compared to the other,
 * which is a privilege-escalation bug). Any future tweak — IP allow-list,
 * role checks, etc. — happens once, here.
 *
 * The guard is intentionally side-effect-free: no DB writes, no event
 * publishes, only repository reads. It is safe to call from token issuance
 * even when many channels are authorised in a tight loop.
 */
final readonly class WidgetSessionAccessGuard
{
    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetSessionRepository $sessionRepository,
    ) {
    }

    /**
     * @throws UnauthorizedSubscriptionException when the subscriber is not
     *                                           allowed to participate in
     *                                           `(widgetId, sessionId)`
     */
    public function ensureCanAccess(string $widgetId, string $sessionId, SubscriberContext $subscriber): void
    {
        $widget = $this->widgetRepository->findByWidgetId($widgetId);
        if (null === $widget) {
            throw new UnauthorizedSubscriptionException(sprintf('Widget "%s" not found', $widgetId));
        }

        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);
        if (null === $session) {
            throw new UnauthorizedSubscriptionException(sprintf('Session "%s" not found', $sessionId));
        }

        if ($subscriber->isAuthenticatedUser()) {
            $userId = $subscriber->user?->getId();
            if ($userId === $widget->getOwnerId()) {
                return;
            }
            throw new UnauthorizedSubscriptionException('Operator does not own this widget');
        }

        if ($subscriber->isAnonymousVisitor()) {
            // The visitorId IS the sessionId — possession is the bearer
            // because the upstream token controller only accepts a
            // sessionId after looking it up in the widget session store.
            if ($subscriber->visitorId === $sessionId) {
                return;
            }
            throw new UnauthorizedSubscriptionException('Visitor session id mismatch');
        }

        throw new UnauthorizedSubscriptionException('Subscriber is not eligible for widget session channel');
    }
}
