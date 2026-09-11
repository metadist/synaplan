<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Desktop\DesktopAgentConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global Synaplan Desktop flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. The flag
 * seeds ON (`1`) since 4.8: the Synaplan Desktop client is available as a
 * public beta from GitHub, so the pairing surface is shown by default. System
 * configuration → Features or `FEATURE_DESKTOP_AGENT_ENABLED=false` turns it off.
 */
final readonly class DesktopAgentConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => DesktopAgentConfig::CONFIG_GROUP, 'setting' => DesktopAgentConfig::KEY_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'desktop_agent_config', $rows);
    }
}
