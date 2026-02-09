<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache-based widget event service.
 *
 * Replaces database-persisted events with ephemeral cache entries.
 * Events auto-expire after 10 minutes (sufficient for SSE reconnects).
 *
 * Event types:
 * - takeover: Operator takes over session
 * - handback: Operator hands back to AI
 * - message: Operator sends message
 * - typing: Operator is typing (short TTL)
 * - notification: Admin notification
 */
final class WidgetEventCacheService
{
    private const EVENT_TTL = 600; // 10 minutes for regular events
    private const TYPING_TTL = 5;  // 5 seconds for typing indicators

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'cache.widget_events')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Publish an event to a session.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(string $widgetId, string $sessionId, string $type, array $payload): void
    {
        $cacheKey = $this->getEventsCacheKey($widgetId, $sessionId);

        // Get existing events or empty array
        $item = $this->cache->getItem($cacheKey);
        $events = $item->isHit() ? $item->get() : [];

        // Add new event with microsecond timestamp as ID
        $eventId = (int) (microtime(true) * 1000000);
        $events[] = [
            'id' => $eventId,
            'type' => $type,
            'timestamp' => time(),
            'payload' => $payload,
        ];

        // Keep only events from last 10 minutes (cleanup old entries)
        $cutoff = time() - self::EVENT_TTL;
        $events = array_filter($events, fn ($e) => $e['timestamp'] >= $cutoff);
        $events = array_values($events); // Re-index

        // Save back to cache
        $item->set($events);
        $item->expiresAfter(self::EVENT_TTL);
        $this->cache->save($item);
    }

    /**
     * Set typing indicator for a session.
     */
    public function setTyping(string $widgetId, string $sessionId, int $operatorId): void
    {
        $cacheKey = $this->getTypingCacheKey($widgetId, $sessionId);

        $item = $this->cache->getItem($cacheKey);
        $item->set([
            'timestamp' => time(),
            'operatorId' => $operatorId,
        ]);
        $item->expiresAfter(self::TYPING_TTL);
        $this->cache->save($item);
    }

    /**
     * Clear typing indicator for a session.
     */
    public function clearTyping(string $widgetId, string $sessionId): void
    {
        $cacheKey = $this->getTypingCacheKey($widgetId, $sessionId);
        $this->cache->deleteItem($cacheKey);
    }

    /**
     * Get typing indicator for a session.
     *
     * @return array{timestamp: int, operatorId: int}|null
     */
    public function getTyping(string $widgetId, string $sessionId): ?array
    {
        $cacheKey = $this->getTypingCacheKey($widgetId, $sessionId);
        $item = $this->cache->getItem($cacheKey);

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Get new events for a session since a given event ID.
     *
     * @return array<array{id: int, type: string, timestamp: int, payload: array<string, mixed>}>
     */
    public function getNewEvents(string $widgetId, string $sessionId, int $lastEventId = 0): array
    {
        $cacheKey = $this->getEventsCacheKey($widgetId, $sessionId);
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            return [];
        }

        $events = $item->get();

        // Filter events newer than lastEventId
        return array_values(array_filter(
            $events,
            fn ($e) => $e['id'] > $lastEventId
        ));
    }

    /**
     * Publish notification for widget owner.
     *
     * @param array<string, mixed> $payload
     */
    public function publishNotification(string $widgetId, array $payload): void
    {
        $this->publish($widgetId, 'notifications', 'notification', $payload);
    }

    /**
     * Get new notifications for a widget.
     *
     * @return array<array{id: int, type: string, timestamp: int, payload: array<string, mixed>}>
     */
    public function getNewNotifications(string $widgetId, int $lastEventId = 0): array
    {
        return $this->getNewEvents($widgetId, 'notifications', $lastEventId);
    }

    private function getEventsCacheKey(string $widgetId, string $sessionId): string
    {
        // Replace special characters to create valid cache key
        $widgetId = str_replace(['-', '.'], '_', $widgetId);
        $sessionId = str_replace(['-', '.'], '_', $sessionId);

        return sprintf('widget_events_%s_%s', $widgetId, $sessionId);
    }

    private function getTypingCacheKey(string $widgetId, string $sessionId): string
    {
        $widgetId = str_replace(['-', '.'], '_', $widgetId);
        $sessionId = str_replace(['-', '.'], '_', $sessionId);

        return sprintf('widget_typing_%s_%s', $widgetId, $sessionId);
    }
}
