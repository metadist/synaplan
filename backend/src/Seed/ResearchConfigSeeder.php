<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Research\Run\ResearchConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Research is on for a new install. Existing rows are never overwritten.
 */
#[AsTaggedItem(priority: 185)]
final readonly class ResearchConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'research';
    }

    public function description(): string
    {
        return 'BCONFIG, group=RESEARCH, ownerId=0 — on; an administrator can turn it off';
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, $this->label(), [
            ['ownerId' => 0, 'group' => ResearchConfig::GROUP, 'setting' => ResearchConfig::ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => ResearchConfig::GROUP, 'setting' => ResearchConfig::MAX_ACTIVE_RUNS, 'value' => '5'],
            ['ownerId' => 0, 'group' => ResearchConfig::GROUP, 'setting' => ResearchConfig::MAX_ACTIVE_THOROUGH, 'value' => '1'],
            ['ownerId' => 0, 'group' => ResearchConfig::GROUP, 'setting' => ResearchConfig::LEAD_MODE, 'value' => 'auto'],
            ['ownerId' => 0, 'group' => ResearchConfig::GROUP, 'setting' => ResearchConfig::TOOL_MODE_MODELS, 'value' => '[]'],
        ]);
    }
}
