<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AgentTemplate;

use App\AI\Provider\TestProvider;
use App\Bundle\BundleEnvelopeValidator;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\AgentTemplate\AgentTemplateCatalog;
use App\Service\AgentTemplate\AgentTemplateRef;
use App\Tests\Support\AgentTemplate\AgentTemplateAcceptanceProvider;
use App\Tests\Support\AgentTemplate\AgentTemplateAcceptanceRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AgentTemplateAcceptanceTest extends TestCase
{
    public function testEveryBuiltInTemplateKeepsItsAcceptanceContract(): void
    {
        $backend = dirname(__DIR__, 4);
        $catalog = new AgentTemplateCatalog(
            $backend.'/templates/agents',
            new BundleEnvelopeValidator(),
            new AgentDefinitionValidator(),
        );
        $templates = $catalog->all();
        self::assertNotEmpty($templates);

        $yamlDir = $backend.'/tests/templates';
        $ids = [];
        foreach ($templates as $template) {
            $ids[] = $template->id;
            $acceptancePath = $yamlDir.'/'.$template->id.'.yaml';
            self::assertFileExists($acceptancePath, $template->id.' has no acceptance file');
            $acceptance = Yaml::parseFile($acceptancePath);
            self::assertIsArray($acceptance);
            self::assertSame($template->id, $acceptance['id']);
            self::assertSame($template->version, $acceptance['version']);
            self::assertIsArray($acceptance['mustMention']);
            foreach ($acceptance['mustMention'] as $phrase) {
                self::assertIsString($phrase);
                self::assertStringContainsString($phrase, $template->body->instruction);
            }
            self::assertLessThanOrEqual(64, strlen(AgentTemplateRef::source($template->id, $template->version)));
            $models = $template->body->definition['models'] ?? [];
            self::assertIsArray($models);
            foreach (['chat', 'vision', 'vectorize'] as $slot) {
                self::assertNull($models[$slot] ?? null, $template->id.' must not name a '.$slot.' model');
            }
            $raw = (string) file_get_contents($backend.'/templates/agents/'.$template->id.'.json');
            self::assertDoesNotMatchRegularExpression('/\b(gpt-\d|claude|llama|gemini|mistral|groq|ollama)\b/i', $raw);
        }

        $yamlIds = [];
        foreach (glob($yamlDir.'/*.yaml') ?: [] as $path) {
            $yamlIds[] = basename($path, '.yaml');
        }
        sort($ids);
        sort($yamlIds);
        self::assertSame($yamlIds, $ids);
    }

    public function testEveryTryItSentenceRunsAgainstTheMockedProvider(): void
    {
        $backend = dirname(__DIR__, 4);
        $catalog = new AgentTemplateCatalog(
            $backend.'/templates/agents',
            new BundleEnvelopeValidator(),
            new AgentDefinitionValidator(),
        );
        $runner = new AgentTemplateAcceptanceRunner(new AgentTemplateAcceptanceProvider(), new TestProvider());
        $failures = [];
        foreach ($catalog->all() as $template) {
            $acceptance = Yaml::parseFile($backend.'/tests/templates/'.$template->id.'.yaml');
            $failures = array_merge($failures, $runner->run($template, $acceptance));
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }

    public function testACitationAppearsOnlyWhenTheInstructionAsksForIt(): void
    {
        $provider = new AgentTemplateAcceptanceProvider();
        $user = $provider->attach('How much is a license?', "price-list.txt\n10 licenses cost 100 EUR.");
        $cited = $provider->chat([
            ['role' => 'system', 'content' => 'Cite the file for each price. Do not invent one.'],
            ['role' => 'user', 'content' => $user],
        ]);
        $plain = $provider->chat([
            ['role' => 'system', 'content' => 'Be helpful. Do not invent one.'],
            ['role' => 'user', 'content' => $user],
        ]);

        self::assertStringContainsString('Source: price-list.txt', $cited['content']);
        self::assertStringContainsString('100 EUR', $cited['content']);
        self::assertStringNotContainsString('Source:', $plain['content']);
    }
}
