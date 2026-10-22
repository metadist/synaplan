<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Source;

use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\KnowledgeSearchTool;
use App\AI\Messages\Tools\ReadDocumentTool;
use App\AI\Messages\Tools\WebFetchTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\Service\RAG\KnowledgeSearchHelper;
use App\Service\Tool\SideEffect;
use App\Service\Tool\Source\GatewayBuiltinToolSource;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

final class GatewayBuiltinToolSourceTest extends TestCase
{
    public function testEveryNativeGatewayToolIsRegisteredAsARead(): void
    {
        $declaration = static fn (string $name): array => ['name' => $name, 'description' => $name, 'input_schema' => ['type' => 'object']];
        $webSearch = $this->createStub(WebSearchTool::class);
        $webSearch->method('declaration')->willReturn($declaration(WebSearchTool::NAME));
        $vision = $this->createStub(AnalyzeImageTool::class);
        $vision->method('declaration')->willReturn($declaration(AnalyzeImageTool::NAME));
        $webFetch = $this->createStub(WebFetchTool::class);
        $webFetch->method('declaration')->willReturn($declaration(WebFetchTool::NAME));
        $helper = $this->createStub(KnowledgeSearchHelper::class);

        $source = new GatewayBuiltinToolSource(
            $webSearch,
            $vision,
            $webFetch,
            new KnowledgeSearchTool($helper, new RequestStack(), new NullLogger()),
            new ReadDocumentTool($helper, new RequestStack(), new NullLogger()),
        );

        $byName = [];
        foreach ($source->describe(3) as $descriptor) {
            $byName[$descriptor->name] = $descriptor;
        }

        foreach ([WebSearchTool::NAME, AnalyzeImageTool::NAME, WebFetchTool::NAME, KnowledgeSearchTool::NAME, ReadDocumentTool::NAME] as $name) {
            $this->assertArrayHasKey($name, $byName);
            $this->assertSame(SideEffect::Read, $byName[$name]->sideEffect);
            $this->assertSame(ToolSource::Builtin, $byName[$name]->source);
        }
    }
}
