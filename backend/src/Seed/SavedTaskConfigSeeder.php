<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\SavedTask\SavedTaskConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for the global Saved Tasks flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched.
 * Local/dev and new installs get ENABLED=1 so the feature is clickable
 * after `app:seed`. Code default remains OFF when no row exists.
 */
#[AsTaggedItem(priority: 190)]
final readonly class SavedTaskConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'saved-tasks';
    }

    public function description(): string
    {
        return 'BCONFIG, group=SAVEDTASKS, ownerId=0 — default ON for new/local';
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => SavedTaskConfig::CONFIG_GROUP, 'setting' => SavedTaskConfig::KEY_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'saved_task_config', $rows);
    }
}
