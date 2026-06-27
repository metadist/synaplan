<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\Infrastructure\RedisService;

/**
 * Redis persistence + indexing for {@see MediaJob}.
 *
 * Layout
 * ------
 *   mediajob:{jobKey}        -> JSON snapshot of the job (TTL'd).
 *   mediajob:msg:{messageId} -> SET of jobKeys for one assistant message,
 *                               so the poll endpoint can list every card's job.
 *   mediajob:active          -> ZSET of non-terminal jobKeys scored by the
 *                               heartbeat (updated ts). The reaper scans the
 *                               low end of this set to find jobs whose worker
 *                               died (stale heartbeat) — the "nothing runs
 *                               forever / nothing fails silently" safety net.
 *
 * Why Redis: progress/heartbeat are written many times per render across a
 * multi-node cluster; this is exactly the ephemeral, high-write, cross-node
 * state Redis is the canonical store for here (see {@see MediaJob}).
 */
final class MediaJobStore
{
    private const JOB_PREFIX = 'mediajob:';
    private const MSG_PREFIX = 'mediajob:msg:';
    private const ACTIVE_ZSET = 'mediajob:active';

    /**
     * Per-user index of active job keys: `mediajob:user:{userId}` -> SET.
     * Backs the global Jobs tray and the per-user concurrency limit in O(jobs
     * for that user) instead of scanning every active job across all tenants.
     */
    private const USER_ACTIVE_PREFIX = 'mediajob:user:';

    /** Retention for in-flight jobs — generous enough for the longest render. */
    private const ACTIVE_TTL_SECONDS = 21600; // 6h

    /** Retention after a job reaches a terminal state (poll grace window). */
    private const TERMINAL_TTL_SECONDS = 3600; // 1h

    public function __construct(
        private readonly RedisService $redis,
    ) {
    }

    public function save(MediaJob $job): void
    {
        $terminal = $job->isTerminal();
        $ttl = $terminal ? self::TERMINAL_TTL_SECONDS : self::ACTIVE_TTL_SECONDS;

        // Fail loudly on a serialization error instead of casting `false` to an
        // empty string and storing it — an empty snapshot makes find() return
        // null, which silently strands the job. Options are sanitized upstream
        // (MediaJobService::create), so this should never trigger; it is the
        // last-line guard that turns a future regression into a visible error.
        $encoded = json_encode($job->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            throw new \RuntimeException(sprintf('MediaJobStore: failed to serialize job %s: %s', $job->getJobKey(), json_last_error_msg()));
        }

        $this->redis->set(self::JOB_PREFIX.$job->getJobKey(), $encoded, $ttl);

        $messageId = $job->getMessageId();
        if (null !== $messageId) {
            $this->redis->sAdd(self::MSG_PREFIX.$messageId, $job->getJobKey());
            $this->redis->expire(self::MSG_PREFIX.$messageId, self::ACTIVE_TTL_SECONDS);
        }

        if ($terminal) {
            $this->redis->zRem(self::ACTIVE_ZSET, $job->getJobKey());
        } else {
            $this->redis->zAdd(self::ACTIVE_ZSET, (float) $job->getUpdated(), $job->getJobKey());
        }

        // Per-user active index: add while in flight, remove once terminal so a
        // user's tray + concurrency count stay accurate and tenant-isolated.
        $userId = $job->getUserId();
        if ($userId > 0) {
            $userKey = self::USER_ACTIVE_PREFIX.$userId;
            if ($terminal) {
                $this->redis->sRem($userKey, $job->getJobKey());
            } else {
                $this->redis->sAdd($userKey, $job->getJobKey());
                $this->redis->expire($userKey, self::ACTIVE_TTL_SECONDS);
            }
        }
    }

    public function find(string $jobKey): ?MediaJob
    {
        $raw = $this->redis->get(self::JOB_PREFIX.$jobKey);
        if (null === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        /* @var array<string, mixed> $decoded */
        return MediaJob::fromArray($decoded);
    }

    /**
     * All jobs attached to one assistant message, oldest first.
     *
     * @return list<MediaJob>
     */
    public function findByMessage(int $messageId): array
    {
        $keys = $this->redis->sMembers(self::MSG_PREFIX.$messageId);
        $jobs = [];
        foreach ($keys as $key) {
            $job = $this->find($key);
            if (null === $job) {
                // Snapshot expired but the index lingered — self-heal.
                $this->redis->sRem(self::MSG_PREFIX.$messageId, $key);
                continue;
            }
            $jobs[] = $job;
        }

        usort($jobs, static fn (MediaJob $a, MediaJob $b): int => $a->getCreated() <=> $b->getCreated());

        return $jobs;
    }

    /**
     * Active jobs whose heartbeat is older than the cutoff — i.e. nobody has
     * touched them recently, so their worker is presumed dead. Self-heals the
     * active set for entries whose snapshot expired or already went terminal.
     *
     * @return list<MediaJob>
     */
    public function findStale(int $heartbeatCutoff, int $limit = 100): array
    {
        $keys = $this->redis->zRangeByScore(self::ACTIVE_ZSET, '-inf', (string) $heartbeatCutoff, $limit);
        $jobs = [];
        foreach ($keys as $key) {
            $job = $this->find($key);
            if (null === $job || $job->isTerminal()) {
                $this->redis->zRem(self::ACTIVE_ZSET, $key);
                continue;
            }
            $jobs[] = $job;
        }

        return $jobs;
    }

    /**
     * Active (non-terminal) jobs owned by one user, via the per-user index —
     * O(that user's jobs), tenant-isolated. Self-heals index entries whose
     * snapshot expired or already went terminal.
     *
     * @return list<MediaJob>
     */
    public function findActiveForUser(int $userId, int $limit = 200): array
    {
        if ($userId <= 0) {
            return [];
        }

        $userKey = self::USER_ACTIVE_PREFIX.$userId;
        $keys = $this->redis->sMembers($userKey);
        $jobs = [];
        foreach ($keys as $key) {
            $job = $this->find($key);
            if (null === $job || $job->isTerminal()) {
                // Snapshot expired or job finished but the index lingered — self-heal.
                $this->redis->sRem($userKey, $key);
                continue;
            }
            $jobs[] = $job;
            if (count($jobs) >= $limit) {
                break;
            }
        }

        return $jobs;
    }

    /** Count a user's active jobs (resolves + self-heals the index). */
    public function countActiveForUser(int $userId): int
    {
        return count($this->findActiveForUser($userId, \PHP_INT_MAX));
    }

    /**
     * Non-terminal jobs whose {@see MediaJob::isPastDeadline()} is true.
     * Unlike {@see findStale}, this catches overdue jobs even when a worker
     * is still heartbeating (advancer/reaper gap).
     *
     * @return list<MediaJob>
     */
    public function findPastDeadline(int $limit = 100): array
    {
        $keys = $this->redis->zRangeByScore(self::ACTIVE_ZSET, '-inf', '+inf', $limit);
        $jobs = [];
        foreach ($keys as $key) {
            $job = $this->find($key);
            if (null === $job || $job->isTerminal()) {
                $this->redis->zRem(self::ACTIVE_ZSET, $key);
                continue;
            }
            if (!$job->isPastDeadline()) {
                continue;
            }
            $jobs[] = $job;
        }

        return $jobs;
    }
}
