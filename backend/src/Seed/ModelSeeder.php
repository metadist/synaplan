<?php

declare(strict_types=1);

namespace App\Seed;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the AI model catalog (BMODELS).
 *
 * - Always upserts every model defined in App\Model\ModelCatalog::all() (positive IDs).
 * - In dev/test, additionally upserts mock test-models with negative IDs (so they can
 *   never collide with the auto-increment range used by real catalog rows).
 * - In `test`, also flips test models to selectable/showWhenFree so the admin UI
 *   exposes them in the E2E stack.
 *
 * Safe to run repeatedly: ModelCatalog::upsert() uses INSERT ... ON DUPLICATE KEY UPDATE.
 */
final class ModelSeeder
{
    /**
     * Mock models for E2E/CI stacks. Negative IDs cannot collide with auto-increment.
     * Routed through TestProvider/OllamaProvider stubs.
     */
    public const TEST_MODELS = [
        ['id' => -1, 'service' => 'test', 'name' => 'test-model',      'tag' => 'chat',       'providerId' => 'test-model'],
        ['id' => -2, 'service' => 'test', 'name' => 'test-vectorize',  'tag' => 'vectorize',  'providerId' => 'test-vectorize'],
        ['id' => -3, 'service' => 'test', 'name' => 'test-pic2text',   'tag' => 'pic2text',   'providerId' => 'test-pic2text'],
        ['id' => -4, 'service' => 'test', 'name' => 'test-text2pic',   'tag' => 'text2pic',   'providerId' => 'test-text2pic'],
        ['id' => -5, 'service' => 'test', 'name' => 'test-text2vid',   'tag' => 'text2vid',   'providerId' => 'test-text2vid'],
        ['id' => -6, 'service' => 'test', 'name' => 'test-sound2text', 'tag' => 'sound2text', 'providerId' => 'test-sound2text'],
        ['id' => -7, 'service' => 'test', 'name' => 'test-text2sound', 'tag' => 'text2sound', 'providerId' => 'test-text2sound'],
        ['id' => -10, 'service' => 'ollama', 'name' => 'stub-chat-model',  'tag' => 'chat',      'providerId' => 'stub-chat-model'],
        ['id' => -11, 'service' => 'ollama', 'name' => 'stub-embed-model', 'tag' => 'vectorize', 'providerId' => 'stub-embed-model'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $environment,
    ) {
    }

    public function seed(): SeedResult
    {
        $upserted = 0;

        if (in_array($this->environment, ['dev', 'test'], true)) {
            foreach (self::TEST_MODELS as $base) {
                ModelCatalog::upsert($this->connection, array_merge($base, [
                    'selectable' => 0,
                    'active' => 1,
                    'priceIn' => 0,
                    'inUnit' => '-',
                    'priceOut' => 0,
                    'outUnit' => '-',
                    'quality' => 1,
                    'rating' => 0,
                    'json' => ['description' => 'Mock model for E2E/CI. No API key required.'],
                ]));
                ++$upserted;
            }

            if ('test' === $this->environment) {
                $this->connection->executeStatement(
                    'UPDATE BMODELS SET BSELECTABLE = 1, BSHOWWHENFREE = 1 WHERE BID < 0'
                );
            }
        }

        foreach (ModelCatalog::all() as $data) {
            ModelCatalog::upsert($this->connection, $data);
            ++$upserted;
        }

        return new SeedResult('models', inserted: $upserted);
    }
}
