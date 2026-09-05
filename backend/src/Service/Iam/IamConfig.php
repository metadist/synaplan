<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Repository\ConfigRepository;

/**
 * Feature-flag resolver for IAM (groups, sharing, directory sync).
 *
 * Flags live in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - GROUPS_ENABLED — People page, group API, AccessGate may consult groups
 *   - SHARING_ENABLED — BSHARES + Share dialog (S2)
 *   - DIRECTORY_SYNC_ENABLED — OIDC group claim upsert (S4)
 *
 * Resolution mirrors {@see \App\Service\Desktop\DesktopAgentConfig}: a per-user
 * row (BOWNERID = userId) overrides the global row (BOWNERID = 0), which
 * overrides the built-in code default (OFF).
 *
 * The whole IAM track ships to `main` with these flags OFF. Turning them on
 * is an explicit operator / per-user action.
 */
final readonly class IamConfig
{
    public const CONFIG_GROUP = 'IAM';
    public const KEY_GROUPS_ENABLED = 'GROUPS_ENABLED';
    public const KEY_SHARING_ENABLED = 'SHARING_ENABLED';
    public const KEY_DIRECTORY_SYNC_ENABLED = 'DIRECTORY_SYNC_ENABLED';

    private const DEFAULT_ENABLED = false;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function isGroupsEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_GROUPS_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    public function isSharingEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_SHARING_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    public function isDirectorySyncEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_DIRECTORY_SYNC_ENABLED, $userId, self::DEFAULT_ENABLED);
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
