<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Usage\UsageTaximeterConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

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
#[AsTaggedItem(priority: 230)]
final readonly class UsageTaximeterConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'usage-taximeter';
    }

    public function description(): string
    {
        return 'BCONFIG, group=USAGE_TAXIMETER, ownerId=0 — default ON';
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
