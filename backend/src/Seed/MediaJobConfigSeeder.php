<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Media\MediaJobConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global async media-job flag (BCONFIG, ownerId=0).
 *
 * Seeds the explicit global row so the value is visible/toggleable rather than
 * relying purely on the code default. Insert-if-missing only — operator
 * overrides are never touched.
 *
 * Global default ON means OSS, fresh installs, dev, and new signups get async
 * media (image + video + audio detach to a background job). Existing users on
 * the live platform are grandfathered to OFF by the dedicated data migration,
 * NOT by this seeder.
 */
final readonly class MediaJobConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => MediaJobConfig::CONFIG_GROUP, 'setting' => MediaJobConfig::KEY_ASYNC_JOBS_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'media_job_config', $rows);
    }
}
