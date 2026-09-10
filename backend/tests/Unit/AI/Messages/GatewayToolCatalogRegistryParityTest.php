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
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

/**
 * S1 C1: turning the registry on with no extra sources must not change the
 * catalog the coding client already sees.
 */
final class GatewayToolCatalogRegistryParityTest extends TestCase
{
    public function testRegistryOnWithEmptySourcesMatchesLegacyCatalog(): void
    {
        $legacy = $this->catalog(registry: false);
        $withRegistry = $this->catalog(registry: true);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);
        $body = ['tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]]];

        $this->assertSame(
            $legacy->build($user, 'session-parity', $body),
            $withRegistry->build($user, 'session-parity', $body),
        );
    }

    private function catalog(bool $registry): GatewayToolCatalog
    {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('webSearchMode')->willReturn(MessagesGatewayConfig::WEB_SEARCH_AUTO);
        $config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_OFF);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->method('isAvailable')->willReturn(true);
        $webSearch->method('declaration')->willReturn([
            'name' => WebSearchTool::NAME,
            'description' => 'Search the live web',
            'input_schema' => ['type' => 'object'],
        ]);

        $analyzeImage = $this->createMock(AnalyzeImageTool::class);
        $analyzeImage->method('isAvailable')->willReturn(false);
        $analyzeImage->method('declaration')->willReturn([
            'name' => AnalyzeImageTool::NAME,
            'description' => 'Analyse an image',
            'input_schema' => ['type' => 'object'],
        ]);

        $toolsConfig = null;
        $toolRegistry = null;
        if ($registry) {
            $toolsConfig = $this->createMock(ToolsConfig::class);
            $toolsConfig->method('isRegistryEnabled')->willReturn(true);
            $toolsConfig->method('isApprovalsEnabled')->willReturn(false);
            $toolRegistry = new ToolRegistry([]);
        }

        return new GatewayToolCatalog(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $webSearch,
            $analyzeImage,
            $config,
            $this->createMock(CacheItemPoolInterface::class),
            new NullLogger(),
            $toolRegistry,
            $toolsConfig,
        );
    }
}
