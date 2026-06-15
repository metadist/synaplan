<?php

declare(strict_types=1);

namespace App\Service;

use App\Realtime\Channel\WidgetOperatorsChannel;
use App\Realtime\Channel\WidgetSessionChannel;
use App\Realtime\Publisher\RealtimePublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Single fan-out point for widget realtime events.
 *
 * Every call site (HumanTakeoverService, WidgetPublicController,
 * WidgetSessionController, …) depends on this one service so the
 * transport behind it can be swapped without touching feature code.
 * Today we publish exclusively via Centrifugo through the injected
 * {@see RealtimePublisherInterface}.
 *
 * The publisher contract requires implementations to swallow transport
 * errors (so a flaky gateway cannot fail a chat reply). The defensive
 * try/catch in every method here is a belt-and-suspenders guard against
 * an exception accidentally escaping that contract.
 */
final readonly class WidgetRealtimeBroadcaster
{
    public function __construct(
        private RealtimePublisherInterface $publisher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Publish a session-scoped event (visible to widget visitor + the operator
     * looking at this exact session in the dashboard).
     *
     * @param array<string, mixed> $payload
     */
    public function publishSessionEvent(string $widgetId, string $sessionId, string $type, array $payload): void
    {
        try {
            $this->publisher->publish(
                new WidgetSessionChannel($widgetId, $sessionId),
                $type,
                $payload,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('WidgetRealtimeBroadcaster publishSessionEvent failed', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // `publishTyping()` and `clearTyping()` were removed when typing
    // indicators moved off the durable session channel onto the dedicated
    // `widgettyping:*` Centrifugo channel that browsers publish to
    // directly. Backend services no longer originate typing frames; if a
    // future feature needs to do so, prefer publishing on the typing
    // namespace via {@see RealtimePublisherInterface} so the channel
    // separation (history vs. ephemeral) is preserved.

    /**
     * Notify a widget owner about activity that requires their attention.
     *
     * Replaces the 3-second polling loop in the LiveSupportView — when an
     * operator subscribes to `widget:operators.{widgetId}`, this fires
     * immediately on every relevant event.
     *
     * @param array<string, mixed> $payload
     */
    public function publishOperatorNotification(string $widgetId, array $payload): void
    {
        try {
            $this->publisher->publish(
                new WidgetOperatorsChannel($widgetId),
                'notification',
                $payload,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('WidgetRealtimeBroadcaster publishOperatorNotification failed', [
                'widget_id' => $widgetId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
