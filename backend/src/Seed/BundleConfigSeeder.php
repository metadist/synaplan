<?php

declare(strict_types=1);

namespace App\Seed;

use App\Bundle\BundleConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for BUNDLE.ENABLED (ownerId=0). Insert-if-missing only.
 * Seeded OFF so existing installs stay unchanged.
 */
final readonly class BundleConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => BundleConfig::CONFIG_GROUP, 'setting' => BundleConfig::KEY_ENABLED, 'value' => '0'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'bundle_config', $rows);
    }
}
