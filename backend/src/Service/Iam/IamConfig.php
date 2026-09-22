<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\Share;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\RegistrationConfig;

/**
 * Feature-flag resolver for IAM (groups, sharing, directory sync, policies).
 *
 * Flags live in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - GROUPS_ENABLED — People page, group API, AccessGate may consult groups
 *   - SHARING_ENABLED — BSHARES + Share dialog (S2)
 *   - USER_SEARCH_ENABLED — share picker may search user accounts (OFF unless
 *     an admin enables it: on a public instance the directory would expose
 *     every registered account, #2060)
 *   - DIRECTORY_SYNC_ENABLED — OIDC group claim upsert (S4)
 *   - GROUP_POLICIES_ENABLED — People → Policies and group-layer defaults (S5)
 *
 * Resolution mirrors {@see \App\Service\Desktop\DesktopAgentConfig}: an
 * explicit `FEATURE_IAM_*` environment variable pins the flag
 * ({@see FeatureFlagEnv}); otherwise a per-user row (BOWNERID = userId)
 * overrides the global row (BOWNERID = 0), which overrides the built-in code
 * fallback (OFF, only reached when no row was ever seeded).
 *
 * Since 4.8 the seeder writes the flags ON. Operators change them under
 * Operate → System configuration → Features, or pin them off for an automated
 * deployment with `FEATURE_IAM_*=false`. Exception: USER_SEARCH_ENABLED
 * seeds OFF (#2060) — user-directory search is opt-in.
 */
