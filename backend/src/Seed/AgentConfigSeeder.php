<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Agent\AgentConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global Agent Builder flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Seeds ON
 * (`1`) since 4.8; System configuration → Features or `FEATURE_AGENTS_*=false`
 * turns it off.
 */
final readonly class AgentConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => AgentConfig::CONFIG_GROUP, 'setting' => AgentConfig::KEY_ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => AgentConfig::CONFIG_GROUP, 'setting' => AgentConfig::KEY_ROUTABLE_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'agent_config', $rows);
    }
}
