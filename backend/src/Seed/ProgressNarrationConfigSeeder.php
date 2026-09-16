<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Chat\ProgressNarrationConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the chat progress-narration switches (BCONFIG, ownerId=0).
 *
 * All three seeded ON ('1'): a fresh install shows the full step timeline with
 * model/provider names and durations. Insert-if-missing only — operator
 * overrides are never touched.
 *
 * NOTE: BCONFIG defaults are bootstrap-only. Flipping a default later would
 * NOT propagate to existing installs; that needs an explicit UPDATE migration
 * (see docs/MIGRATIONS.md).
 */
final readonly class ProgressNarrationConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [];
        foreach ([ProgressNarrationConfig::KEY_STEPS, ProgressNarrationConfig::KEY_MODELS, ProgressNarrationConfig::KEY_TIMINGS] as $key) {
            $rows[] = [
                'ownerId' => 0,
                'group' => ProgressNarrationConfig::CONFIG_GROUP,
                'setting' => $key,
                'value' => '1',
            ];
        }

        return BConfigSeeder::insertIfMissing($this->connection, 'progress_narration_config', $rows);
    }
}
