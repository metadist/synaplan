<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\ConfigRepository;

/**
 * Feature flag + tuning resolver for the asynchronous media-job backbone
 * (Release 4.0, Feature 1).
 *
 * Flags live in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - ASYNC_JOBS_ENABLED        — master switch: chat/multitask media (image,
 *                                 video, audio) detaches to a background job
 *                                 instead of running inline. Built-in default ON
 *                                 (Sprint F rollout): OSS clones, fresh installs,
 *                                 dev, and new signups get async media instantly.
 *                                 Existing users on the live platform are
 *                                 grandfathered to an explicit per-user OFF row by
 *                                 a one-time data migration (mirrors the multitask
 *                                 routing rollout), giving them a switch they own.
 *   - JOB_POLL_INTERVAL_SECONDS — delay the advancer waits before re-dispatching
 *                                 itself for the next non-blocking poll step.
 *   - JOB_IMAGE_INLINE_FAST_MS  — grace window so a fast image render can still
 *                                 resolve in the same turn (consumed in Sprint B).
 *   - JOB_HEARTBEAT_STALE_SECONDS — how long without a heartbeat before the
 *                                 reaper presumes the worker died and times the
 *                                 job out. Must be comfortably larger than the
 *                                 poll interval so a slow-but-alive render is
 *                                 never killed.
 *
 * Resolution mirrors {@see \App\Service\Multitask\MultitaskRoutingConfig}:
 * per-user row (BOWNERID = userId) overrides the global row (BOWNERID = 0),
 * which overrides the built-in code default.
 */
final readonly class MediaJobConfig
{
    public const CONFIG_GROUP = 'MEDIA';

    public const KEY_ASYNC_JOBS_ENABLED = 'ASYNC_JOBS_ENABLED';
    public const KEY_POLL_INTERVAL_SECONDS = 'JOB_POLL_INTERVAL_SECONDS';
    public const KEY_IMAGE_INLINE_FAST_MS = 'JOB_IMAGE_INLINE_FAST_MS';
    public const KEY_HEARTBEAT_STALE_SECONDS = 'JOB_HEARTBEAT_STALE_SECONDS';
    public const KEY_MAX_ACTIVE_PER_USER = 'JOB_MAX_ACTIVE_PER_USER';

    private const DEFAULT_ASYNC_JOBS_ENABLED = true;
    private const DEFAULT_POLL_INTERVAL_SECONDS = 3;
    private const DEFAULT_IMAGE_INLINE_FAST_MS = 1500;
    private const DEFAULT_HEARTBEAT_STALE_SECONDS = 90;
    private const DEFAULT_MAX_ACTIVE_PER_USER = 16;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Master switch. Per-user override wins, then global, then built-in default (ON).
     *
     * Pass the EFFECTIVE user id (see ModelConfigService::getEffectiveUserIdForMessage)
     * so email/WhatsApp remapping resolves the flag for the same identity that
     * resolves the models.
     */
    public function isAsyncJobsEnabled(?int $userId = null): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_ASYNC_JOBS_ENABLED);
            if (null !== $perUser) {
                return $this->toBool($perUser, self::DEFAULT_ASYNC_JOBS_ENABLED);
            }
        }

        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ASYNC_JOBS_ENABLED);
        if (null !== $global) {
            return $this->toBool($global, self::DEFAULT_ASYNC_JOBS_ENABLED);
        }

        return self::DEFAULT_ASYNC_JOBS_ENABLED;
    }

    /**
     * Delay (seconds) the advancer waits before re-dispatching itself for the
     * next poll. Global-only switch; clamped to a sane range so a misconfigured
     * row can neither hammer the provider nor stall a render.
     */
    public function pollIntervalSeconds(): int
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_POLL_INTERVAL_SECONDS);
        $n = null !== $value ? (int) $value : self::DEFAULT_POLL_INTERVAL_SECONDS;

        return max(1, min(30, $n));
    }

    /**
     * Grace window (ms) within which a fast image job may resolve inline in the
     * same turn instead of detaching to the tray. Consumed in Sprint B; defined
     * here so the rollout can tune it without further schema churn.
     */
    public function imageInlineFastMs(): int
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_IMAGE_INLINE_FAST_MS);
        $n = null !== $value ? (int) $value : self::DEFAULT_IMAGE_INLINE_FAST_MS;

        return max(0, min(10000, $n));
    }

    /**
     * Seconds without a heartbeat after which the reaper presumes the worker
     * died and drives the job to `timed_out`. Global-only; clamped above the
     * poll interval's realistic ceiling so a live render is never reaped.
     */
    public function heartbeatStaleSeconds(): int
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_HEARTBEAT_STALE_SECONDS);
        $n = null !== $value ? (int) $value : self::DEFAULT_HEARTBEAT_STALE_SECONDS;

        return max(30, min(1800, $n));
    }

    /**
     * Maximum number of concurrent in-flight media jobs a single user may have.
     * Prevents one user from flooding the worker / piling up provider cost.
     * Global-only switch; clamped to a sane range (1..100).
     */
    public function maxActiveJobsPerUser(): int
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_MAX_ACTIVE_PER_USER);
        $n = null !== $value ? (int) $value : self::DEFAULT_MAX_ACTIVE_PER_USER;

        return max(1, min(100, $n));
    }

    private function toBool(string $value, bool $default): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
