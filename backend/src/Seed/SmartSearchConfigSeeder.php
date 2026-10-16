<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\SmartSearch\SmartSearchConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for SEARCH.* (ownerId=0). Insert-if-missing only.
 * AI_ENABLED seeds ON (System configuration → Features or
 * `FEATURE_SEARCH_AI_ENABLED=false` turns it off).
 */
#[AsTaggedItem(priority: 70)]
final readonly class SmartSearchConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'smart-search';
    }

    public function description(): string
    {
        return 'BCONFIG, group=SEARCH, ownerId=0';
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => SmartSearchConfig::CONFIG_GROUP, 'setting' => SmartSearchConfig::KEY_AI_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'smart_search_config', $rows);
    }
}
