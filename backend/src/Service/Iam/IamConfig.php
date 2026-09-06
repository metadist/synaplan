<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
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
    public const KEY_DIRECTORY_GROUPS_CLAIM = 'DIRECTORY_GROUPS_CLAIM';
    public const KEY_DIRECTORY_GROUP_NAMES = 'DIRECTORY_GROUP_NAMES';
    public const KEY_EVERYONE_SHARES = 'EVERYONE_SHARES';
    public const KEY_ADMIN_IMPERSONATION = 'ADMIN_IMPERSONATION';
    public const KEY_AUDIT_RETENTION_DAYS = 'AUDIT_RETENTION_DAYS';

    public const EVERYONE_SHARES_ANY_OWNER = 'any_owner';
    public const EVERYONE_SHARES_ADMINS_ONLY = 'admins_only';

    public const IMPERSONATION_AUDITED = 'audited';
    public const IMPERSONATION_DISABLED = 'disabled';

    public const DEFAULT_DIRECTORY_GROUPS_CLAIM = 'groups';
    public const DEFAULT_AUDIT_RETENTION_DAYS = 365;

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
        return $this->isGroupsEnabled($userId)
            && $this->resolveFlag(self::KEY_SHARING_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    /**
     * Who may share a resource with "everyone on this instance".
     */
    public function everyoneSharesPolicy(?int $userId): string
    {
        $value = null;
        if (null !== $userId && $userId > 0) {
            $value = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_EVERYONE_SHARES);
        }
        if (null === $value) {
            $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_EVERYONE_SHARES);
        }
        if (self::EVERYONE_SHARES_ADMINS_ONLY === $value) {
            return self::EVERYONE_SHARES_ADMINS_ONLY;
        }

        return self::EVERYONE_SHARES_ANY_OWNER;
    }

    public function canShareWithEveryone(User $actor): bool
    {
        if (self::EVERYONE_SHARES_ANY_OWNER === $this->everyoneSharesPolicy((int) $actor->getId())) {
            return true;
        }

        return $actor->isAdmin();
    }

    public function isDirectorySyncEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_DIRECTORY_SYNC_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    public function directoryGroupsClaim(?int $userId): string
    {
        $value = $this->resolveString(self::KEY_DIRECTORY_GROUPS_CLAIM, $userId);
        $claim = is_string($value) && '' !== trim($value) ? trim($value) : self::DEFAULT_DIRECTORY_GROUPS_CLAIM;

        return $claim;
    }

    /**
     * Optional claim-value → display-name map (`IAM.DIRECTORY_GROUP_NAMES` JSON).
     *
     * @return array<string, string>
     */
    public function directoryGroupNames(?int $userId): array
    {
        $raw = $this->resolveString(self::KEY_DIRECTORY_GROUP_NAMES, $userId);
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $name) {
            if (is_string($key) && is_string($name) && '' !== $name) {
                $out[$key] = $name;
            }
        }

        return $out;
    }

    public function adminImpersonationPolicy(?int $userId): string
    {
        $value = $this->resolveString(self::KEY_ADMIN_IMPERSONATION, $userId);
        if (self::IMPERSONATION_DISABLED === $value) {
            return self::IMPERSONATION_DISABLED;
        }

        return self::IMPERSONATION_AUDITED;
    }

    public function isImpersonationDisabled(?int $userId): bool
    {
        return self::IMPERSONATION_DISABLED === $this->adminImpersonationPolicy($userId);
    }

    public function auditRetentionDays(?int $userId): int
    {
        $raw = $this->resolveString(self::KEY_AUDIT_RETENTION_DAYS, $userId);
        if (!is_string($raw) || '' === trim($raw)) {
            return self::DEFAULT_AUDIT_RETENTION_DAYS;
        }
        if (!is_numeric($raw)) {
            return self::DEFAULT_AUDIT_RETENTION_DAYS;
        }

        return max(0, (int) $raw);
    }

    private function resolveString(string $setting, ?int $userId): ?string
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $setting);
            if (null !== $perUser) {
                return $perUser;
            }
        }

        return $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
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
