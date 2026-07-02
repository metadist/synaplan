<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Repository\ConfigRepository;

/**
 * Feature-flag resolver for the multi-task routing engine.
 *
 * Three flags live in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - ROUTING_ENABLED  — master switch: route through the TaskPlanner/executor.
 *   - SHADOW_MODE      — generate + persist a plan but let the legacy path answer.
 *   - PARALLEL_ENABLED — run independent DAG nodes concurrently (Phase 4).
 *
 * Resolution mirrors {@see \App\Service\ModelConfigService::getDefaultModel}:
 * per-user row (BOWNERID = userId) overrides the global row (BOWNERID = 0),
 * which overrides the built-in code default.
 *
 * Built-in defaults (used when NO row exists at all — e.g. a fresh OSS clone
 * before `app:seed` runs):
 *   - ROUTING_ENABLED  → true   (new installs / dev / new signups get the new
 *                                routing instantly; existing users on the live
 *                                platform are grandfathered to OFF by a one-time
 *                                data migration, giving them a switch they own)
 *   - SHADOW_MODE      → false
 *   - PARALLEL_ENABLED → false
 *
 * NOTE (Sprint 0): the executor is not wired yet, so these flags are inert —
 * toggling them changes nothing observable. They exist so later phases can be
 * gated without further schema churn.
 */
final readonly class MultitaskRoutingConfig
{
    public const CONFIG_GROUP = 'MULTITASK';

    public const KEY_ROUTING_ENABLED = 'ROUTING_ENABLED';
    public const KEY_SHADOW_MODE = 'SHADOW_MODE';
    public const KEY_PARALLEL_ENABLED = 'PARALLEL_ENABLED';
    public const KEY_MAX_PARALLEL = 'MAX_PARALLEL';
    public const KEY_NODE_TIMEOUT = 'NODE_TIMEOUT';
    public const KEY_URL_FETCH_ENABLED = 'URL_FETCH_ENABLED';

    private const DEFAULT_ROUTING_ENABLED = true;
    private const DEFAULT_SHADOW_MODE = false;
    private const DEFAULT_PARALLEL_ENABLED = false;
    private const DEFAULT_MAX_PARALLEL = 3;
    private const DEFAULT_NODE_TIMEOUT = 120;

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
    public function isRoutingEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_ROUTING_ENABLED, $userId, self::DEFAULT_ROUTING_ENABLED);
    }

    /**
     * Shadow mode is a global-only operator switch (no per-user override): a plan
     * is generated/persisted/logged but the legacy path still answers the user.
     */
    public function isShadowMode(): bool
    {
        return $this->resolveFlag(self::KEY_SHADOW_MODE, null, self::DEFAULT_SHADOW_MODE);
    }

    /**
     * Parallel execution of independent nodes (Phase 4). Global-only switch;
     * when off the executor runs the DAG sequentially.
     */
    public function isParallelEnabled(): bool
    {
        return $this->resolveFlag(self::KEY_PARALLEL_ENABLED, null, self::DEFAULT_PARALLEL_ENABLED);
    }

    /**
     * Max media nodes executed concurrently (subprocess offload). Bounds provider
     * rate-limit exposure and memory. Global-only; clamped to a sane range.
     */
    public function maxParallel(): int
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_MAX_PARALLEL);
        $n = null !== $value ? (int) $value : self::DEFAULT_MAX_PARALLEL;

        return max(1, min(8, $n));
    }

    /**
     * Per-node hard timeout (seconds) for an offloaded media subprocess. A node
     * that exceeds it is marked failed and isolated (never hangs the turn).
     */
    public function nodeTimeoutSeconds(): int
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_NODE_TIMEOUT);
        $n = null !== $value ? (int) $value : self::DEFAULT_NODE_TIMEOUT;

        return max(10, min(600, $n));
    }

    /**
     * Generic per-block feature flag (release-4.0 data nodes: URL_FETCH_ENABLED,
     * MCP_FETCH_ENABLED, EMAIL_SEARCH_ENABLED, …). Same resolution order as the
     * routing master switch: per-user row → global row → built-in default.
     * Consumed by the {@see Skill\SkillCatalog} at plan
     * time and re-checked by the block's runner at run time.
     */
    public function isFeatureEnabled(string $setting, ?int $userId, bool $default): bool
    {
        return $this->resolveFlag($setting, $userId, $default);
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
