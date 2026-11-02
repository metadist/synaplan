<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AgentTemplate;

use App\Bundle\BundleEnvelopeValidator;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\AgentTemplate\AgentTemplateCatalog;
use App\Service\AgentTemplate\AgentTemplateRef;
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
}
