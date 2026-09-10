<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Repository\ConfigRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Tool\Policy\PolicyOutcome;

/**
 * Feature-flag and policy defaults for the tool registry and approvals.
 *
 * BCONFIG group {@see self::CONFIG_GROUP}. Per-user row overrides global,
 * which overrides the code default. Flags default OFF until an operator
 * enables them (roadmap principle 3). REGISTRY_ENABLED code default is ON
 * after the S1 parity proof (kill switch for one release).
 */
final readonly class ToolsConfig
{
    public const CONFIG_GROUP = 'TOOLS';
    public const KEY_REGISTRY_ENABLED = 'REGISTRY_ENABLED';
    public const KEY_APPROVALS_ENABLED = 'APPROVALS_ENABLED';
    public const KEY_CUSTOM_HTTP_ENABLED = 'CUSTOM_HTTP_ENABLED';
    public const KEY_POLICY_READ = 'POLICY.read';
    public const KEY_POLICY_WRITE = 'POLICY.write';
    public const KEY_POLICY_DESTRUCTIVE = 'POLICY.destructive';
    public const KEY_APPROVAL_EXPIRY_HOURS = 'APPROVAL_EXPIRY_HOURS';
    public const KEY_APPROVAL_NOTIFY = 'APPROVAL_NOTIFY';
    public const KEY_USER_OVERRIDES = 'USER_OVERRIDES';
    public const KEY_CUSTOM_HTTP_ALLOW_PLAIN_HTTP = 'CUSTOM_HTTP_ALLOW_PLAIN_HTTP';
    public const KEY_CUSTOM_HTTP_MAX_RESPONSE_BYTES = 'CUSTOM_HTTP_MAX_RESPONSE_BYTES';

    public const NOTIFY_INSTANT = 'instant';
    public const NOTIFY_DIGEST = 'digest';

    private const DEFAULT_REGISTRY_ENABLED = true;
    private const DEFAULT_APPROVALS_ENABLED = false;
    private const DEFAULT_CUSTOM_HTTP_ENABLED = false;
    private const DEFAULT_EXPIRY_HOURS = 72;
    private const DEFAULT_MAX_RESPONSE_BYTES = 1048576;

    public function __construct(
        private ConfigRepository $configRepository,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
    ) {
    }

    public function isRegistryEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_REGISTRY_ENABLED, $userId, self::DEFAULT_REGISTRY_ENABLED);
    }

    public function isApprovalsEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_APPROVALS_ENABLED, $userId, self::DEFAULT_APPROVALS_ENABLED);
    }

    public function isCustomHttpEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_CUSTOM_HTTP_ENABLED, $userId, self::DEFAULT_CUSTOM_HTTP_ENABLED);
    }

    public function allowPlainHttp(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_CUSTOM_HTTP_ALLOW_PLAIN_HTTP, $userId, false);
    }

    public function approvalExpiryHours(?int $userId): int
    {
        $raw = $this->resolveRaw(self::KEY_APPROVAL_EXPIRY_HOURS, $userId);
        if (null === $raw || !is_numeric($raw)) {
            return self::DEFAULT_EXPIRY_HOURS;
        }
        $hours = (int) $raw;

        return max(1, min(720, $hours));
    }

    public function maxResponseBytes(?int $userId): int
    {
        $raw = $this->resolveRaw(self::KEY_CUSTOM_HTTP_MAX_RESPONSE_BYTES, $userId);
        if (null === $raw || !is_numeric($raw)) {
            return self::DEFAULT_MAX_RESPONSE_BYTES;
        }

        return max(1024, (int) $raw);
    }

    public function notifyMode(?int $userId): string
    {
        $raw = $this->resolveRaw(self::KEY_APPROVAL_NOTIFY, $userId);
        if (self::NOTIFY_DIGEST === $raw) {
            return self::NOTIFY_DIGEST;
        }

        return self::NOTIFY_INSTANT;
    }

    public function defaultOutcome(SideEffect $sideEffect, ?int $userId): PolicyOutcome
    {
        $key = match ($sideEffect) {
            SideEffect::Read => self::KEY_POLICY_READ,
            SideEffect::Write => self::KEY_POLICY_WRITE,
            SideEffect::Destructive => self::KEY_POLICY_DESTRUCTIVE,
        };
        $raw = $this->resolveRaw($key, $userId);
        $outcome = is_string($raw) ? PolicyOutcome::tryFrom($raw) : null;

        return $outcome ?? match ($sideEffect) {
            SideEffect::Read => PolicyOutcome::Auto,
            SideEffect::Write => PolicyOutcome::Approve,
            SideEffect::Destructive => PolicyOutcome::Block,
        };
    }

    /**
     * @return list<string>
     */
    public function alwaysAllowTools(?int $userId, string $assistantKey): array
    {
        $raw = $this->resolveRaw(self::KEY_USER_OVERRIDES, $userId);
        if (null === $raw || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $list = $decoded[$assistantKey] ?? [];
        if (!is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, static fn ($item): bool => is_string($item) && '' !== $item));
    }

    /**
     * @param list<string> $tools
     */
    public function addAlwaysAllow(int $userId, string $assistantKey, string $toolName): void
    {
        $raw = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_USER_OVERRIDES) ?? '{}';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $list = is_array($decoded[$assistantKey] ?? null) ? $decoded[$assistantKey] : [];
        if (!in_array($toolName, $list, true)) {
            $list[] = $toolName;
        }
        $decoded[$assistantKey] = array_values(array_filter($list, 'is_string'));
        $this->configRepository->setValue(
            $userId,
            self::CONFIG_GROUP,
            self::KEY_USER_OVERRIDES,
            json_encode($decoded, \JSON_THROW_ON_ERROR),
        );
    }

    public function setNotifyMode(int $userId, string $mode): void
    {
        $value = self::NOTIFY_DIGEST === $mode ? self::NOTIFY_DIGEST : self::NOTIFY_INSTANT;
        $this->configRepository->setValue($userId, self::CONFIG_GROUP, self::KEY_APPROVAL_NOTIFY, $value);
    }

    private function resolveFlag(string $setting, ?int $userId, bool $default): bool
    {
        if (null !== $this->layeredConfigResolver) {
            return $this->layeredConfigResolver->resolveBool($userId, self::CONFIG_GROUP, $setting, $default);
        }
        $raw = $this->resolveRaw($setting, $userId);
        if (null === $raw) {
            return $default;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function resolveRaw(string $setting, ?int $userId): ?string
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $setting);
            if (null !== $perUser) {
                return $perUser;
            }
        }

        return $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
    }
}
