<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\GatewayOwnedCall;
use App\AI\Messages\Tools\GatewayOwnedDeps;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\NativeToolRegistry;
use App\AI\Messages\Tools\WebFetchTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\OpenAI\OpenAiGatewayToolCatalog;
use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use App\Service\Tool\Execution\ToolInvokerSlot;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RegistryDescriptorLookupTest extends TestCase
{
    public function testGatewaySkipsDiscoveryWhileTheRegistryIsOff(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::never())->method('get');
        $descriptor = $this->gatewayDescriptor($registry, false);

        self::assertSame(ToolSource::Builtin, $descriptor->source);
        self::assertSame('web_search', $descriptor->name);
    }

    public function testGatewayUsesTheRegistryDescriptorWhenItIsOn(): void
    {
        $registered = new ToolDescriptor('web_search', 'web_search', '', [], SideEffect::Write, ToolSource::Builtin, 7);
        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::once())->method('get')->with(7, 'web_search')->willReturn($registered);

        self::assertSame($registered, $this->gatewayDescriptor($registry, true));
    }

    public function testOpenAiSkipsDiscoveryWhileTheRegistryIsOff(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::never())->method('get');
        $descriptor = $this->openAiDescriptor($registry, false);

        self::assertSame(ToolSource::Builtin, $descriptor->source);
    }

    public function testOpenAiUsesTheRegistryDescriptorWhenItIsOn(): void
    {
        $registered = new ToolDescriptor('web_search', 'web_search', '', [], SideEffect::Read, ToolSource::Builtin, 7);
        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::once())->method('get')->with(7, 'web_search')->willReturn($registered);

        self::assertSame($registered, $this->openAiDescriptor($registry, true));
    }

    private function gatewayDescriptor(ToolRegistry $registry, bool $enabled): ToolDescriptor
    {
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isRegistryEnabled')->willReturn($enabled);
        $call = new GatewayOwnedCall(
            new NullLogger(),
            new GatewayOwnedDeps(
                $this->createStub(McpClient::class),
                $this->createStub(McpServerConfigRepository::class),
                new NativeToolRegistry($this->createStub(WebSearchTool::class), $this->createStub(WebFetchTool::class)),
                $this->createStub(AnalyzeImageTool::class),
                toolRegistry: $registry,
                toolsConfig: $config,
            ),
            new ToolInvokerSlot(),
            static fn (string $id, string $text, bool $error): array => [],
            static function (User $user, int $serverId, string $tool, bool $error): void {
            },
            static function (User $user, string $source, string $tool, string $input, bool $error): void {
            },
        );
        $method = new \ReflectionMethod(GatewayOwnedCall::class, 'descriptorFor');
        $descriptor = $method->invoke($call, 7, 'web_search', $this->entry());
        self::assertInstanceOf(ToolDescriptor::class, $descriptor);

        return $descriptor;
    }

    private function openAiDescriptor(ToolRegistry $registry, bool $enabled): ToolDescriptor
    {
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isRegistryEnabled')->willReturn($enabled);
        $messages = $this->createStub(MessagesGatewayConfig::class);
        $loop = new OpenAiGatewayToolLoop(
            new OpenAiGatewayToolCatalog(
                new McpToolCatalogAdapter($this->createStub(McpToolRegistry::class)),
                $this->createStub(McpClientConfig::class),
                $this->createStub(WebSearchTool::class),
                $messages,
            ),
            $this->createStub(AiFacade::class),
            $this->createStub(WebSearchTool::class),
            $this->createStub(McpClient::class),
            new McpToolCatalogAdapter($this->createStub(McpToolRegistry::class)),
            $this->createStub(McpServerConfigRepository::class),
            $messages,
            $this->createStub(RateLimitService::class),
            new NullLogger(),
            toolRegistry: $registry,
            toolsConfig: $config,
        );
        $method = new \ReflectionMethod(OpenAiGatewayToolLoop::class, 'descriptorFor');
        $descriptor = $method->invoke($loop, 7, 'web_search', $this->entry());
        self::assertInstanceOf(ToolDescriptor::class, $descriptor);

        return $descriptor;
    }

    /**
     * @return array{kind: string, serverId: int, tool: string, annotations: array<string, mixed>}
     */
    private function entry(): array
    {
        return [
            'kind' => GatewayToolCatalog::KIND_NATIVE,
            'serverId' => 0,
            'tool' => 'web_search',
            'annotations' => [],
        ];
    }
}
