<?php

declare(strict_types=1);

namespace App\Observability;

use App\Service\Infrastructure\RedisService;

/**
 * A bounded, redacted ring buffer of operational events in Redis.
 *
 * Purpose: give developers and the in-chat AI a way to see recent production
 * errors and notable operational events on demand, WITHOUT ever exposing raw
 * logs (which carry chat contents, user emails, RAG/document text and secrets).
 *
 * Design:
 *  - Events are assembled from a fixed ALLOW-LIST of structured fields, so
 *    "there is nothing from the user in it" is true by construction, not by
 *    hoping we stripped everything. The only free-text fields (message,
 *    exception_message) pass through {@see EventScrubber} first.
 *  - `user_id` is pseudonymous (resolvable to a person only via the
 *    access-controlled DB) and is the only quasi-identifier kept, for
 *    correlation.
 *  - Backed by a Redis sorted set scored by timestamp; capped in size with a
 *    short TTL. Volatile by design — this is a troubleshooting feed, not an
 *    audit log.
 *
 * @phpstan-type EventInput array{
 *     id?: string,
 *     ts?: int,
 *     level?: string,
 *     channel?: string,
 *     event?: string,
 *     message?: string|null,
 *     exception_class?: string|null,
 *     exception_message?: string|null,
 *     stack?: array<int, mixed>,
 *     request_id?: string|null,
 *     host?: string|null,
 *     route?: string|null,
 *     method?: string|null,
 *     status_code?: int|null,
 *     user_id?: int|null,
 *     worker?: string|null,
 *     provider?: string|null,
 *     model?: string|null,
 *     duration_ms?: int|null
 * }
 * @phpstan-type Event array{
 *     id: string,
 *     ts: int,
 *     level: string,
 *     channel: string,
 *     event: string,
 *     message: string|null,
 *     exception_class: string|null,
 *     exception_message: string|null,
 *     stack: list<string>,
 *     request_id: string|null,
 *     host: string|null,
 *     route: string|null,
 *     method: string|null,
 *     status_code: int|null,
 *     user_id: int|null,
 *     provider: string|null,
 *     model: string|null,
 *     worker: string|null,
 *     duration_ms: int|null
 * }
 */
