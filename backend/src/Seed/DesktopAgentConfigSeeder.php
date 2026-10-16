<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Desktop\DesktopAgentConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for the global Synaplan Desktop flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. The flag
 * seeds ON (`1`) since 4.8, so the pairing surface is shown by default.
 * There is no signed installer; the app is built from the source repository.
 * System configuration → Features or `FEATURE_DESKTOP_AGENT_ENABLED=false` turns it off.
 */
#[AsTaggedItem(priority: 170)]
final readonly class DesktopAgentConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'desktop-agent';
    }

    public function description(): string
    {
        return 'BCONFIG, group=DESKTOP_AGENT, ownerId=0 — default ON';
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => DesktopAgentConfig::CONFIG_GROUP, 'setting' => DesktopAgentConfig::KEY_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'desktop_agent_config', $rows);
    }
}
