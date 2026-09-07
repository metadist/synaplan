<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Iam\IamConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global IAM flags (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Feature
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
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_GROUP_POLICIES_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_DIRECTORY_GROUPS_CLAIM, 'value' => IamConfig::DEFAULT_DIRECTORY_GROUPS_CLAIM],
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_DIRECTORY_GROUP_NAMES, 'value' => '{}'],
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_EVERYONE_SHARES, 'value' => IamConfig::EVERYONE_SHARES_ANY_OWNER],
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_ADMIN_IMPERSONATION, 'value' => IamConfig::IMPERSONATION_AUDITED],
            ['ownerId' => 0, 'group' => IamConfig::CONFIG_GROUP, 'setting' => IamConfig::KEY_AUDIT_RETENTION_DAYS, 'value' => (string) IamConfig::DEFAULT_AUDIT_RETENTION_DAYS],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'iam_config', $rows);
    }
}
