<?php

namespace App\DataFixtures;

use App\Entity\Config;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads system configuration from BCONFIG table.
 */
class ConfigFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Use TestProvider (model 900) in test env so tests don't call real APIs; Groq elsewhere.
        $isTest = 'test' === (getenv('APP_ENV') ?: '');
        $chatModel = $isTest ? '900' : '76';   // Groq gpt-oss-120b
        $sortModel = $isTest ? '900' : '9';    // Groq Llama 3.3 70b
        $defaultProvider = $isTest ? 'test' : 'groq';

        $configs = [
            // DEFAULTMODEL: value = BMODELS BID. TestProvider (900) in test, Groq in dev/prod.
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'CHAT', 'value' => $chatModel],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TOOLS', 'value' => $chatModel],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SORT', 'value' => $sortModel],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE', 'value' => $sortModel],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2PIC', 'value' => '151'],  // gpt-image-1.5
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2VID', 'value' => '45'],   // Veo 3.1
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2SOUND', 'value' => '140'], // Piper (free)
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2TEXT', 'value' => '17'],   // Groq Llama 4 Scout
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SOUND2TEXT', 'value' => '21'], // Groq whisper-large-v3
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'VECTORIZE', 'value' => '13'],  // Ollama bge-m3

            // AI Provider Config
            ['ownerId' => 0, 'group' => 'ai', 'setting' => 'default_chat_provider', 'value' => $defaultProvider],

            // Example Widget Config (for user 2)
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'color', 'value' => '#007bff'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'position', 'value' => 'bottom-right'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'autoMessage', 'value' => 'Hello! How can I help you today?'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'prompt', 'value' => 'general'],
        ];

        foreach ($configs as $data) {
            $config = new Config();
            $config->setOwnerId($data['ownerId']);
            $config->setGroup($data['group']);
            $config->setSetting($data['setting']);
            $config->setValue($data['value']);

            $manager->persist($config);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ModelFixtures::class,
        ];
    }
}
