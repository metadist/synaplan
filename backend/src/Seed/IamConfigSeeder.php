<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Iam\IamConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global IAM flags (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. All three
 * flags seed OFF so existing and new installs stay byte-identical until an
 * operator turns groups on (invariant C1).
 */
final readonly class IamConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_GROUPS_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_SHARING_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_DIRECTORY_SYNC_ENABLED, 'value' => '0'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'iam_config', $rows);
    }
}
