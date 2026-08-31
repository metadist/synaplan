<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Desktop\DesktopAgentConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global Synaplan Desktop flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Unlike most
 * feature seeders, this one seeds the flag OFF (`0`) for every install,
 * including new / local-dev ones: the desktop client does not exist yet
 * (server-first order, master plan decision 21), so the surface stays inert
 * until an operator or a later release turns it on.
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
            ['ownerId' => 0, 'group' => DesktopAgentConfig::CONFIG_GROUP, 'setting' => DesktopAgentConfig::KEY_ENABLED, 'value' => '0'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'desktop_agent_config', $rows);
    }
}
