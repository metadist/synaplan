<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

use Predis\Client;
use Psr\Log\LoggerInterface;

/**
 * Application-facing wrapper around the shared Redis instance.
 *
 * Redis is the canonical platform service for cross-node state in Synaplan:
 *
 *   * Symfony cache (`framework.cache.app` and per-feature pools)
 *   * Symfony lock (`framework.lock`)
 *   * Symfony rate-limiter (`cache.rate_limiter`)
 *   * Centrifugo realtime engine (separate logical DB)
 *   * Future: Symfony Messenger transport, sessions, presence counters
 *
 * Symfony's cache/lock/rate-limiter components talk to Redis directly through
 * their built-in adapters — they do NOT route through this wrapper. Use this
 * service from feature code (counters, idempotency keys, ad-hoc pub/sub,
 * presence) where Symfony's higher-level abstractions don't fit.
 *
 * Key convention: every key written through this service is prefixed with
 * `synaplan:{env}:` to give us a single namespace we can flush/inspect
 * without colliding with Symfony's own keys (which carry their own prefix).
 *
 * NOTE: Controllers MUST NOT depend on this service directly. Wrap usage in
 * a feature-specific service (e.g. `IdempotencyService`, `PresenceService`)
 * to keep business logic out of the controller layer (see AGENTS-DEV).
 */
final class RedisService
{
    private readonly string $keyPrefix;
    private ?Client $client = null;
    private ?\Throwable $connectionFailure = null;

    public function __construct(
        private readonly string $redisDsn,
        string $environment,
        private readonly LoggerInterface $logger,
    ) {
        $this->keyPrefix = sprintf('synaplan:%s:', $environment);
    }

    /**
     * Lazily build a Predis client. We avoid eager construction so a missing
     * Redis at boot does not crash the whole container — features that
     * don't need Redis still work, and features that do can degrade
     * gracefully via {@see self::isAvailable()}.
     */
    private function client(): ?Client
    {
        if (null !== $this->client) {
            return $this->client;
        }

        if ('' === trim($this->redisDsn)) {
            $this->connectionFailure = new \RuntimeException('REDIS_DSN is empty');

            return null;
        }

        try {
            $this->client = new Client($this->redisDsn, [
                'parameters' => [
                    'read_write_timeout' => 2.5,
                    'timeout' => 2.5,
                ],
            ]);

            return $this->client;
        } catch (\Throwable $e) {
            $this->connectionFailure = $e;
            $this->logger->warning('Redis client initialisation failed', [
                'dsn_redacted' => $this->redactDsn($this->redisDsn),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cheap probe used by health-checks. Returns false (not throws) when the
     * server is unreachable so callers can short-circuit gracefully.
     */
    public function ping(): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            $response = $client->ping();

            // Predis returns a Status object whose payload is "PONG".
            return 'PONG' === (string) $response;
        } catch (\Throwable $e) {
            // Predis throws a Predis\ClientException (or various transport
            // exceptions) on a dead server — collapse them all to "false"
            // so callers can degrade gracefully without leaking the
            // implementation type.
            $this->connectionFailure = $e;

            return false;
        }
    }

    public function isAvailable(): bool
    {
        return $this->ping();
    }

    public function get(string $key): ?string
    {
        $client = $this->client();
        if (null === $client) {
            return null;
        }

        try {
            $value = $client->get($this->prefix($key));

            return is_string($value) ? $value : null;
        } catch (\Throwable $e) {
            $this->logCommandFailure('GET', $key, $e);

            return null;
        }
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            if (null === $ttlSeconds) {
                $client->set($this->prefix($key), $value);
            } else {
                $client->set($this->prefix($key), $value, 'EX', $ttlSeconds);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logCommandFailure('SET', $key, $e);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            $client->del([$this->prefix($key)]);

            return true;
        } catch (\Throwable $e) {
            $this->logCommandFailure('DEL', $key, $e);

            return false;
        }
    }

    public function exists(string $key): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            return 1 === (int) $client->exists($this->prefix($key));
        } catch (\Throwable $e) {
            $this->logCommandFailure('EXISTS', $key, $e);

            return false;
        }
    }

