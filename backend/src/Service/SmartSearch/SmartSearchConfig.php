<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

use App\Service\Config\LayeredConfigResolver;
use App\Service\Feature\FeatureFlagEnv;

/**
 * BCONFIG SEARCH.* — AI_ENABLED is seeded ON and pinnable with
 * FEATURE_SEARCH_AI_ENABLED. Off removes the AI tier of the search palette;
 * keyword and meaning search keep working.
 */
final readonly class SmartSearchConfig
{
    public const CONFIG_GROUP = 'SEARCH';
    public const KEY_AI_ENABLED = 'AI_ENABLED';

    public function __construct(
        private LayeredConfigResolver $layeredConfigResolver,
        private FeatureFlagEnv $featureFlagEnv,
    ) {
    }

    public function isAiEnabled(?int $userId = null): bool
    {
        $pinned = $this->featureFlagEnv->forced(self::CONFIG_GROUP, self::KEY_AI_ENABLED);
        if (null !== $pinned) {
            return $pinned;
        }

        return $this->layeredConfigResolver->resolveBool($userId, self::CONFIG_GROUP, self::KEY_AI_ENABLED, true);
    }
}
