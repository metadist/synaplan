<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Default QDRANT_SEARCH routing toggles (insert-if-missing only).
 */
final class SynapseRoutingConfigSeeder
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, 'synapse-routing-config', [
            [
                'ownerId' => 0,
                'group' => 'QDRANT_SEARCH',
                'setting' => 'USE_CASE_ROUTING_ENABLED',
                'value' => 'true',
            ],
            [
                'ownerId' => 0,
                'group' => 'QDRANT_SEARCH',
                'setting' => 'SYNAPSE_COMPOUND_CONFIDENCE_THRESHOLD',
                'value' => '0.55',
            ],
        ]);
    }
}
