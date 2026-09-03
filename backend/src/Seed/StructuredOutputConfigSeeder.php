<?php

declare(strict_types=1);

namespace App\Seed;

use App\AI\StructuredOutput\StructuredOutputConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global structured-output flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Seeds ON so
 * fresh OSS installs, dev, and new signups get schema-enforced JSON from day
 * one. Existing installs do NOT get this row from the seeder alone — BCONFIG
 * defaults are bootstrap-only (AGENTS.md) — {@see
 * \DoctrineMigrations\Version20260901000000} inserts the same row for them.
 */
final readonly class StructuredOutputConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => StructuredOutputConfig::CONFIG_GROUP, 'setting' => StructuredOutputConfig::KEY_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'structured_output_config', $rows);
    }
}
