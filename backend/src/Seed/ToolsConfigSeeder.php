<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Tool\ToolsConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for TOOLS.* flags (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only. Registry defaults ON (S1 kill switch). Approvals
 * and custom HTTP stay OFF until an operator enables them.
 */
final readonly class ToolsConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_REGISTRY_ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_APPROVALS_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_CUSTOM_HTTP_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_POLICY_READ, 'value' => 'auto'],
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_POLICY_WRITE, 'value' => 'approve'],
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_POLICY_DESTRUCTIVE, 'value' => 'block'],
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_APPROVAL_EXPIRY_HOURS, 'value' => '72'],
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_CUSTOM_HTTP_ALLOW_PLAIN_HTTP, 'value' => '0'],
            ['ownerId' => 0, 'group' => ToolsConfig::CONFIG_GROUP, 'setting' => ToolsConfig::KEY_CUSTOM_HTTP_MAX_RESPONSE_BYTES, 'value' => '1048576'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'tools_config', $rows);
    }
}
