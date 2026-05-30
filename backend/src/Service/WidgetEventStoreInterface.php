<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Transport for real-time widget events (SSE backing store).
 *
 * The store must be shared across all web nodes, because the SSE stream for a
 * session can be held open on a different node than the one that handles the
 * POST publishing an event (round-robin load balancer). The current
 * implementation is {@see DatabaseWidgetEventStore} (Galera-replicated); a
 * Redis-backed implementation is planned to swap polling for push.
 *
 * Event shape returned by the getter methods:
 *   array{id: int, type: string, timestamp: int, payload: array<string, mixed>}
 */
interface WidgetEventStoreInterface
{
    /**
     * Publish an event to a session stream.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(string $widgetId, string $sessionId, string $type, array $payload): void;

    /**
     * Get events for a session newer than $lastEventId.
     *
     * @return list<array{id: int, type: string, timestamp: int, payload: array<string, mixed>}>
     */
    public function getNewEvents(string $widgetId, string $sessionId, int $lastEventId = 0): array;

    /**
     * Highest event id currently stored for a session (0 if none).
     */
    public function getLatestEventId(string $widgetId, string $sessionId): int;

    /**
     * Set the operator "is typing" indicator for a session (latest wins).
     */
    public function setTyping(string $widgetId, string $sessionId, int $operatorId): void;

    /**
     * Clear the operator typing indicator for a session.
     */
    public function clearTyping(string $widgetId, string $sessionId): void;

    /**
     * Current operator typing indicator, or null if none/expired.
     *
     * @return array{timestamp: int, operatorId: int}|null
     */
    public function getTyping(string $widgetId, string $sessionId): ?array;

    /**
     * Publish a notification for the widget owner.
     *
     * @param array<string, mixed> $payload
     */
    public function publishNotification(string $widgetId, array $payload): void;

    /**
     * Get new notifications for a widget.
     *
     * @return list<array{id: int, type: string, timestamp: int, payload: array<string, mixed>}>
     */
    public function getNewNotifications(string $widgetId, int $lastEventId = 0): array;
}
