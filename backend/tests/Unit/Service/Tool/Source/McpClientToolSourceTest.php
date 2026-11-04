<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Source;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\Entity\McpServerConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Tool\SideEffect;
use App\Service\Tool\Source\McpClientToolSource;
use PHPUnit\Framework\TestCase;

final class McpClientToolSourceTest extends TestCase
{
    public function testASharedConnectionPublishesReadsAndHidesWrites(): void
    {
        $server = $this->createStub(McpServerConfig::class);
        $server->method('getId')->willReturn(9);
        $server->method('getName')->willReturn('Company Notion');
        $registry = $this->createStub(McpToolRegistry::class);
        $registry->method('catalogForUser')->willReturn([]);
        $registry->method('sharedCatalogFor')->willReturn([[
            'server' => $server,
            'tools' => [
                $this->tool('search', true),
                $this->tool('update', false),
            ],
        ]]);
        $adapter = $this->createStub(McpToolCatalogAdapter::class);
        $adapter->method('namespace')->willReturn('mcp__9__search');

        $tools = (new McpClientToolSource($registry, $adapter))->describe(7);

        self::assertCount(1, $tools);
        self::assertSame('mcp:9:search', $tools[0]->name);
        self::assertSame(SideEffect::Read, $tools[0]->sideEffect);
        self::assertFalse($tools[0]->meta['allowWrite']);
        self::assertTrue($tools[0]->meta['shared']);
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    private function tool(string $name, bool $readOnly): array
    {
        return [
            'name' => $name,
            'description' => $name,
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => $readOnly],
        ];
    }
}