final readonly class IamConfig
{
    public const CONFIG_GROUP = 'IAM';
    public const KEY_GROUPS_ENABLED = 'GROUPS_ENABLED';
    public const KEY_SHARING_ENABLED = 'SHARING_ENABLED';
    public const KEY_USER_SEARCH_ENABLED = 'USER_SEARCH_ENABLED';
    public const KEY_DIRECTORY_SYNC_ENABLED = 'DIRECTORY_SYNC_ENABLED';
    public const KEY_GROUP_POLICIES_ENABLED = 'GROUP_POLICIES_ENABLED';
    public const KEY_DIRECTORY_GROUPS_CLAIM = 'DIRECTORY_GROUPS_CLAIM';
    public const KEY_DIRECTORY_GROUP_NAMES = 'DIRECTORY_GROUP_NAMES';
    public const KEY_EVERYONE_SHARES = 'EVERYONE_SHARES';
    public const KEY_ADMIN_IMPERSONATION = 'ADMIN_IMPERSONATION';
    public const KEY_AUDIT_RETENTION_DAYS = 'AUDIT_RETENTION_DAYS';

    public const EVERYONE_SHARES_ANY_OWNER = 'any_owner';
    public const EVERYONE_SHARES_ADMINS_ONLY = 'admins_only';

    /**
     * No user-facing audience. Existing everyone rows stay in the database
     * and grant nothing until an operator picks another value. A grant the
     * platform wrote ({@see Share::isPlatformGrant()}) still reaches every
     * account (#2096).
     */
    public const EVERYONE_SHARES_DISABLED = 'disabled';

    public const IMPERSONATION_AUDITED = 'audited';
    public const IMPERSONATION_DISABLED = 'disabled';

    public const DEFAULT_DIRECTORY_GROUPS_CLAIM = 'groups';
    public const DEFAULT_AUDIT_RETENTION_DAYS = 365;

    /** Code fallback when no BCONFIG row exists at all; the seeder writes ON rows. */
    private const DEFAULT_ENABLED = false;

    public function __construct(
        private ConfigRepository $configRepository,
        private ?FeatureFlagEnv $featureFlagEnv = null,
        private ?RegistrationConfig $registration = null,
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
     * Whether the share picker may search user accounts by name or email.
     * OFF unless an admin enables it: with the whole user table in one
     * database, an open directory exposes every registered account (#2060).
     * Groups the actor can see are unaffected.
     */
    public function isUserSearchEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_USER_SEARCH_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    /**
     * Who may share a resource with every account on this instance.
     *
     * The global row (owner 0) decides. A per-user row may narrow it to
     * `admins_only` or `disabled`, but a global {@see self::EVERYONE_SHARES_DISABLED}
     * cannot be widened by one: a single operator switch closes the audience
     * for everyone. This is the one read behind every access decision, so it
     * costs one query for the global row plus one when a user id is given.
     */
    public function everyoneSharesPolicy(?int $userId): string
    {
        $global = $this->knownEveryoneShares(
            $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_EVERYONE_SHARES)
        ) ?? $this->everyoneSharesFallback();

        if (self::EVERYONE_SHARES_DISABLED === $global || null === $userId || $userId <= 0) {
            return $global;
        }

        $perUser = $this->knownEveryoneShares(
            $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_EVERYONE_SHARES)
        );

        return $perUser ?? $global;
    }

    /**
     * Whether everyone-shares written by people still reach signed-in accounts.
     * False only for {@see self::EVERYONE_SHARES_DISABLED}. `admins_only` still
     * honours rows that already exist; it only restricts who may create them.
     */
    public function isEveryoneAudienceEnabled(): bool
    {
        return self::EVERYONE_SHARES_DISABLED !== $this->everyoneSharesPolicy(null);
    }

    /**
     * Whether one stored everyone-share reaches other accounts right now.
     *
     * The single rule behind {@see \App\Repository\ShareRepository::findForSubjects()},
     * the share list, the chat-history pill and the realtime fan-out: a grant
     * a person wrote follows the policy; a grant the platform wrote (seeded
     * system assistants, plugin packs — {@see Share::isPlatformGrant()}) always
     * reaches everyone. Rows that are not everyone-shares are not this method's
     * concern and count as reaching.
     */
    public function everyoneShareReaches(Share $share): bool
    {
        return ($this->everyoneShareReachesFilter())($share);
    }

    /**
     * {@see self::everyoneShareReaches()} for a list: the policy row is read
     * at most once per returned closure, however many shares pass through it.
     *
     * @return \Closure(Share): bool
     */
    public function everyoneShareReachesFilter(): \Closure
    {
        $audienceOn = null;

        return function (Share $share) use (&$audienceOn): bool {
            if (Share::SUBJECT_EVERYONE !== $share->getSubjectType() || $share->isPlatformGrant()) {
                return true;
            }
            $audienceOn ??= $this->isEveryoneAudienceEnabled();

            return $audienceOn;
        };
    }

    public function canShareWithEveryone(User $actor): bool
    {
        return match ($this->everyoneSharesPolicy((int) $actor->getId())) {
            self::EVERYONE_SHARES_DISABLED => false,
            self::EVERYONE_SHARES_ANY_OWNER => true,
            default => $actor->isAdmin(),
        };
    }

    /**
     * The stored value when it is one of the three policies, else null.
     */
    private function knownEveryoneShares(?string $value): ?string
    {
        return match ($value) {
            self::EVERYONE_SHARES_ANY_OWNER,
            self::EVERYONE_SHARES_ADMINS_ONLY,
            self::EVERYONE_SHARES_DISABLED => $value,
            default => null,
        };
    }

    /**
     * Policy when no usable global row exists. This is the gap between the
     * migration (which never inserts a row) and the seeder, and the case of a
     * hand-edited value. It fails closed where anyone can sign up — the public
     * case — and keeps the company default only when sign-up is explicitly off.
     * The same rule seeds a fresh install ({@see \App\Seed\IamConfigSeeder}).
     */
    private function everyoneSharesFallback(): string
    {
        return ($this->registration?->isEnabled() ?? true)
            ? self::EVERYONE_SHARES_DISABLED
            : self::EVERYONE_SHARES_ANY_OWNER;
    }

    public function isDirectorySyncEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_DIRECTORY_SYNC_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    public function isGroupPoliciesEnabled(?int $userId): bool
    {
        return $this->isGroupsEnabled($userId)
            && $this->resolveFlag(self::KEY_GROUP_POLICIES_ENABLED, $userId, self::DEFAULT_ENABLED);
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
        $pinned = $this->featureFlagEnv?->forced(self::CONFIG_GROUP, $setting);
        if (null !== $pinned) {
            return $pinned;
        }
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
