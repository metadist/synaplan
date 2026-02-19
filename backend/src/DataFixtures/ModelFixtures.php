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

        foreach (ModelCatalog::all() as $data) {
            ModelCatalog::upsert($connection, $data);
        }

        $manager->flush();
    }
}
