<?php

declare(strict_types=1);

namespace App\Service\Observability;

use App\Observability\ServerIdentity;
use App\Service\Infrastructure\RedisService;

/**
 * Reads what every server's watchdog wrote to Redis (architecture update,
 * contracts §6 and §6.1): the live snapshot `capacity:server:{id}` and the
 * seen-list `capacity:seen`, which keeps a server on the page for an hour
 * after its snapshot expired. No request reads another server; Redis is the only
 * source, and one short request builds the whole report.
 *
 * @phpstan-type Pool array{total: int, busy: int}
 * @phpstan-type Server array{
 *     id: string, hostname: string, role: string, runtime: string, version: string,
 *     watchdog_version: string, reporting: bool, updated_at: string, last_seen_at: string,
 *     php_ready: bool, admin_ok: bool, streams: Pool, api: Pool,
 *     memory: array{rss_bytes: int, limit_bytes: int, limited: bool},
 *     cpu: array{cores: float, limited: bool, usage_ratio: float|null},
 *     place: array{host: string, address: string, pod: string, namespace: string, deployment: string, container: string, cpu_request: string, memory_limit: string},
 *     logs: array{collector: string, event_ring_host: string},
 *     threads: array{count: int, busy: int},
 *     peak_today: array{streams_busy: int, streams_total: int, at: string|null}
 * }
 */
