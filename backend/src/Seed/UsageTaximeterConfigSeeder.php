<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\UsageTaximeterConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global usage-taximeter switch (BCONFIG, ownerId=0).
 *
 * Seeded ON ('1') so fresh/OSS/self-hosted installs show the in-chat usage
 * display by default (a transparency feature). Insert-if-missing only —
 * operator overrides are never touched.
 *
 * NOTE: BCONFIG defaults are bootstrap-only. Flipping this default later would
 * NOT propagate to existing installs; that would require an explicit UPDATE
 * migration (see docs/MIGRATIONS.md).
 */
final readonly class UsageTaximeterConfigSeeder
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
                'group' => UsageTaximeterConfig::CONFIG_GROUP,
                'setting' => UsageTaximeterConfig::KEY_ENABLED,
                'value' => '1',
            ],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'usage_taximeter_config', $rows);
    }
}
