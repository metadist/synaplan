<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Agent\AgentConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for the global Agent Builder flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Seeds ON
 * (`1`) since 4.8; System configuration → Features or `FEATURE_AGENTS_*=false`
 * turns it off.
 */
#[AsTaggedItem(priority: 150)]
final readonly class AgentConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'agents';
    }

    public function description(): string
    {
        return 'BCONFIG, group=AGENTS, ownerId=0 — default ON';
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