final readonly class CapacityService
{
    private const SEEN_KEY = 'capacity:seen';
    private const SNAPSHOT_PREFIX = 'capacity:server:';
    private const LAST_PREFIX = 'capacity:last:';
    private const PEAK_PREFIX = 'capacity:peak:';

    /** A server whose last report is older than this is listed as not reporting. */
    private const REPORTING_WINDOW_SECONDS = 20;

    /**
     * A silent server stays on the page this long, then drops off. Matches
     * the watchdog's SeenTTL. The page applies it itself so a report written
     * under an older, longer TTL still disappears after an hour.
     */
    private const LISTED_WINDOW_SECONDS = 3600;

    public function __construct(
        private RedisService $redis,
        private ServerIdentity $identity,
    ) {
    }

    /**
     * @return array{served_by: string, generated_at: string, servers: list<Server>}
     *
     * @throws CapacityUnavailableException when Redis does not answer
     */
    public function report(): array
    {
        $seen = $this->redis->hGetAll(self::SEEN_KEY);
        if (null === $seen) {
            throw CapacityUnavailableException::redisDown();
        }

        $now = new \DateTimeImmutable();
        $servers = [];
        foreach ($seen as $id => $lastSeenAt) {
            // PHP turns a numeric hash field such as "42" into an integer key.
            $server = $this->server((string) $id, $lastSeenAt, $now);
            if (null !== $server) {
                $servers[] = $server;
            }
        }
        usort($servers, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return [
            'served_by' => $this->identity->id(),
            'generated_at' => $now->format(\DateTimeInterface::ATOM),
            'servers' => $servers,
        ];
    }

    /**
     * Null when neither the live snapshot nor the last report exists, or the
     * last report is older than an hour. The seen-list hash is refreshed by
     * every live server, so a dead server's field outlives its report and
     * the id alone would be a card with nothing on it.
     *
     * @return Server|null
     */
    private function server(string $id, string $lastSeenAt, \DateTimeImmutable $now): ?array
    {
        // The live snapshot expires after 20 seconds; the last report keeps a
        // silent server's role, machine and log source for an hour.
        $live = $this->redis->get(self::SNAPSHOT_PREFIX.$id);
        $raw = $live ?? $this->redis->get(self::LAST_PREFIX.$id);
        if (null === $raw) {
            return null;
        }
        $snapshot = $this->decode($raw);
        $updatedAt = $this->str($snapshot['updated_at'] ?? null) ?? $lastSeenAt;
        if (null === $live && !$this->reportedWithin($lastSeenAt, $now) && !$this->reportedWithin($updatedAt, $now)) {
            return null;
        }
        $reporting = null !== $live && $this->isFresh($updatedAt, $now);

        return [
            'id' => $id,
            'hostname' => $this->str($snapshot['hostname'] ?? null) ?? '',
            'role' => $this->str($snapshot['role'] ?? null) ?? 'web',
            'runtime' => $this->str($snapshot['runtime'] ?? null) ?? '',
            'version' => $this->str($snapshot['version'] ?? null) ?? '',
            'watchdog_version' => $this->str($snapshot['watchdog_version'] ?? null) ?? '',
            'reporting' => $reporting,
            'updated_at' => $updatedAt,
            'last_seen_at' => $lastSeenAt,
            'php_ready' => true === ($snapshot['php_ready'] ?? false),
            'admin_ok' => true === ($snapshot['admin_ok'] ?? false),
            'streams' => $this->pool($snapshot['streams'] ?? null),
            'api' => $this->pool($snapshot['api'] ?? null),
            'memory' => [
                'rss_bytes' => $this->int($snapshot['memory_rss_bytes'] ?? null),
                'limit_bytes' => $this->int($snapshot['memory_limit_bytes'] ?? null),
                'limited' => true === ($snapshot['memory_limited'] ?? false),
            ],
            'cpu' => [
                'cores' => $this->float($snapshot['cpu_cores'] ?? null) ?? 0.0,
                'limited' => true === ($snapshot['cpu_limited'] ?? false),
                'usage_ratio' => $this->float($snapshot['cpu_usage_ratio'] ?? null),
            ],
            'place' => $this->place(\is_array($snapshot['place'] ?? null) ? $snapshot['place'] : []),
            'logs' => [
                'collector' => $this->str($snapshot['logs']['collector'] ?? null) ?? '',
                'event_ring_host' => $this->str($snapshot['logs']['event_ring_host'] ?? null) ?? '',
            ],
            'threads' => [
                'count' => $this->int($snapshot['threads']['count'] ?? null),
                'busy' => $this->int($snapshot['threads']['busy'] ?? null),
            ],
            'peak_today' => $this->peakToday($id, $now),
        ];
    }

    /**
     * @return array{streams_busy: int, streams_total: int, at: string|null}
     */
    private function peakToday(string $id, \DateTimeImmutable $now): array
    {
        $raw = $this->redis->get(self::PEAK_PREFIX.$id.':'.$now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd'));
        $peak = null === $raw ? [] : $this->decode($raw);

        return [
            'streams_busy' => max(0, $this->int($peak['streams_busy_max'] ?? null)),
            'streams_total' => $this->int($peak['streams_total'] ?? null),
            'at' => $this->str($peak['at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $place
     *
     * @return array{host: string, address: string, pod: string, namespace: string, deployment: string, container: string, cpu_request: string, memory_limit: string}
     */
    private function place(array $place): array
    {
        return [
            'host' => $this->str($place['host'] ?? null) ?? '',
            'address' => $this->str($place['address'] ?? null) ?? '',
            'pod' => $this->str($place['pod'] ?? null) ?? '',
            'namespace' => $this->str($place['namespace'] ?? null) ?? '',
            'deployment' => $this->str($place['deployment'] ?? null) ?? '',
            'container' => $this->str($place['container'] ?? null) ?? '',
            'cpu_request' => $this->str($place['cpu_request'] ?? null) ?? '',
            'memory_limit' => $this->str($place['memory_limit'] ?? null) ?? '',
        ];
    }

    /**
     * @return Pool
     */
    private function pool(mixed $pool): array
    {
        if (!\is_array($pool)) {
            return ['total' => 0, 'busy' => 0];
        }

        return ['total' => $this->int($pool['total'] ?? null), 'busy' => $this->int($pool['busy'] ?? null)];
    }

    private function isFresh(string $updatedAt, \DateTimeImmutable $now): bool
    {
        $age = $this->ageSeconds($updatedAt, $now);

        return null !== $age && $age <= self::REPORTING_WINDOW_SECONDS;
    }

    private function reportedWithin(string $at, \DateTimeImmutable $now): bool
    {
        $age = $this->ageSeconds($at, $now);

        return null !== $age && $age <= self::LISTED_WINDOW_SECONDS;
    }

    private function ageSeconds(string $at, \DateTimeImmutable $now): ?int
    {
        try {
            $then = new \DateTimeImmutable($at);
        } catch (\Exception) {
            return null;
        }

        return $now->getTimestamp() - $then->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function str(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function int(mixed $value): int
    {
        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function float(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : (is_numeric($value) ? (float) $value : null);
    }
}
