<?php

declare(strict_types=1);

namespace App\Seed;

use App\Bundle\BundleConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for BUNDLE.ENABLED (ownerId=0). Insert-if-missing only.
 * Seeded ON since 4.8; System configuration → Features or
 * `FEATURE_BUNDLE_ENABLED=false` turns it off.
 */
#[AsTaggedItem(priority: 140)]
final readonly class BundleConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'bundle';
    }

    public function description(): string
    {
        return 'BCONFIG, group=BUNDLE, ownerId=0 — default ON';
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => BundleConfig::CONFIG_GROUP, 'setting' => BundleConfig::KEY_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'bundle_config', $rows);
    }
}
