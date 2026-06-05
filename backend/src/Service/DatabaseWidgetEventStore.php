<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WidgetEvent;
use App\Repository\WidgetEventRepository;

/**
 * Database-backed (Galera-replicated) widget event store.
 *
 * Replaces the previous node-local filesystem cache, which was invisible across
 * the cluster and silently dropped concurrent events via a read-modify-write of
 * a single cache entry. Here every publish is an atomic INSERT and every node
 * reads the same shared table, so SSE delivers reliably regardless of which
 * node terminates the stream or handles the POST.
 *
 * Notifications reuse the same table under the reserved session id
 * {@see self::NOTIFICATION_SESSION}.
 */
final readonly class DatabaseWidgetEventStore implements WidgetEventStoreInterface
{
    /** Reserved session id for the widget-owner notification stream. */
    private const NOTIFICATION_SESSION = 'notifications';

    /** TTL for regular events (matches the old 10-minute reconnect window). */
    private const EVENT_TTL = 600;

    /** TTL for the ephemeral operator typing indicator. */
    private const TYPING_TTL = 6;

    /** Inverse probability of an opportunistic expired-row purge per publish. */
    private const PURGE_EVERY = 50;

    public function __construct(
        private WidgetEventRepository $events,
    ) {
    }

    public function publish(string $widgetId, string $sessionId, string $type, array $payload): void
    {
        $now = time();
        $this->events->add(new WidgetEvent($widgetId, $sessionId, $type, $payload, $now + self::EVENT_TTL));
        $this->maybePurge($now);
    }

    public function getNewEvents(string $widgetId, string $sessionId, int $lastEventId = 0, int $graceSeconds = 0): array
    {
        $now = time();
        $graceCutoff = $graceSeconds > 0 ? $now - $graceSeconds : 0;
        $events = $this->events->findStreamEventsSince($widgetId, $sessionId, $lastEventId, $now, $graceCutoff);

        return array_map(static fn (WidgetEvent $e): array => [
            'id' => (int) $e->getId(),
            'type' => $e->getType(),
            'timestamp' => $e->getCreated(),
            'payload' => $e->getPayload(),
        ], $events);
    }

    public function getLatestEventId(string $widgetId, string $sessionId): int
    {
        return $this->events->maxStreamEventId($widgetId, $sessionId);
    }

    public function setTyping(string $widgetId, string $sessionId, int $operatorId): void
    {
        $now = time();
        // Latest-wins: drop any previous indicator so only the newest row exists.
        $this->events->deleteOperatorTyping($widgetId, $sessionId);
        $this->events->add(new WidgetEvent(
            $widgetId,
            $sessionId,
            WidgetEventRepository::OPERATOR_TYPING_TYPE,
            ['timestamp' => $now, 'operatorId' => $operatorId],
            $now + self::TYPING_TTL,
        ));
    }

    public function clearTyping(string $widgetId, string $sessionId): void
    {
        $this->events->deleteOperatorTyping($widgetId, $sessionId);
    }

    public function getTyping(string $widgetId, string $sessionId): ?array
    {
        $event = $this->events->findLatestOperatorTyping($widgetId, $sessionId, time());
        if (null === $event) {
            return null;
        }

        $payload = $event->getPayload();

        return [
            'timestamp' => (int) ($payload['timestamp'] ?? $event->getCreated()),
            'operatorId' => (int) ($payload['operatorId'] ?? 0),
        ];
    }

    public function publishNotification(string $widgetId, array $payload): void
    {
        $this->publish($widgetId, self::NOTIFICATION_SESSION, 'notification', $payload);
    }

    public function getNewNotifications(string $widgetId, int $lastEventId = 0): array
    {
        return $this->getNewEvents($widgetId, self::NOTIFICATION_SESSION, $lastEventId);
    }

    private function maybePurge(int $now): void
    {
        if (1 === random_int(1, self::PURGE_EVERY)) {
            $this->events->deleteExpired($now);
        }
    }
}
