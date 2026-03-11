<?php

namespace App\DataFixtures;

use App\Model\ModelCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads all AI models from the built-in catalog into the database.
 *
 * Also seeds TestProvider models (IDs 9000-9006) for all capability tags.
 * Fixtures only run in dev/test (docker-entrypoint.sh guards this), never in prod.
 * E2E tests select TestProvider explicitly via POST /api/v1/config/models/defaults.
 */
class ModelFixtures extends Fixture
{
    private const TEST_MODELS = [
        ['id' => 9000, 'service' => 'test', 'name' => 'test-model',      'tag' => 'chat',       'providerId' => 'test-model'],
        ['id' => 9001, 'service' => 'test', 'name' => 'test-vectorize',  'tag' => 'vectorize',  'providerId' => 'test-vectorize'],
        ['id' => 9002, 'service' => 'test', 'name' => 'test-pic2text',   'tag' => 'pic2text',   'providerId' => 'test-pic2text'],
        ['id' => 9003, 'service' => 'test', 'name' => 'test-text2pic',   'tag' => 'text2pic',   'providerId' => 'test-text2pic'],
        ['id' => 9004, 'service' => 'test', 'name' => 'test-text2vid',   'tag' => 'text2vid',   'providerId' => 'test-text2vid'],
        ['id' => 9005, 'service' => 'test', 'name' => 'test-sound2text', 'tag' => 'sound2text', 'providerId' => 'test-sound2text'],
        ['id' => 9006, 'service' => 'test', 'name' => 'test-text2sound', 'tag' => 'text2sound', 'providerId' => 'test-text2sound'],
    ];

    public function load(ObjectManager $manager): void
    {
        $connection = $manager->getConnection();

        foreach (self::TEST_MODELS as $base) {
            ModelCatalog::upsert($connection, array_merge($base, [
                'selectable' => 1,
                'active' => 1,
                'priceIn' => 0,
                'inUnit' => '-',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 1,
                'rating' => 0,
                'json' => ['description' => 'Mock model for E2E/CI (TestProvider). No API key required.'],
            ]));
        }

        foreach (ModelCatalog::all() as $data) {
            ModelCatalog::upsert($connection, $data);
        }

        $manager->flush();
    }
}
