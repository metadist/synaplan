<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\OpenAI;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\OpenAI\OpenAiGatewayToolCatalog;
use App\Entity\User;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use PHPUnit\Framework\TestCase;

final class OpenAiGatewayToolCatalogTest extends TestCase
{
    public function testInjectsWebSearchWhenAvailable(): void
    {
        $snapshot = $this->catalog(
            mcpEnabled: false,
            searchAvailable: true,
            searchMode: MessagesGatewayConfig::WEB_SEARCH_AUTO,
        )->build($this->user(), []);

        self::assertSame('web_search', $snapshot['tools'][0]['function']['name']);
        self::assertArrayHasKey('web_search', $snapshot['dispatch']);
    }

    public function testSkipsWebSearchWhenModeOff(): void
    {
        $snapshot = $this->catalog(
            mcpEnabled: false,
            searchAvailable: true,
            searchMode: MessagesGatewayConfig::WEB_SEARCH_OFF,
        )->build($this->user(), []);

        self::assertSame([], $snapshot['tools']);
    }

    public function testSkipsWebSearchWhenUnavailable(): void
    {
        $snapshot = $this->catalog(
            mcpEnabled: false,
            searchAvailable: false,
            searchMode: MessagesGatewayConfig::WEB_SEARCH_AUTO,
        )->build($this->user(), []);

        self::assertSame([], $snapshot['tools']);
    }

    public function testNameCollisionLeavesClientOwningTheName(): void
    {
        $client = [[
            'type' => 'function',
            'function' => ['name' => 'web_search', 'description' => 'client'],
        ]];
        $snapshot = $this->catalog(
            mcpEnabled: false,
            searchAvailable: true,
            searchMode: MessagesGatewayConfig::WEB_SEARCH_AUTO,
        )->build($this->user(), $client);

        self::assertSame([], $snapshot['tools']);
        self::assertArrayNotHasKey('web_search', $snapshot['dispatch']);
    }

    public function testMcpInjectedEvenWhenClientSentTools(): void
    {
        $adapter = $this->createMock(McpToolCatalogAdapter::class);
        $adapter->method('toAnthropicTools')->willReturn([
            'tools' => [[
                'name' => 'mcp__1__rag_search',
                'description' => 'search',
                'input_schema' => ['type' => 'object'],
            ]],
            'dispatch' => [
                'mcp__1__rag_search' => [
                    'serverId' => 1,
                    'tool' => 'rag_search',
                    'annotations' => ['readOnlyHint' => true],
                ],
            ],
        ]);

        $mcpConfig = $this->createMock(McpClientConfig::class);
        $mcpConfig->method('isClientEnabled')->willReturn(true);
        $search = $this->createMock(WebSearchTool::class);
        $search->method('isAvailable')->willReturn(false);
        $gw = $this->createMock(MessagesGatewayConfig::class);
        $gw->method('webSearchMode')->willReturn(MessagesGatewayConfig::WEB_SEARCH_OFF);

        $catalog = new OpenAiGatewayToolCatalog($adapter, $mcpConfig, $search, $gw);
        $snapshot = $catalog->build($this->user(), [[
            'type' => 'function',
            'function' => ['name' => 'get_weather'],
        ]]);

        self::assertSame('mcp__1__rag_search', $snapshot['tools'][0]['function']['name']);
    }

    public function testMcpSkippedWhenClientDisabled(): void
    {
        $adapter = $this->createMock(McpToolCatalogAdapter::class);
        $adapter->expects(self::never())->method('toAnthropicTools');

        $mcpConfig = $this->createMock(McpClientConfig::class);
        $mcpConfig->method('isClientEnabled')->willReturn(false);
        $search = $this->createMock(WebSearchTool::class);
        $search->method('isAvailable')->willReturn(false);
        $gw = $this->createMock(MessagesGatewayConfig::class);
        $gw->method('webSearchMode')->willReturn(MessagesGatewayConfig::WEB_SEARCH_OFF);

        $catalog = new OpenAiGatewayToolCatalog($adapter, $mcpConfig, $search, $gw);
        self::assertSame([], $catalog->build($this->user(), [])['tools']);
    }

    private function catalog(bool $mcpEnabled, bool $searchAvailable, string $searchMode): OpenAiGatewayToolCatalog
    {
        $adapter = new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class));
        $mcpConfig = $this->createMock(McpClientConfig::class);
        $mcpConfig->method('isClientEnabled')->willReturn($mcpEnabled);
        $search = $this->createMock(WebSearchTool::class);
        $search->method('isAvailable')->willReturn($searchAvailable);
        $search->method('declaration')->willReturn((new WebSearchTool(
            $this->createMock(\App\Service\Search\BraveSearchService::class),
            new \Psr\Log\NullLogger(),
        ))->declaration());
        $gw = $this->createMock(MessagesGatewayConfig::class);
        $gw->method('webSearchMode')->willReturn($searchMode);

        return new OpenAiGatewayToolCatalog($adapter, $mcpConfig, $search, $gw);
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        return $user;
    }
}
