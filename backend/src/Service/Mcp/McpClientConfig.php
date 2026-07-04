<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Repository\ConfigRepository;

/**
 * Feature flags for the OUTBOUND MCP client (release-4.0 plan 09 §6).
 *
 * BCONFIG group `MCP`:
 *   - CLIENT_ENABLED — master switch for any outbound MCP traffic
 *     (per-user row overrides global row overrides built-in default OFF).
 *   - NODE_TIMEOUT   — per-call hard timeout in seconds (global only,
 *     clamped; a slow server degrades one call, never the turn).
 */
final readonly class McpClientConfig
{
    public const CONFIG_GROUP = 'MCP';

    public const KEY_CLIENT_ENABLED = 'CLIENT_ENABLED';
    public const KEY_NODE_TIMEOUT = 'NODE_TIMEOUT';

    private const DEFAULT_CLIENT_ENABLED = false;
    private const DEFAULT_NODE_TIMEOUT = 15;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function isClientEnabled(?int $userId): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_CLIENT_ENABLED);
            if (null !== $perUser) {
                return $this->toBool($perUser);
            }
        }

        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_CLIENT_ENABLED);
        if (null !== $global) {
            return $this->toBool($global);
        }

        return self::DEFAULT_CLIENT_ENABLED;
    }

    public function nodeTimeoutSeconds(): int
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_NODE_TIMEOUT);
        $n = null !== $value ? (int) $value : self::DEFAULT_NODE_TIMEOUT;

        return max(3, min(120, $n));
    }

    private function toBool(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_CLIENT_ENABLED;
    }
}
