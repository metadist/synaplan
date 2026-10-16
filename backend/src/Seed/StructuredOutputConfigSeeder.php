<?php

declare(strict_types=1);

namespace App\Seed;

use App\AI\StructuredOutput\StructuredOutputConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for the global structured-output flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Seeds ON so
 * fresh OSS installs, dev, and new signups get schema-enforced JSON from day
 * one. Existing installs do NOT get this row from the seeder alone — BCONFIG
 * defaults are bootstrap-only (AGENTS.md) — {@see
 * \DoctrineMigrations\Version20260901000000} inserts the same row for them.
 */
#[AsTaggedItem(priority: 110)]
final readonly class StructuredOutputConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'structured-output';
    }

    public function description(): string
    {
        return 'BCONFIG, group=STRUCTURED_OUTPUT, ownerId=0 — default ON';
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => StructuredOutputConfig::CONFIG_GROUP, 'setting' => StructuredOutputConfig::KEY_ENABLED, 'value' => '1'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'structured_output_config', $rows);
    }
}
