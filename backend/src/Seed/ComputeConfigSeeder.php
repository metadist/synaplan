<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Compute\ComputeConfig;
use Doctrine\DBAL\Connection;

/**
 * Bootstrap-only COMPUTE.* defaults. Existing rows are never overwritten.
 */
final readonly class ComputeConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $group = ComputeConfig::CONFIG_GROUP;
        $rows = [
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_TIMEOUT_SEC, 'value' => '60'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_MEMORY_MB, 'value' => '512'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_CPU, 'value' => '1.0'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_PIDS, 'value' => '128'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_OUTPUT_MB, 'value' => '50'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_MAX_TIMEOUT_SEC, 'value' => '300'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'compute_config', $rows);
    }
}
