<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Repository\ConfigRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Feature\FeatureFlagEnv;

/**
 * Feature flag for the Saved Task Steps editor, builder-only graph kinds
 * and the inbound webhook trigger.
 *
 * BCONFIG group {@see self::CONFIG_GROUP}. Insert-if-missing default is OFF.
 */
final readonly class WorkflowsConfig
{
    public const CONFIG_GROUP = 'WORKFLOWS';
    public const KEY_BUILDER_ENABLED = 'BUILDER_ENABLED';

    private const DEFAULT_BUILDER_ENABLED = false;

    public function __construct(
        private ConfigRepository $configRepository,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
        private ?FeatureFlagEnv $featureFlagEnv = null,
    ) {
    }

    public function isBuilderEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_BUILDER_ENABLED, $userId, self::DEFAULT_BUILDER_ENABLED);
    }

    private function resolveFlag(string $setting, ?int $userId, bool $default): bool
    {
        $pinned = $this->featureFlagEnv?->forced(self::CONFIG_GROUP, $setting);
        if (null !== $pinned) {
            return $pinned;
        }
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
