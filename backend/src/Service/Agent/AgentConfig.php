<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Repository\ConfigRepository;
use App\Service\Config\LayeredConfigResolver;

/**
 * Feature-flag resolver for the Agent Builder surface.
 *
 * Flags live in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - ENABLED — master switch. When OFF, every /api/v1/agents* route is 404
 *     and stream `agentId` is ignored (S1 invariant C1).
 *
 * Per-user override wins, then global (BOWNERID = 0), then the built-in
 * default (OFF). The seeder inserts a global `0` row; turning it on is an
 * explicit operator / per-user action.
 */
final readonly class AgentConfig
{
    public const CONFIG_GROUP = 'AGENTS';
    public const KEY_ENABLED = 'ENABLED';
    public const KEY_ROUTABLE_ENABLED = 'ROUTABLE_ENABLED';

    private const DEFAULT_ENABLED = false;
    private const DEFAULT_ROUTABLE_ENABLED = false;

    public function __construct(
        private ConfigRepository $configRepository,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
    ) {
    }

    public function isEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    /**
     * When off (the seeded default) the sorter never sees `agent:{slug}`
     * topics, so existing routing snapshots stay byte-identical.
     */
    public function isRoutableEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_ROUTABLE_ENABLED, $userId, self::DEFAULT_ROUTABLE_ENABLED);
    }

    private function resolveFlag(string $setting, ?int $userId, bool $default): bool
    {
        if (null !== $this->layeredConfigResolver) {
            return $this->layeredConfigResolver->resolveBool($userId, self::CONFIG_GROUP, $setting, $default);
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
