<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Repository\ConfigRepository;

/**
 * Feature-flag resolver for the Synaplan Desktop agent (the whole desktop
 * pairing / job-queue surface).
 *
 * Flags live in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - ENABLED — master switch: expose pairing, device CRUD, the job queue, and
 *     the MCP check-in tools. When OFF, every desktop route is 404 and the
 *     desktop MCP tools are absent from `tools/list` (invariant C8).
 *
 * Resolution mirrors {@see \App\Service\SavedTask\SavedTaskConfig}: a per-user
 * row (BOWNERID = userId) overrides the global row (BOWNERID = 0), which
 * overrides the built-in code default (OFF).
 *
 * The whole Desktop epic ships to `main` with this flag OFF (master plan
 * decision 21): the seeder inserts a global `0` row and no migration turns it
 * on. Turning it on is an explicit operator / per-user action, so a
 * consumer-less feature is inert on every existing and new install until the
 * client exists.
 */
final readonly class DesktopAgentConfig
{
    public const CONFIG_GROUP = 'DESKTOP_AGENT';
    public const KEY_ENABLED = 'ENABLED';

    private const DEFAULT_ENABLED = false;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Master switch. Per-user override wins, then global, then built-in default
     * (OFF). Pass the effective user id (null for anonymous / unresolved).
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
