<?php

namespace App\DataFixtures;

use App\Model\ModelCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads all AI models from the built-in catalog into the database.
 *
 * In dev/test: also seeds TestProvider models (negative IDs) for all capability tags.
 * Negative IDs can never collide with auto-increment (which starts at 1 and goes up).
 * selectable=0 keeps them out of user-facing dropdowns; E2E sets them as defaults via API.
 */
class ModelFixtures extends Fixture
{
    public const TEST_MODELS = [
        ['id' => -1, 'service' => 'test', 'name' => 'test-model',      'tag' => 'chat',       'providerId' => 'test-model'],
        ['id' => -2, 'service' => 'test', 'name' => 'test-vectorize',  'tag' => 'vectorize',  'providerId' => 'test-vectorize'],
        ['id' => -3, 'service' => 'test', 'name' => 'test-pic2text',   'tag' => 'pic2text',   'providerId' => 'test-pic2text'],
        ['id' => -4, 'service' => 'test', 'name' => 'test-text2pic',   'tag' => 'text2pic',   'providerId' => 'test-text2pic'],
        ['id' => -5, 'service' => 'test', 'name' => 'test-text2vid',   'tag' => 'text2vid',   'providerId' => 'test-text2vid'],
        ['id' => -6, 'service' => 'test', 'name' => 'test-sound2text', 'tag' => 'sound2text', 'providerId' => 'test-sound2text'],
        ['id' => -7, 'service' => 'test', 'name' => 'test-text2sound', 'tag' => 'text2sound', 'providerId' => 'test-text2sound'],
    ];

    public function load(ObjectManager $manager): void
    {
        $connection = $manager->getConnection();

        $env = getenv('APP_ENV') ?: 'prod';
        if (in_array($env, ['dev', 'test'], true)) {
            foreach (self::TEST_MODELS as $base) {
                ModelCatalog::upsert($connection, array_merge($base, [
                    'selectable' => 0,
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
        }

        foreach (ModelCatalog::all() as $data) {
            ModelCatalog::upsert($connection, $data);
        }

        $manager->flush();
    }
}
