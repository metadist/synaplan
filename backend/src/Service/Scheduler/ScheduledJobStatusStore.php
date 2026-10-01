<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use App\Service\Infrastructure\RedisService;

/**
 * Last start, finish, exit code and host for one scheduled command.
 *
 * Passed to {@see RedisService} as `scheduler:job:{command}`. That service
 * prefixes `synaplan:{env}:`, so the key in Redis is
 * `synaplan:{env}:scheduler:job:{command}`. The value is JSON and expires
 * after 8 days, refreshed on every write.
 */
final readonly class ScheduledJobStatusStore
{
    public const KEY_PREFIX = 'scheduler:job:';
    public const TTL_SECONDS = 8 * 86400;

    public function __construct(
        private RedisService $redis,
    ) {
    }

    public static function key(string $command): string
    {
        return self::KEY_PREFIX.$command;
    }

    public function ensureAvailable(): void
    {
        if (!$this->redis->isAvailable()) {
            throw new \RuntimeException('Redis is unavailable; scheduler job status cannot be read.');
        }
    }

    public function recordStarted(string $command, int $now): void
    {
        $previous = $this->readPayload($command);
        $this->write($command, [
            'lastStartedAt' => $now,
            'lastFinishedAt' => $previous['lastFinishedAt'],
            'lastExitCode' => $previous['lastExitCode'],
            'host' => $this->hostname(),
        ]);
    }

    public function recordFinished(string $command, int $now, int $exitCode): void
    {
        $previous = $this->readPayload($command);
        $host = $previous['host'];
        $this->write($command, [
            'lastStartedAt' => $previous['lastStartedAt'] ?? $now,
            'lastFinishedAt' => $now,
            'lastExitCode' => $exitCode,
            'host' => null !== $host && '' !== $host ? $host : $this->hostname(),
        ]);
    }

    /**
     * @return array{
     *     lastStartedAt: int|null,
     *     lastFinishedAt: int|null,
     *     lastExitCode: int|null,
     *     host: string|null
     * }|null
     */
    public function read(string $command): ?array
    {
        $payload = $this->readPayload($command);
        if (null === $payload['lastStartedAt']
            && null === $payload['lastFinishedAt']
            && null === $payload['lastExitCode']
            && null === $payload['host']
        ) {
            return null;
        }

        return $payload;
    }

    /**
     * @param array{
     *     lastStartedAt: int,
     *     lastFinishedAt: int|null,
     *     lastExitCode: int|null,
     *     host: string
     * } $payload
     */
    private function write(string $command, array $payload): void
    {
        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);
        if (!$this->redis->set(self::key($command), $encoded, self::TTL_SECONDS)) {
            throw new \RuntimeException(sprintf('Redis rejected scheduler status for "%s".', $command));
        }
    }

    /**
     * @return array{
     *     lastStartedAt: int|null,
     *     lastFinishedAt: int|null,
     *     lastExitCode: int|null,
     *     host: string|null
     * }
     */
    private function readPayload(string $command): array
    {
        $raw = $this->redis->get(self::key($command));
        if (!is_string($raw) || '' === $raw) {
            return $this->emptyPayload();
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->emptyPayload();
        }

        if (!is_array($decoded)) {
            return $this->emptyPayload();
        }

        $host = $decoded['host'] ?? null;

        return [
            'lastStartedAt' => self::intOrNull($decoded['lastStartedAt'] ?? null),
            'lastFinishedAt' => self::intOrNull($decoded['lastFinishedAt'] ?? null),
            'lastExitCode' => self::intOrNull($decoded['lastExitCode'] ?? null),
            'host' => is_string($host) && '' !== $host ? $host : null,
        ];
    }

    /**
     * @return array{
     *     lastStartedAt: int|null,
     *     lastFinishedAt: int|null,
     *     lastExitCode: int|null,
     *     host: string|null
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'lastStartedAt' => null,
            'lastFinishedAt' => null,
            'lastExitCode' => null,
            'host' => null,
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function hostname(): string
    {
        $hostname = gethostname();
        if (!is_string($hostname) || '' === $hostname) {
            return 'unknown';
        }

        return $hostname;
    }
}
