<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Repository\ConfigRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Feature\FeatureFlagEnv;

/**
 * Sidecar URL + token + the COMPUTE.ENABLED product flag.
 *
 * Either missing means the feature is absent. Defaults stay off.
 */
final readonly class ComputeConfig
{
    public const CONFIG_GROUP = 'COMPUTE';
    public const KEY_ENABLED = 'ENABLED';
    public const KEY_DEFAULT_TIMEOUT_SEC = 'DEFAULT_TIMEOUT_SEC';
    public const KEY_DEFAULT_MEMORY_MB = 'DEFAULT_MEMORY_MB';
    public const KEY_DEFAULT_CPU = 'DEFAULT_CPU';
    public const KEY_DEFAULT_PIDS = 'DEFAULT_PIDS';
    public const KEY_DEFAULT_OUTPUT_MB = 'DEFAULT_OUTPUT_MB';
    public const KEY_MAX_TIMEOUT_SEC = 'MAX_TIMEOUT_SEC';
    public const KEY_POLICY_INTERACTIVE = 'POLICY_INTERACTIVE';
    public const KEY_POLICY_UNATTENDED = 'POLICY_UNATTENDED';
    public const KEY_WORKSPACES_ENABLED = 'WORKSPACES_ENABLED';
    public const KEY_EGRESS_ENABLED = 'EGRESS_ENABLED';
    public const KEY_EGRESS_REQUIRES_APPROVAL = 'EGRESS_REQUIRES_APPROVAL';
    public const KEY_EGRESS_MAX_HOSTS = 'EGRESS_MAX_HOSTS';
    public const KEY_WORKSPACE_TTL_DAYS = 'WORKSPACE_TTL_DAYS';

    public const POLICY_AUTO = 'auto';
    public const POLICY_APPROVE = 'approve';
    public const POLICY_BLOCK = 'block';

    public const DEFAULT_EGRESS_MAX_HOSTS = 8;
    public const DEFAULT_WORKSPACE_TTL_DAYS = 90;

    private const DEFAULT_ENABLED = false;
    private const DEFAULT_WORKSPACES_ENABLED = false;
    private const DEFAULT_EGRESS_ENABLED = false;
    private const DEFAULT_EGRESS_REQUIRES_APPROVAL = true;
    private const DEFAULT_POLICY_INTERACTIVE = self::POLICY_AUTO;
    private const DEFAULT_POLICY_UNATTENDED = self::POLICY_APPROVE;

    public function __construct(
        private ConfigRepository $configRepository,
        private string $computeUrl,
        private string $computeToken,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
        private ?FeatureFlagEnv $featureFlagEnv = null,
    ) {
    }

    public function hasSidecar(): bool
    {
        $url = trim($this->computeUrl);
        $token = trim($this->computeToken);

        return '' !== $url && 'disabled' !== $url && '' !== $token;
    }

    public function isEnabled(?int $userId = null): bool
    {
        return $this->hasSidecar() && $this->flagOn($userId);
    }

    public function baseUrl(): string
    {
        return rtrim(trim($this->computeUrl), '/');
    }

    public function token(): string
    {
        return trim($this->computeToken);
    }

    /**
     * @return array{timeoutSec: int, memoryMb: int, cpu: float, pids: int, outputMb: int}
     */
    public function defaultLimits(): array
    {
        return [
            'timeoutSec' => $this->intSetting(self::KEY_DEFAULT_TIMEOUT_SEC, 60),
            'memoryMb' => $this->intSetting(self::KEY_DEFAULT_MEMORY_MB, 512),
            'cpu' => $this->floatSetting(self::KEY_DEFAULT_CPU, 1.0),
            'pids' => $this->intSetting(self::KEY_DEFAULT_PIDS, 128),
            'outputMb' => $this->intSetting(self::KEY_DEFAULT_OUTPUT_MB, 50),
        ];
    }

    public function maxTimeoutSec(): int
    {
        return $this->intSetting(self::KEY_MAX_TIMEOUT_SEC, 300);
    }

    public function policyInteractive(): string
    {
        return $this->policySetting(self::KEY_POLICY_INTERACTIVE, self::DEFAULT_POLICY_INTERACTIVE);
    }

    public function policyUnattended(): string
    {
        return $this->policySetting(self::KEY_POLICY_UNATTENDED, self::DEFAULT_POLICY_UNATTENDED);
    }

    /**
     * Persistent folder between runs. Off means B1/B2 ephemeral behaviour.
     */
    public function workspacesEnabled(?int $userId = null): bool
    {
        return $this->isEnabled($userId) && $this->boolSetting(self::KEY_WORKSPACES_ENABLED, self::DEFAULT_WORKSPACES_ENABLED, $userId);
    }

    /**
     * Per-run website allow-list. Off means every run stays offline.
     */
    public function egressEnabled(?int $userId = null): bool
    {
        return $this->isEnabled($userId) && $this->boolSetting(self::KEY_EGRESS_ENABLED, self::DEFAULT_EGRESS_ENABLED, $userId);
    }

    public function egressRequiresApproval(?int $userId = null): bool
    {
        return $this->boolSetting(self::KEY_EGRESS_REQUIRES_APPROVAL, self::DEFAULT_EGRESS_REQUIRES_APPROVAL, $userId);
    }

    public function egressMaxHosts(): int
    {
        return max(0, $this->intSetting(self::KEY_EGRESS_MAX_HOSTS, self::DEFAULT_EGRESS_MAX_HOSTS));
    }

    public function workspaceTtlDays(): int
    {
        return max(1, $this->intSetting(self::KEY_WORKSPACE_TTL_DAYS, self::DEFAULT_WORKSPACE_TTL_DAYS));
    }

    private function policySetting(string $key, string $default): string
    {
        $raw = strtolower(trim((string) ($this->configRepository->getValue(0, self::CONFIG_GROUP, $key) ?? '')));
        if (\in_array($raw, [self::POLICY_AUTO, self::POLICY_APPROVE, self::POLICY_BLOCK], true)) {
            return $raw;
        }

        return $default;
    }

    /**
     * Clamp requested limits to the configured defaults / max timeout.
     *
     * @param array<string, mixed> $requested
     *
     * @return array{timeoutSec: int, memoryMb: int, cpu: float, pids: int, outputMb: int}
     */
    public function clampLimits(array $requested): array
    {
        $defaults = $this->defaultLimits();
        $timeout = isset($requested['timeoutSec']) ? (int) $requested['timeoutSec'] : $defaults['timeoutSec'];
        $memory = isset($requested['memoryMb']) ? (int) $requested['memoryMb'] : $defaults['memoryMb'];
        $cpu = isset($requested['cpu']) ? (float) $requested['cpu'] : $defaults['cpu'];
        $pids = isset($requested['pids']) ? (int) $requested['pids'] : $defaults['pids'];
        $output = isset($requested['outputMb']) ? (int) $requested['outputMb'] : $defaults['outputMb'];

        return [
            'timeoutSec' => max(1, min($timeout, $defaults['timeoutSec'], $this->maxTimeoutSec())),
            'memoryMb' => max(32, min($memory, $defaults['memoryMb'])),
            'cpu' => max(0.1, min($cpu, $defaults['cpu'])),
            'pids' => max(8, min($pids, $defaults['pids'])),
            'outputMb' => max(1, min($output, $defaults['outputMb'])),
        ];
    }

    private function flagOn(?int $userId): bool
    {
        return $this->boolSetting(self::KEY_ENABLED, self::DEFAULT_ENABLED, $userId);
    }

    private function boolSetting(string $key, bool $default, ?int $userId = null): bool
    {
        $pinned = $this->featureFlagEnv?->forced(self::CONFIG_GROUP, $key);
        if (null !== $pinned) {
            return $pinned;
        }
        if (null !== $this->layeredConfigResolver) {
            return $this->layeredConfigResolver->resolveBool($userId, self::CONFIG_GROUP, $key, $default);
        }
        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, $key);

        return $this->toBool($global, $default);
    }

    private function intSetting(string $key, int $default): int
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, $key);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    private function floatSetting(string $key, float $default): float
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, $key);

        return is_numeric($raw) ? (float) $raw : $default;
    }

    private function toBool(?string $value, bool $default): bool
    {
        if (null === $value) {
            return $default;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
