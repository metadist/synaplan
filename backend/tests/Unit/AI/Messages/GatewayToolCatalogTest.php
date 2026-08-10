<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\AnalyzeImageTool;
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
 * How a Claude Code style `web_search` declaration is answered.
 *
 * The rules pinned here decide whether the client gets a real search, a plain
 * passthrough that only api.anthropic.com can honour, or nothing at all — the
 * difference between a working answer and an apology from training data.
 */
final class GatewayToolCatalogTest extends TestCase
{
    private const WEB_SEARCH_SERVER_TOOL = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5];

    public function testAutoRunsTheSearchWhenAProviderIsConfigured(): void
    {
        $snapshot = $this->catalog(MessagesGatewayConfig::WEB_SEARCH_AUTO, searchConfigured: true)
            ->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertSame(GatewayToolCatalog::WEB_SEARCH_SYNAPLAN, $snapshot['web_search']);
        self::assertCount(1, $snapshot['tools']);
        self::assertSame(WebSearchTool::NAME, $snapshot['tools'][0]['name']);
        self::assertSame(GatewayToolCatalog::KIND_NATIVE, $snapshot['dispatch'][WebSearchTool::NAME]['kind']);
    }

    public function testAutoFallsBackToPassthroughWithoutASearchProvider(): void
    {
        // No provider to run the search with, so the declaration is forwarded:
        // api.anthropic.com can still honour it, and nothing is lost elsewhere.
        $catalog = $this->catalog(MessagesGatewayConfig::WEB_SEARCH_AUTO, searchConfigured: false);
        $snapshot = $catalog->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertSame(GatewayToolCatalog::WEB_SEARCH_PASSTHROUGH, $snapshot['web_search']);
        self::assertSame([], $snapshot['tools']);
        self::assertSame([], $catalog->replacedServerTools($snapshot));
    }

    public function testAutoDoesNotOfferSearchToAClientThatNeverAskedForIt(): void
    {
        $snapshot = $this->catalog(MessagesGatewayConfig::WEB_SEARCH_AUTO, searchConfigured: true)
            ->build($this->user(), 'session-1', []);

        self::assertSame(GatewayToolCatalog::WEB_SEARCH_NONE, $snapshot['web_search']);
        self::assertSame([], $snapshot['tools']);
    }

    public function testSynaplanModeOffersSearchEvenWithoutADeclaration(): void
    {
        $snapshot = $this->catalog(MessagesGatewayConfig::WEB_SEARCH_SYNAPLAN, searchConfigured: true)
            ->build($this->user(), 'session-1', []);

        self::assertSame(GatewayToolCatalog::WEB_SEARCH_SYNAPLAN, $snapshot['web_search']);
        self::assertCount(1, $snapshot['tools']);
    }

    public function testPassthroughModeNeverReplacesTheDeclaration(): void
    {
        $catalog = $this->catalog(MessagesGatewayConfig::WEB_SEARCH_PASSTHROUGH, searchConfigured: true);
        $snapshot = $catalog->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertSame(GatewayToolCatalog::WEB_SEARCH_PASSTHROUGH, $snapshot['web_search']);
        self::assertSame([], $snapshot['tools']);
        self::assertSame([], $catalog->replacedServerTools($snapshot));
    }

    public function testOffModeStripsTheDeclaration(): void
    {
        $catalog = $this->catalog(MessagesGatewayConfig::WEB_SEARCH_OFF, searchConfigured: true);
        $snapshot = $catalog->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertSame(GatewayToolCatalog::WEB_SEARCH_OFF, $snapshot['web_search']);
        self::assertSame([], $snapshot['tools']);
        self::assertSame([WebSearchTool::NAME], $catalog->replacedServerTools($snapshot));
    }

    public function testSynaplanSearchReplacesTheServerToolDeclaration(): void
    {
        $catalog = $this->catalog(MessagesGatewayConfig::WEB_SEARCH_AUTO, searchConfigured: true);
        $snapshot = $catalog->build($this->user(), 'session-1', ['tools' => [self::WEB_SEARCH_SERVER_TOOL]]);

        self::assertSame([WebSearchTool::NAME], $catalog->replacedServerTools($snapshot));
    }

    public function testClientToolOfTheSameNameWins(): void
    {
        $snapshot = $this->catalog(MessagesGatewayConfig::WEB_SEARCH_SYNAPLAN, searchConfigured: true)->build(
            $this->user(),
            'session-1',
            ['tools' => [[
                'name' => 'web_search',
                'description' => 'the client runs its own search',
                'input_schema' => ['type' => 'object'],
            ]]],
        );

        self::assertSame(GatewayToolCatalog::WEB_SEARCH_NONE, $snapshot['web_search']);
        self::assertSame([], $snapshot['tools']);
    }

    public function testAutoOffersAnalyzeImageWhenVisionIsAvailable(): void
    {
        $snapshot = $this->catalog(
            MessagesGatewayConfig::WEB_SEARCH_AUTO,
            searchConfigured: false,
            visionMode: MessagesGatewayConfig::VISION_AUTO,
            visionConfigured: true,
        )->build($this->user(), 'session-1', []);

        self::assertCount(1, $snapshot['tools']);
        self::assertSame(AnalyzeImageTool::NAME, $snapshot['tools'][0]['name']);
        self::assertSame(GatewayToolCatalog::KIND_NATIVE, $snapshot['dispatch'][AnalyzeImageTool::NAME]['kind']);
    }

    public function testOffVisionModeDoesNotOfferAnalyzeImage(): void
    {
        $snapshot = $this->catalog(
            MessagesGatewayConfig::WEB_SEARCH_AUTO,
            searchConfigured: false,
            visionMode: MessagesGatewayConfig::VISION_OFF,
            visionConfigured: true,
        )->build($this->user(), 'session-1', []);

        self::assertSame([], $snapshot['tools']);
    }

    /**
     * The settings page reads the same rules without a request in hand, so the
     * operator is told what the gateway runs rather than what it was asked to.
     */
    public function testNativeToolNamesReportWhatTheInstallCanRun(): void
    {
        $both = $this->catalog(
            MessagesGatewayConfig::WEB_SEARCH_AUTO,
            searchConfigured: true,
            visionMode: MessagesGatewayConfig::VISION_AUTO,
            visionConfigured: true,
        );

        self::assertSame([WebSearchTool::NAME, AnalyzeImageTool::NAME], $both->nativeToolNames(5));
    }

    public function testNativeToolNamesDropWhatCannotRun(): void
    {
        // Passthrough hands the search to the upstream, and vision has no model.
        $neither = $this->catalog(
            MessagesGatewayConfig::WEB_SEARCH_PASSTHROUGH,
            searchConfigured: true,
            visionMode: MessagesGatewayConfig::VISION_AUTO,
            visionConfigured: false,
        );

        self::assertSame([], $neither->nativeToolNames(5));
    }

    public function testNativeToolNamesDropSearchWithoutAProvider(): void
    {
        $catalog = $this->catalog(
            MessagesGatewayConfig::WEB_SEARCH_AUTO,
            searchConfigured: false,
            visionMode: MessagesGatewayConfig::VISION_SYNAPLAN,
            visionConfigured: true,
        );

        self::assertSame([AnalyzeImageTool::NAME], $catalog->nativeToolNames(5));
    }

    private function catalog(
        string $mode,
        bool $searchConfigured,
        string $visionMode = MessagesGatewayConfig::VISION_OFF,
        bool $visionConfigured = false,
    ): GatewayToolCatalog {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('webSearchMode')->willReturn($mode);
        $config->method('visionMode')->willReturn($visionMode);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->method('isAvailable')->willReturn($searchConfigured);
        $webSearch->method('declaration')->willReturn([
            'name' => WebSearchTool::NAME,
            'description' => 'Search the live web',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        ]);

        $analyzeImage = $this->createMock(AnalyzeImageTool::class);
        $analyzeImage->method('isAvailable')->willReturn($visionConfigured);
        $analyzeImage->method('declaration')->willReturn([
            'name' => AnalyzeImageTool::NAME,
            'description' => 'Analyse an image',
            'input_schema' => ['type' => 'object'],
        ]);

        return new GatewayToolCatalog(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $webSearch,
            $analyzeImage,
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
