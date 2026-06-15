<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Multitask\MultitaskRoutingConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global multi-task routing flags (BCONFIG, ownerId=0).
 *
 * Seeds the explicit global rows so the values are visible/toggleable rather
 * than relying purely on the code default. Insert-if-missing only — operator
 * overrides are never touched.
 *
 * Global default ON means OSS, fresh installs, dev, and new signups get the new
 * routing. Existing users on the live platform are grandfathered to OFF by the
 * dedicated data migration, NOT by this seeder.
 */
final readonly class MultitaskConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => MultitaskRoutingConfig::CONFIG_GROUP, 'setting' => MultitaskRoutingConfig::KEY_ROUTING_ENABLED,  'value' => '1'],
            ['ownerId' => 0, 'group' => MultitaskRoutingConfig::CONFIG_GROUP, 'setting' => MultitaskRoutingConfig::KEY_SHADOW_MODE,      'value' => '0'],
            ['ownerId' => 0, 'group' => MultitaskRoutingConfig::CONFIG_GROUP, 'setting' => MultitaskRoutingConfig::KEY_PARALLEL_ENABLED, 'value' => '0'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'multitask_config', $rows);
    }
}
