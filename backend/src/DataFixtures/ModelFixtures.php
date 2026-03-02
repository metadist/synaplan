<?php

namespace App\DataFixtures;

use App\Model\ModelCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads all AI models from the built-in catalog into the database.
 */
class ModelFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $connection = $manager->getConnection();

        $testModel = [
            'id' => 900,
            'service' => 'test',
            'name' => 'test-model',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'test-model',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 1,
            'rating' => 0,
            'json' => [
                'description' => 'Mock model for E2E/CI (TestProvider). No API key required.',
            ],
        ];
        ModelCatalog::upsert($connection, $testModel);

        foreach (ModelCatalog::all() as $data) {
            ModelCatalog::upsert($connection, $data);
        }

        $manager->flush();
    }
}
