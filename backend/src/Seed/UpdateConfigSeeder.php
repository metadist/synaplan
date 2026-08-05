<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Update\UpdateConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the update-notice config (BCONFIG, ownerId=0).
 *
 * Seeds the two operator-owned settings (master switch ON, manifest URL) plus
 * the result fields the daily check writes — the latter as empty strings, so the
 * rows are visible and editable before the first check has ever run.
 * Insert-if-missing only; operator overrides are never touched.
 *
 * NOTE: BCONFIG defaults are bootstrap-only. Nothing here propagates to an
 * existing install until `app:seed` runs, which is why every read in
 * {@see UpdateConfig} falls back to the same built-in default when its row is
 * missing. Changing a default later would need an explicit UPDATE migration
 * (see docs/MIGRATIONS.md).
 */
final readonly class UpdateConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $group = UpdateConfig::CONFIG_GROUP;

        $rows = [
            [
                'ownerId' => UpdateConfig::OWNER_ID,
                'group' => $group,
                'setting' => UpdateConfig::KEY_CHECK_ENABLED,
                // Master switch ON: detection never changes the installation,
                // and UpdateConfig applies the same default when the row is
                // missing entirely.
                'value' => '1',
            ],
            [
                'ownerId' => UpdateConfig::OWNER_ID,
                'group' => $group,
                'setting' => UpdateConfig::KEY_MANIFEST_URL,
                'value' => UpdateConfig::DEFAULT_MANIFEST_URL,
            ],
        ];

        $resultKeys = [
            UpdateConfig::KEY_LATEST_VERSION,
            UpdateConfig::KEY_LATEST_NOTES_URL,
            UpdateConfig::KEY_LATEST_SEVERITY,
            UpdateConfig::KEY_LATEST_RELEASED_AT,
            UpdateConfig::KEY_LAST_CHECKED_AT,
            UpdateConfig::KEY_LAST_ERROR,
            UpdateConfig::KEY_DISMISSED_VERSION,
        ];

        foreach ($resultKeys as $setting) {
            $rows[] = [
                'ownerId' => UpdateConfig::OWNER_ID,
                'group' => $group,
                'setting' => $setting,
                'value' => '',
            ];
        }

        return BConfigSeeder::insertIfMissing($this->connection, 'update_config', $rows);
    }
}
