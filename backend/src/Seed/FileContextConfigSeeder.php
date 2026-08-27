<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\File\GeneratedImageVisionFlag;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the conversation file-context flags (BCONFIG, ownerId=0).
 *
 * Seeds the explicit global row so the switch is visible/toggleable in the admin
 * System Config UI instead of living only as a code default. Insert-if-missing
 * only — operator overrides are never touched.
 *
 * NOTE: BCONFIG defaults are bootstrap-only. Flipping a default here would NOT
 * propagate to existing installs; that needs an explicit UPDATE migration
 * (see docs/MIGRATIONS.md).
 */
final readonly class FileContextConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            // OFF: every generated image included costs a base64 payload on each
            // following request of the conversation.
            [
                'ownerId' => 0,
                'group' => GeneratedImageVisionFlag::CONFIG_GROUP,
                'setting' => GeneratedImageVisionFlag::KEY_VISION_INCLUDE_GENERATED,
                'value' => '0',
            ],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'file_context_config', $rows);
    }
}
