<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Message\Routing\EmbeddingRouterConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global embedding-router flags (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Seeds
 * ENABLED=0: unlike {@see StructuredOutputConfigSeeder} this is a NEW routing
 * surface (not a reliability hardening of an existing path), so it stays
 * off until `app:sort-eval --cascade` demonstrates the embedding layer
 * matches or beats the AI-sorter baseline on the four SYSTEM topics (Phase 8
 * acceptance criterion). The CONFIDENCE_THRESHOLD row is seeded explicitly
 * (rather than left to the code default) so operators discover the tunable
 * in BCONFIG without reading the source.
 *
 * Default OFF means existing installs need no migration to stay behaviour-
 * identical — unlike {@see StructuredOutputConfigSeeder}'s ON default, which
 * needed {@see \DoctrineMigrations\Version20260901000000} to reach installs
 * the seeder alone cannot touch.
 */
final readonly class EmbeddingRouterConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => EmbeddingRouterConfig::CONFIG_GROUP, 'setting' => EmbeddingRouterConfig::KEY_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => EmbeddingRouterConfig::CONFIG_GROUP, 'setting' => EmbeddingRouterConfig::KEY_CONFIDENCE_THRESHOLD, 'value' => '0.88'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'embedding_router_config', $rows);
    }
}
