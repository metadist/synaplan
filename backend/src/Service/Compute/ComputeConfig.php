<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Repository\ConfigRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Feature\FeatureFlagEnv;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
    public const KEY_REQUIRE_TIER = 'REQUIRE_TIER';

    public const TIER_DOCKER = 'docker';
    public const TIER_GVISOR = 'gvisor';
    public const TIER_MICROVM = 'microvm';

    /** Isolation strength, weakest first. Shared with the status card. */
    public const TIER_ORDER = [
        self::TIER_DOCKER => 0,
        self::TIER_GVISOR => 1,
        self::TIER_MICROVM => 2,
    ];

    public const DEFAULT_REQUIRE_TIER = self::TIER_DOCKER;

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

    private const TIER_CACHE_KEY = 'compute_tier_gate';
    private const TIER_CACHE_TTL_SECONDS = 60;

    public function __construct(
        private ConfigRepository $configRepository,
        private string $computeUrl,
        private string $computeToken,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
        private ?FeatureFlagEnv $featureFlagEnv = null,
        private ?HttpClientInterface $httpClient = null,
        private ?CacheInterface $cache = null,
        private LoggerInterface $logger = new NullLogger(),
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
        return $this->isSwitchedOn($userId) && $this->tierGatePasses();
    }

    /**
     * Wired + switched on, without the live tier probe. Status surfaces use
     * this for the on/off state so a down sidecar reads as down (with retry),
     * not as switched off. Everything that RUNS uses isEnabled().
     */
    public function isSwitchedOn(?int $userId = null): bool
    {
        return $this->hasSidecar() && $this->flagOn($userId);
    }

    /**
     * Minimum isolation tier for this instance. Synaplan Cloud pins `gvisor`.
     */
    public function requireTier(): string
    {
        return self::normalizeTier($this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_REQUIRE_TIER));
    }

    public static function normalizeTier(?string $raw): string
    {
        if (null === $raw || '' === trim($raw)) {
            return self::DEFAULT_REQUIRE_TIER;
        }
        $tier = strtolower(trim($raw));

        // Fail closed: an unknown stored value must never silently become the
        // weakest tier (writes are validated, so this is corruption-only).
        return isset(self::TIER_ORDER[$tier]) ? $tier : self::TIER_MICROVM;
    }

    public static function tierAtLeast(string $tier, string $minimum): bool
    {
        $levels = array_keys(self::TIER_ORDER);
        $at = array_search($tier, $levels, true);
        $need = array_search($minimum, $levels, true);

        return false !== $at && false !== $need && $at >= $need;
    }

    public static function tierDisplayName(string $tier): string
    {
        return match ($tier) {
            self::TIER_DOCKER => 'Standard isolation',
            self::TIER_GVISOR => 'Strong isolation',
            self::TIER_MICROVM => 'Virtual machine isolation',
            default => '' !== $tier ? $tier : 'unknown isolation',
        };
    }

    /**
     * CS31 hard gate: the reported tier must meet REQUIRE_TIER, re-checked
     * every 60 s. Unreachable counts as failing. Skipped only when no HTTP
     * client was wired (unit contexts keep legacy behavior). The cache key
     * is scoped to the sidecar URL so retargeting the endpoint cannot
     * inherit the previous sidecar's tier.
     */
    private function tierGatePasses(): bool
    {
        $http = $this->httpClient;
        $cache = $this->cache;
        if (null === $http || null === $cache) {
            return true;
        }
        $cacheKey = self::TIER_CACHE_KEY.'.'.substr(hash('sha256', $this->baseUrl()), 0, 16);
        try {
            $tier = $cache->get($cacheKey, function (ItemInterface $item) use ($http): ?string {
                $item->expiresAfter(self::TIER_CACHE_TTL_SECONDS);

                return $this->fetchSidecarTier($http);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('ComputeConfig: tier gate cache failed', ['error' => $e->getMessage()]);
            $tier = $this->fetchSidecarTier($http);
        }

        return \is_string($tier) && self::tierAtLeast($tier, $this->requireTier());
    }

    private function fetchSidecarTier(HttpClientInterface $http): ?string
    {
        try {
            $response = $http->request('GET', $this->baseUrl().'/v1/health', ['timeout' => 5]);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $data = json_decode($response->getContent(), true);
            $tier = \is_array($data) ? ($data['tier'] ?? null) : null;

            return \is_string($tier) && '' !== $tier ? strtolower(trim($tier)) : null;
        } catch (\Throwable $e) {
            $this->logger->warning('ComputeConfig: sidecar tier probe failed', ['error' => $e->getMessage()]);

            return null;
        }
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