    public function increment(string $key, int $by = 1): ?int
    {
        $client = $this->client();
        if (null === $client) {
            return null;
        }

        try {
            return (int) $client->incrby($this->prefix($key), $by);
        } catch (\Throwable $e) {
            $this->logCommandFailure('INCRBY', $key, $e);

            return null;
        }
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            return 1 === (int) $client->expire($this->prefix($key), $ttlSeconds);
        } catch (\Throwable $e) {
            $this->logCommandFailure('EXPIRE', $key, $e);

            return false;
        }
    }

    /**
     * Best-effort fire-and-forget pub/sub for INTERNAL workers.
     *
     * For browser-facing realtime events use Centrifugo via
     * {@see \App\Realtime\Publisher\RealtimePublisherInterface} — never
     * publish raw Redis messages to the WS layer.
     *
     * Returns the number of subscribers reached, or null on failure.
     */
    public function publish(string $channel, string $payload): ?int
    {
        $client = $this->client();
        if (null === $client) {
            return null;
        }

        try {
            return (int) $client->publish($this->prefix('pubsub:'.$channel), $payload);
        } catch (\Throwable $e) {
            $this->logCommandFailure('PUBLISH', $channel, $e);

            return null;
        }
    }

    /**
     * Redis server version from `INFO server`, or null when unavailable.
     *
     * Uses executeRaw so we don't depend on Predis' INFO section parsing —
     * the raw reply is a single bulk string we grep for `redis_version:`.
     */
    public function serverVersion(): ?string
    {
        $client = $this->client();
        if (null === $client) {
            return null;
        }

        try {
            $raw = $client->executeRaw(['INFO', 'server']);
            if (is_string($raw) && 1 === preg_match('/^redis_version:(\S+)/m', $raw, $matches)) {
                return $matches[1];
            }

            return null;
        } catch (\Throwable $e) {
            $this->logCommandFailure('INFO', 'server', $e);

            return null;
        }
    }

    /**
     * Add/update a member in a sorted set. Used for time-ordered indexes such
     * as the "active media jobs" set scored by heartbeat, which the reaper
     * scans for stale entries.
     */
    public function zAdd(string $key, float $score, string $member): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            $client->zadd($this->prefix($key), [$member => $score]);

            return true;
        } catch (\Throwable $e) {
            $this->logCommandFailure('ZADD', $key, $e);

            return false;
        }
    }

    public function zRem(string $key, string $member): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            $client->zrem($this->prefix($key), $member);

            return true;
        } catch (\Throwable $e) {
            $this->logCommandFailure('ZREM', $key, $e);

            return false;
        }
    }

    /**
     * Range query by score. `$min`/`$max` accept Redis range syntax
     * ('-inf', '+inf', '(123' for exclusive). Returns the matching members.
     *
     * @return list<string>
     */
    public function zRangeByScore(string $key, string $min, string $max, ?int $limit = null): array
    {
        $client = $this->client();
        if (null === $client) {
            return [];
        }

        try {
            $options = null === $limit ? [] : ['limit' => [0, $limit]];
            /** @var array<int, mixed> $members */
            $members = $client->zrangebyscore($this->prefix($key), $min, $max, $options);

            return array_values(array_map(static fn ($m): string => (string) $m, $members));
        } catch (\Throwable $e) {
            $this->logCommandFailure('ZRANGEBYSCORE', $key, $e);

            return [];
        }
    }

    public function sAdd(string $key, string $member): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            $client->sadd($this->prefix($key), [$member]);

            return true;
        } catch (\Throwable $e) {
            $this->logCommandFailure('SADD', $key, $e);

            return false;
        }
    }

    public function sRem(string $key, string $member): bool
    {
        $client = $this->client();
        if (null === $client) {
            return false;
        }

        try {
            $client->srem($this->prefix($key), $member);

            return true;
        } catch (\Throwable $e) {
            $this->logCommandFailure('SREM', $key, $e);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function sMembers(string $key): array
    {
        $client = $this->client();
        if (null === $client) {
            return [];
        }

        try {
            /** @var array<int, mixed> $members */
            $members = $client->smembers($this->prefix($key));

            return array_values(array_map(static fn ($m): string => (string) $m, $members));
        } catch (\Throwable $e) {
            $this->logCommandFailure('SMEMBERS', $key, $e);

            return [];
        }
    }

    public function getLastConnectionError(): ?\Throwable
    {
        return $this->connectionFailure;
    }

    private function prefix(string $key): string
    {
        return $this->keyPrefix.$key;
    }

    private function logCommandFailure(string $command, string $key, \Throwable $e): void
    {
        $this->logger->warning('Redis command failed', [
            'command' => $command,
            'key' => $key,
            'error' => $e->getMessage(),
        ]);
    }

    private function redactDsn(string $dsn): string
    {
        // Strip `redis://user:password@host` -> `redis://***@host`
        return (string) preg_replace('#://[^@]*@#', '://***@', $dsn);
    }
}
