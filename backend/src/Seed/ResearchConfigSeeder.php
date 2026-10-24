<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Research\Run\ResearchConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Research stays off on a new install. Existing rows are never overwritten.
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
        return 'BCONFIG, group=RESEARCH, ownerId=0 — off until an administrator turns it on';
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, $this->label(), [
            ['ownerId' => 0, 'group' => ResearchConfig::GROUP, 'setting' => ResearchConfig::ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => ResearchConfig::GROUP, 'setting' => ResearchConfig::MAX_ACTIVE_RUNS, 'value' => '5'],
            ['ownerId' => 0, 'group' => ResearchConfig::GROUP, 'setting' => ResearchConfig::MAX_ACTIVE_THOROUGH, 'value' => '1'],
        ]);
    }
}
