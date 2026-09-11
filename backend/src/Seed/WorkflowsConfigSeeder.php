<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\SavedTask\WorkflowsConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for WORKFLOWS.* flags (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only. The Steps editor and webhook trigger seed ON since
 * 4.8; System configuration → Features or `FEATURE_WORKFLOWS_BUILDER_ENABLED=false`
 * turns them off.
 */
final readonly class WorkflowsConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => WorkflowsConfig::CONFIG_GROUP, 'setting' => WorkflowsConfig::KEY_BUILDER_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'workflows_config', $rows);
    }
}
