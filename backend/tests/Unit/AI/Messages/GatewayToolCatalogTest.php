<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\WebSearchTool;
use App\Entity\User;
use App\Service\Mcp\McpToolRegistry;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

/**
 * Which tools Synaplan offers on a Messages gateway request.
 *
 * The rules pinned here decide whether a Claude Code style client that asks for
 * web search gets a real search or an apology from the model's training data.
 */
final class GatewayToolCatalogTest extends TestCase
{
    private const WEB_SEARCH_SERVER_TOOL = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5];

    public function testWebSearchIsOfferedWhenEnabledAndAvailable(): void
    {
        $snapshot = $this->catalog(webSearchEnabled: true, webSearchAvailable: true)
            ->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertCount(1, $snapshot['tools']);
        self::assertSame(WebSearchTool::NAME, $snapshot['tools'][0]['name']);
        self::assertSame(GatewayToolCatalog::KIND_NATIVE, $snapshot['dispatch'][WebSearchTool::NAME]['kind']);
    }

    public function testWebSearchServerToolIsReportedAsReplaced(): void
    {
        $catalog = $this->catalog(webSearchEnabled: true, webSearchAvailable: true);
        $snapshot = $catalog->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertSame([WebSearchTool::NAME], $catalog->replacedServerTools($snapshot));
    }

    public function testWebSearchIsOfferedWithoutAnyClientDeclaration(): void
    {
        // Most clients never declare a search tool; the operator switch is what
        // decides, so the model is offered search on a plain request too.
        $snapshot = $this->catalog(webSearchEnabled: true, webSearchAvailable: true)
            ->build($this->user(), 'session-1', []);

        self::assertCount(1, $snapshot['tools']);
    }

    public function testWebSearchIsSkippedWhenNoSearchProviderIsConfigured(): void
    {
        $snapshot = $this->catalog(webSearchEnabled: true, webSearchAvailable: false)
            ->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertSame([], $snapshot['tools']);
    }

    public function testWebSearchIsSkippedWhenTheFlagIsOff(): void
    {
        $snapshot = $this->catalog(webSearchEnabled: false, webSearchAvailable: true)
            ->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertSame([], $snapshot['tools']);
    }

    public function testClientToolOfTheSameNameWins(): void
    {
        $snapshot = $this->catalog(webSearchEnabled: true, webSearchAvailable: true)->build(
            $this->user(),
            'session-1',
            ['tools' => [[
                'name' => 'web_search',
                'description' => 'the client runs its own search',
                'input_schema' => ['type' => 'object'],
            ]]],
        );

        self::assertSame([], $snapshot['tools']);
    }

    private function catalog(bool $webSearchEnabled, bool $webSearchAvailable): GatewayToolCatalog
    {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('isWebSearchEnabled')->willReturn($webSearchEnabled);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->method('isAvailable')->willReturn($webSearchAvailable);
        $webSearch->method('declaration')->willReturn([
            'name' => WebSearchTool::NAME,
            'description' => 'Search the live web',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        ]);

        return new GatewayToolCatalog(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $webSearch,
            $config,
            $this->createMock(CacheItemPoolInterface::class),
            new NullLogger(),
        );
    }

    private function user(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        return $user;
    }
}
