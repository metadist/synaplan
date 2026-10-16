<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\File\GeneratedImageVisionFlag;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

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
#[AsTaggedItem(priority: 180)]
final readonly class FileContextConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'file-context';
    }

    public function description(): string
    {
        return 'BCONFIG, group=FILE_CONTEXT, ownerId=0 — default OFF';
    }

    public function seed(): SeedResult
    {
        $rows = [
            // ON: a follow-up like "what is in that picture?" needs the pixels
            // (#1596). The operator can flip this to 0 to stop sending base64.
            [
                'ownerId' => 0,
                'group' => GeneratedImageVisionFlag::CONFIG_GROUP,
                'setting' => GeneratedImageVisionFlag::KEY_VISION_INCLUDE_GENERATED,
                'value' => '1',
            ],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'file_context_config', $rows);
    }
}
