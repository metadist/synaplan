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
        // In the `test` env, divert to a TestProvider-backed config so
        // phpunit + E2E suites can exercise the full controller →
        // AiFacade path without real provider API keys. Dev keeps the
        // real catalog — developers need real models.
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod';
        if ('test' === $env) {
            $this->loadTestConfig($manager);

            return;
        }

        $configs = [
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'CHAT',       'value' => '180'],  // OpenAI GPT-5.4 (fallback: Ollama if no key)
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TOOLS',      'value' => '180'],  // OpenAI GPT-5.4
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SORT',       'value' => '76'],   // Groq gpt-oss-120b (cost-efficient)
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE',  'value' => '76'],   // Groq gpt-oss-120b
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2PIC',   'value' => '190'],  // Google Nano Banana 2 (3.1 Flash Image)
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2PIC',    'value' => '190'],  // Google Nano Banana 2
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2VID',   'value' => '45'],   // Veo 3.1
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2SOUND', 'value' => '140'],  // Piper (free)
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2TEXT',   'value' => '17'],   // Groq Llama 4 Scout
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SOUND2TEXT', 'value' => '21'],   // Groq whisper-large-v3
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'ANALYZE',    'value' => '180'],  // OpenAI GPT-5.4
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'VECTORIZE',  'value' => '13'],   // Ollama bge-m3

            ['ownerId' => 0, 'group' => 'ai', 'setting' => 'default_chat_provider', 'value' => 'openai'],

            // Example Widget Config (for user 2)
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'color', 'value' => '#007bff'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'position', 'value' => 'bottom-right'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'autoMessage', 'value' => 'Hello! How can I help you today?'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'prompt', 'value' => 'general'],
        ];

        $this->persistConfigs($manager, $configs);
    }

    /**
     * Test-env variant: points each AI default model at the matching
     * TestProvider row seeded by ModelFixtures::TEST_MODELS (negative
     * IDs). Widget / provider defaults stay the same as prod.
     */
    private function loadTestConfig(ObjectManager $manager): void
    {
        $configs = [
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'CHAT',       'value' => '-1'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TOOLS',      'value' => '-1'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SORT',       'value' => '-1'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE',  'value' => '-1'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'ANALYZE',    'value' => '-1'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'VECTORIZE',  'value' => '-2'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2TEXT',   'value' => '-3'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2PIC',   'value' => '-4'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2PIC',    'value' => '-4'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2VID',   'value' => '-5'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SOUND2TEXT', 'value' => '-6'],
            ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2SOUND', 'value' => '-7'],

            ['ownerId' => 0, 'group' => 'ai', 'setting' => 'default_chat_provider', 'value' => 'test'],

            // Example Widget Config (for user 2)
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'color', 'value' => '#007bff'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'position', 'value' => 'bottom-right'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'autoMessage', 'value' => 'Hello! How can I help you today?'],
            ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'prompt', 'value' => 'general'],
        ];

        $this->persistConfigs($manager, $configs);
    }

    /**
     * @param list<array{ownerId: int, group: string, setting: string, value: string}> $configs
     */
    private function persistConfigs(ObjectManager $manager, array $configs): void
    {
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
