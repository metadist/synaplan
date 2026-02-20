<?php

namespace App\DataFixtures;

use App\Entity\Prompt;
use App\Prompt\PromptCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads system prompts from the built-in catalog via Doctrine fixtures.
 */
class PromptFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        foreach (PromptCatalog::all() as $data) {
            $prompt = new Prompt();
            $prompt->setOwnerId(0);
            $prompt->setLanguage($data['language']);
            $prompt->setTopic($data['topic']);
            $prompt->setShortDescription($data['shortDescription']);
            $prompt->setPrompt($data['prompt']);

            $manager->persist($prompt);
        }

        $manager->flush();
    }
}
