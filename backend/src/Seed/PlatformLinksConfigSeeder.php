<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\PlatformLink\PlatformLinksConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global platform-links flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Seeds OFF
 * (`0`) so existing installs stay unchanged until an operator enables it.
 */
final readonly class PlatformLinksConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => PlatformLinksConfig::CONFIG_GROUP, 'setting' => PlatformLinksConfig::KEY_ENABLED, 'value' => '0'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'platform_links_config', $rows);
    }
}
