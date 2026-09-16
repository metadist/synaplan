<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\SelfAware\SelfAwareConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for SELF_AWARE flags (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched.
 */
final readonly class SelfAwareConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            [
                'ownerId' => 0,
                'group' => SelfAwareConfig::CONFIG_GROUP,
                'setting' => SelfAwareConfig::KEY_ENABLED,
                'value' => '1',
            ],
            [
                'ownerId' => 0,
                'group' => SelfAwareConfig::CONFIG_GROUP,
                'setting' => SelfAwareConfig::KEY_INVENTORY_IN_GENERAL,
                'value' => '1',
            ],
            [
                'ownerId' => 0,
                'group' => SelfAwareConfig::CONFIG_GROUP,
                'setting' => SelfAwareConfig::KEY_DOCS_RAG_ENABLED,
                'value' => '1',
            ],
            [
                'ownerId' => 0,
                'group' => SelfAwareConfig::CONFIG_GROUP,
                'setting' => SelfAwareConfig::KEY_DOCS_MANIFEST_URL,
                'value' => SelfAwareConfig::DEFAULT_DOCS_MANIFEST_URL,
            ],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'self_aware_config', $rows);
    }
}