final readonly class EventRingStore
{
    private const RING_KEY = 'observability:events';
    private const MAX_ENTRIES = 2000;
    private const TTL_SECONDS = 604800; // 7 days
    private const MAX_STACK_FRAMES = 15;

    /** Levels we accept, ordered by severity. */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function __construct(
        private RedisService $redis,
        private EventScrubber $scrubber,
    ) {
    }

    /**
     * Assemble, redact and append one event. Silently degrades when Redis is
     * unavailable — recording an event must never break the request it describes.
     *
     * @param EventInput $event
     */
    public function record(array $event): void
    {
        $normalized = $this->normalize($event);

        $encoded = json_encode($normalized, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            return;
        }

        if (!$this->redis->zAdd(self::RING_KEY, (float) $normalized['ts'], $encoded)) {
            return;
        }

        // Trim oldest entries down to the cap, then refresh the TTL.
        $overflow = $this->redis->zCard(self::RING_KEY) - self::MAX_ENTRIES;
        if ($overflow > 0) {
            $this->redis->zRemRangeByRank(self::RING_KEY, 0, $overflow - 1);
        }
        $this->redis->expire(self::RING_KEY, self::TTL_SECONDS);
    }

    /**
     * Most recent events first, optionally filtered.
     *
     * @return list<Event>
     */
    public function recent(
        ?string $level = null,
        ?int $sinceTs = null,
        ?string $query = null,
        ?string $requestId = null,
        int $limit = 50,
    ): array {
        $limit = max(1, min(self::MAX_ENTRIES, $limit));
        $hasFilter = null !== $level || null !== $query || null !== $requestId;

        // With filters we over-fetch and narrow in PHP; without, the limit is exact.
        $fetch = $hasFilter ? self::MAX_ENTRIES : $limit;
        $min = null === $sinceTs ? '-inf' : (string) $sinceTs;

        $raw = $this->redis->zRevRangeByScore(self::RING_KEY, '+inf', $min, $fetch);

        $out = [];
        foreach ($raw as $json) {
            $event = $this->decode($json);
            if (null === $event) {
                continue;
            }
            if (null !== $level && $event['level'] !== $level) {
                continue;
            }
            if (null !== $requestId && $event['request_id'] !== $requestId) {
                continue;
            }
            if (null !== $query && !$this->matchesQuery($event, $query)) {
                continue;
            }

            $out[] = $event;
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Aggregate counts over a window — the cheap "what's going on" view.
     *
     * @return array{
     *     window_start: int,
     *     total: int,
     *     by_level: array<string, int>,
     *     by_event: array<string, int>,
     *     by_route: array<string, int>,
     *     recent_errors: list<Event>
     * }
     */
    public function summary(int $sinceTs): array
    {
        $raw = $this->redis->zRevRangeByScore(self::RING_KEY, '+inf', (string) $sinceTs, self::MAX_ENTRIES);

        $byLevel = [];
        $byEvent = [];
        $byRoute = [];
        $recentErrors = [];
        $total = 0;

        foreach ($raw as $json) {
            $event = $this->decode($json);
            if (null === $event) {
                continue;
            }
            ++$total;
            $byLevel[$event['level']] = ($byLevel[$event['level']] ?? 0) + 1;
            $byEvent[$event['event']] = ($byEvent[$event['event']] ?? 0) + 1;
            if (null !== $event['route']) {
                $byRoute[$event['route']] = ($byRoute[$event['route']] ?? 0) + 1;
            }
            if (\in_array($event['level'], ['error', 'critical', 'alert', 'emergency'], true) && \count($recentErrors) < 10) {
                $recentErrors[] = $event;
            }
        }

        arsort($byLevel);
        arsort($byEvent);
        arsort($byRoute);

        return [
            'window_start' => $sinceTs,
            'total' => $total,
            'by_level' => $byLevel,
            'by_event' => $byEvent,
            'by_route' => $byRoute,
            'recent_errors' => $recentErrors,
        ];
    }

    /**
     * Drop everything (test/maintenance helper).
     */
    public function clear(): void
    {
        $this->redis->delete(self::RING_KEY);
    }

    /**
     * @param EventInput $event
     *
     * @return Event
     */
    private function normalize(array $event): array
    {
        $level = \is_string($event['level'] ?? null) && \in_array($event['level'], self::LEVELS, true)
            ? $event['level']
            : 'info';

        $stack = [];
        foreach (\array_slice($event['stack'] ?? [], 0, self::MAX_STACK_FRAMES) as $frame) {
            if (\is_string($frame)) {
                $stack[] = $frame;
            }
        }

        return [
            'id' => \is_string($event['id'] ?? null) && '' !== $event['id'] ? $event['id'] : bin2hex(random_bytes(8)),
            'ts' => \is_int($event['ts'] ?? null) && $event['ts'] > 0 ? $event['ts'] : time(),
            'level' => $level,
            'channel' => $this->str($event['channel'] ?? null) ?? 'app',
            'event' => $this->str($event['event'] ?? null) ?? 'log',
            'message' => $this->scrubber->scrub($this->str($event['message'] ?? null)),
            'exception_class' => $this->str($event['exception_class'] ?? null),
            'exception_message' => $this->scrubber->scrub($this->str($event['exception_message'] ?? null)),
            'stack' => $stack,
            'request_id' => $this->str($event['request_id'] ?? null),
            'host' => $this->str($event['host'] ?? null),
            'route' => $this->str($event['route'] ?? null),
            'method' => $this->str($event['method'] ?? null),
            'status_code' => $this->int($event['status_code'] ?? null),
            'user_id' => $this->int($event['user_id'] ?? null),
            'provider' => $this->str($event['provider'] ?? null),
            'model' => $this->str($event['model'] ?? null),
            'worker' => $this->str($event['worker'] ?? null),
            'duration_ms' => $this->int($event['duration_ms'] ?? null),
        ];
    }

    private function str(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function int(mixed $value): ?int
    {
        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    /**
     * @return Event|null
     */
    private function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return null;
        }

        // Re-run through the normalizer so a stored record always has the full
        // shape even if the schema grew since it was written.
        /* @var EventInput $decoded */
        return $this->normalize($decoded);
    }

    /**
     * @param Event $event
     */
    private function matchesQuery(array $event, string $query): bool
    {
        $needle = mb_strtolower($query);
        $haystack = mb_strtolower(implode(' ', [
            $event['event'],
            $event['message'] ?? '',
            $event['exception_class'] ?? '',
            $event['exception_message'] ?? '',
            $event['route'] ?? '',
            $event['provider'] ?? '',
            $event['model'] ?? '',
        ]));

        return str_contains($haystack, $needle);
    }
}
