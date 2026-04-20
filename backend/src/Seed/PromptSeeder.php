<?php

declare(strict_types=1);

namespace App\Seed;

use App\Prompt\PromptCatalog;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for system prompts (BPROMPTS where BOWNERID=0).
 *
 * Wraps PromptCatalog::seed(), which itself uses INSERT/UPDATE on
 * (ownerId, topic, language). User-created prompts (ownerId>0) are never touched.
 */
final readonly class PromptSeeder
{
    public function __construct(private Connection $connection)
    {
    }

    public function seed(): SeedResult
    {
        $result = PromptCatalog::seed($this->connection);

        return new SeedResult(
            'prompts',
            inserted: count($result['inserted']),
            updated: count($result['updated']),
        );
    }
}
