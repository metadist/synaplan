<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\Entity\McpServerConfig;
use App\Service\Mcp\McpToolRegistry;
use PHPUnit\Framework\TestCase;

final class McpToolCatalogAdapterTest extends TestCase
{
    public function testNamespacesAndSanitisesToolNames(): void
    {
        $adapter = new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class));

        $this->assertSame('mcp__3__rag_search', $adapter->namespace(3, 'rag_search'));
        $this->assertSame('mcp__3__weird_tool_name', $adapter->namespace(3, 'weird.tool/name'));
        $this->assertTrue($adapter->isOurs('mcp__1__x'));
        $this->assertFalse($adapter->isOurs('Bash'));
        $this->assertSame(
            ['serverId' => 7, 'tool' => 'memory_search'],
            $adapter->parseNamespaced('mcp__7__memory_search'),
        );
    }

    public function testToAnthropicToolsSortsAndSkipsMutating(): void
    {
        $serverA = $this->server(2, 'Alpha');
        $serverB = $this->server(1, 'Beta');

        $registry = $this->createMock(McpToolRegistry::class);
        $registry->method('catalogForUser')->with(9)->willReturn([
            [
                'server' => $serverA,
                'tools' => [
                    [
                        'name' => 'zeta',
                        'description' => 'Z',
                        'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
                        'annotations' => [],
                    ],
                    [
                        'name' => 'delete',
                        'description' => 'bad',
                        'inputSchema' => [],
                        'annotations' => ['destructiveHint' => true],
                    ],
                ],
            ],
            [
                'server' => $serverB,
                'tools' => [
                    [
                        'name' => 'alpha',
                        'description' => 'A',
                        'inputSchema' => ['type' => 'object'],
                        'annotations' => ['readOnlyHint' => true],
                    ],
                ],
            ],
        ]);

        $adapter = new McpToolCatalogAdapter($registry);
        $snapshot = $adapter->toAnthropicTools(9);

        $names = array_column($snapshot['tools'], 'name');
        $this->assertSame(['mcp__1__alpha', 'mcp__2__zeta'], $names);
        $this->assertArrayHasKey('mcp__1__alpha', $snapshot['dispatch']);
        $this->assertArrayNotHasKey('mcp__2__delete', $snapshot['dispatch']);
        $this->assertSame('object', $snapshot['tools'][0]['input_schema']['type']);
    }

    private function server(int $id, string $name): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(9);
        $server->setName($name);
        $server->setUrl('https://example.test/mcp');
        $server->setEnabled(true);

        $ref = new \ReflectionProperty(McpServerConfig::class, 'id');
        $ref->setValue($server, $id);

        return $server;
    }
}
