<?php

declare(strict_types=1);

namespace App\Seed;

use App\Prompt\PromptCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for system prompts (BPROMPTS where BOWNERID=0).
 *
 * Wraps PromptCatalog::seed(), which itself uses INSERT/UPDATE on
 * (ownerId, topic, language). User-created prompts (ownerId>0) are never touched.
 */
#[AsTaggedItem(priority: 330)]
final readonly class PromptSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'prompts';
    }

    public function description(): string
    {
        return 'BPROMPTS, ownerId=0';
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
