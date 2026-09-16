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
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

final class GatewayToolCatalogCustomTest extends TestCase
{
    public function testOffersCustomHttpToolsIndependentlyOfMcp(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->method('forUser')->willReturn([
            new ToolDescriptor(
                'custom:walk_ticket_create',
                'Create a ticket',
                'Open a ticket in the helpdesk',
                ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
                SideEffect::Write,
                ToolSource::Custom,
                5,
                meta: ['toolId' => 9],
            ),
        ]);

        $toolsConfig = $this->createMock(ToolsConfig::class);
        $toolsConfig->method('isCustomHttpEnabled')->willReturn(true);
        $toolsConfig->method('isRegistryEnabled')->willReturn(true);

        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('webSearchMode')->willReturn(MessagesGatewayConfig::WEB_SEARCH_OFF);
        $config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_OFF);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->method('isAvailable')->willReturn(false);
        $analyzeImage = $this->createMock(AnalyzeImageTool::class);
        $analyzeImage->method('isAvailable')->willReturn(false);

        $catalog = new GatewayToolCatalog(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $webSearch,
            $analyzeImage,
            $config,
            $this->createMock(CacheItemPoolInterface::class),
            new NullLogger(),
            $registry,
            $toolsConfig,
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);
        $snapshot = $catalog->build($user, 'session-custom', []);

        self::assertCount(1, $snapshot['tools']);
        self::assertSame('custom:walk_ticket_create', $snapshot['tools'][0]['name']);
        self::assertSame(GatewayToolCatalog::KIND_CUSTOM, $snapshot['dispatch']['custom:walk_ticket_create']['kind']);
        self::assertSame(9, $snapshot['dispatch']['custom:walk_ticket_create']['annotations']['toolId']);
    }

    public function testOmitsCustomHttpToolsWhenRegistryIsDisabled(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::never())->method('forUser');

        $toolsConfig = $this->createMock(ToolsConfig::class);
        $toolsConfig->method('isCustomHttpEnabled')->willReturn(true);
        $toolsConfig->method('isRegistryEnabled')->willReturn(false);

        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('webSearchMode')->willReturn(MessagesGatewayConfig::WEB_SEARCH_OFF);
        $config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_OFF);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->method('isAvailable')->willReturn(false);
        $analyzeImage = $this->createMock(AnalyzeImageTool::class);
        $analyzeImage->method('isAvailable')->willReturn(false);

        $catalog = new GatewayToolCatalog(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $webSearch,
            $analyzeImage,
            $config,
            $this->createMock(CacheItemPoolInterface::class),
            new NullLogger(),
            $registry,
            $toolsConfig,
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);
        $snapshot = $catalog->build($user, 'session-custom', []);

        self::assertSame([], $snapshot['tools']);
    }
}
