<?php

declare(strict_types=1);

namespace App\Bundle;

use App\Repository\ConfigRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Feature\FeatureFlagEnv;

/**
 * Feature flag for synaplan-bundle.v1 export/import routes.
 *
 * Seeded ON since 4.8; `FEATURE_BUNDLE_ENABLED=false` or the Features tab
 * under System configuration turns it off. Bundle endpoints 404 while off.
 */
final readonly class BundleConfig
{
    public const CONFIG_GROUP = 'BUNDLE';
    public const KEY_ENABLED = 'ENABLED';

    private const DEFAULT_ENABLED = false;

    public function __construct(
        private ConfigRepository $configRepository,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
        private ?FeatureFlagEnv $featureFlagEnv = null,
    ) {
    }

    public function isEnabled(?int $userId): bool
    {
        $pinned = $this->featureFlagEnv?->forced(self::CONFIG_GROUP, self::KEY_ENABLED);
        if (null !== $pinned) {
            return $pinned;
        }
        if (null !== $this->layeredConfigResolver) {
            return $this->layeredConfigResolver->resolveBool($userId, self::CONFIG_GROUP, self::KEY_ENABLED, self::DEFAULT_ENABLED);
        }
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_ENABLED);
            if (null !== $perUser) {
                return $this->toBool($perUser);
            }
        }
        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ENABLED);
        if (null !== $global) {
            return $this->toBool($global);
        }

        return self::DEFAULT_ENABLED;
    }

    private function toBool(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
    }
}
