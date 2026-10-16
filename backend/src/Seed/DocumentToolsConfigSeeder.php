<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Document\DocumentToolsConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for DOCUMENT_TOOLS.* (ownerId=0). Insert-if-missing only.
 * ENABLED seeds ON since 4.8 (System configuration → Features or
 * `FEATURE_DOCUMENT_TOOLS_ENABLED=false` turns it off); the limits keep their
 * conservative defaults and editing uploaded files stays opt-in.
 */
#[AsTaggedItem(priority: 80)]
final readonly class DocumentToolsConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'document-tools';
    }

    public function description(): string
    {
        return 'BCONFIG, group=DOCUMENT_TOOLS, ownerId=0 — ENABLED default ON';
    }

    public function seed(): SeedResult
    {
        $group = DocumentToolsConfig::CONFIG_GROUP;
        $rows = [
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_MAX_ITERATIONS, 'value' => '8'],
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_MAX_OPS_PER_TURN, 'value' => '24'],
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_KEEP_REVISIONS, 'value' => '10'],
            ['ownerId' => 0, 'group' => $group, 'setting' => DocumentToolsConfig::KEY_ALLOW_UPLOAD_EDIT, 'value' => '0'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'document_tools_config', $rows);
    }
}
