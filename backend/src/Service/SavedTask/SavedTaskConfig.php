<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Repository\ConfigRepository;

/**
 * Feature-flag resolver for Saved Tasks.
 *
 * Flags live in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - ENABLED — master switch: persist / run Saved Tasks.
 *
 * Resolution mirrors {@see \App\Service\Multitask\MultitaskRoutingConfig}:
 * per-user row (BOWNERID = userId) overrides the global row (BOWNERID = 0),
 * which overrides the built-in code default (OFF).
 *
 * The seeder inserts a global ON row for new / local-dev installs. Existing
 * production rows are never overwritten (insert-if-missing).
 */
final readonly class SavedTaskConfig
{
    public const CONFIG_GROUP = 'SAVEDTASKS';
    public const KEY_ENABLED = 'ENABLED';

    private const DEFAULT_ENABLED = false;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Master switch. Per-user override wins, then global, then built-in default (OFF).
     *
     * Pass the EFFECTIVE user id so email/WhatsApp/cron runs resolve the flag
     * for the same identity that owns the task.
     */
    public function isEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    private function resolveFlag(string $setting, ?int $userId, bool $default): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $setting);
            if (null !== $perUser) {
                return $this->toBool($perUser, $default);
            }
        }

        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
        if (null !== $global) {
            return $this->toBool($global, $default);
        }

        return $default;
    }

    private function toBool(string $value, bool $default): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
