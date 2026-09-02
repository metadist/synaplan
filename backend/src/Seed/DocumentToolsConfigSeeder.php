<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Document\DocumentToolsConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for DOCUMENT_TOOLS.* (ownerId=0). Insert-if-missing only.
 * All flags default OFF so classic officemaker stays unchanged.
 */
final readonly class DocumentToolsConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $group = DocumentToolsConfig::CONFIG_GROUP;
        $rows = [
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_MAX_ITERATIONS, 'value' => '8'],
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_MAX_OPS_PER_TURN, 'value' => '24'],
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_KEEP_REVISIONS, 'value' => '10'],
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_ALLOW_UPLOAD_EDIT, 'value' => '0'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'document_tools_config', $rows);
    }
}
