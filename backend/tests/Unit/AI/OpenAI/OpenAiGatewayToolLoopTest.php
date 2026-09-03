<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\OpenAI;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\OpenAI\OpenAiGatewayToolCatalog;
use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Provider\TestProvider;
use App\AI\Service\AiFacade;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OpenAiGatewayToolLoopTest extends TestCase
{
    public function testWebSearchIsExecutedAndRePrompted(): void
    {
        $search = $this->createMock(WebSearchTool::class);
        $search->method('isAvailable')->willReturn(true);
        $search->method('declaration')->willReturn((new WebSearchTool(
            $this->createMock(\App\Service\Search\BraveSearchService::class),
            new NullLogger(),
        ))->declaration());
        $search->method('execute')->willReturn([
            'text' => 'Berlin is 18C',
            'isError' => false,
            'query' => 'berlin weather',
            'resultCount' => 1,
        ]);

        $loop = $this->loop($search, mcpEnabled: false);
        $result = $loop->complete(
            $this->user(),
            [['role' => 'user', 'content' => 'TOOLTEST:web_search:{"query":"berlin weather"}']],
            ['model' => 'test-model', 'provider' => 'test'],
        );

        self::assertSame('Tool result received: Berlin is 18C', $result['content']);
        self::assertSame(['[web_search:berlin weather]'], $result['loop_notes']);
        self::assertSame('stop', $result['finish_reason'] ?? 'stop');
    }

    public function testMcpToolIsExecuted(): void
    {
        $server = new McpServerConfig();
        $server->setUserId(4)->setName('local')->setUrl('https://example.test/mcp')->setEnabled(true);
        $ref = new \ReflectionProperty(McpServerConfig::class, 'id');
        $ref->setValue($server, 1);

        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->method('findByIdAndUser')->willReturn($server);

        $client = $this->createMock(McpClient::class);
        $client->expects(self::once())->method('callTool')->with($server, 'rag_search', ['q' => 'x'])->willReturn([
            'content' => [['type' => 'text', 'text' => 'rag-hit']],
            'isError' => false,
        ]);

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
        $adapter->method('isOurs')->willReturnCallback(
            static fn (string $name): bool => str_starts_with($name, 'mcp__')
        );
        $adapter->method('isMutatingTool')->willReturn(false);

        $mcpConfig = $this->createMock(McpClientConfig::class);
        $mcpConfig->method('isClientEnabled')->willReturn(true);
        $search = $this->createMock(WebSearchTool::class);
        $search->method('isAvailable')->willReturn(false);
        $gw = $this->createMock(MessagesGatewayConfig::class);
        $gw->method('webSearchMode')->willReturn(MessagesGatewayConfig::WEB_SEARCH_OFF);
        $gw->method('mcpMaxIterations')->willReturn(8);

        $catalog = new OpenAiGatewayToolCatalog($adapter, $mcpConfig, $search, $gw);
        $loop = new OpenAiGatewayToolLoop(
            $catalog,
            $this->facade(),
            $search,
            $client,
            $adapter,
            $servers,
            $gw,
            $this->rateLimits(),
            new NullLogger(),
        );

        $result = $loop->complete(
            $this->user(),
            [['role' => 'user', 'content' => 'TOOLTEST:mcp__1__rag_search:{"q":"x"}']],
            ['model' => 'test-model', 'provider' => 'test'],
        );

        self::assertSame('Tool result received: rag-hit', $result['content']);
        self::assertSame(['[mcp:1/rag_search]'], $result['loop_notes']);
    }

    public function testClientOwnedToolIsRelayed(): void
    {
        $loop = $this->loop($this->unavailableSearch(), mcpEnabled: false);
        $result = $loop->complete(
            $this->user(),
            [['role' => 'user', 'content' => 'TOOLTEST:get_weather:{"city":"Berlin"}']],
            [
                'model' => 'test-model',
                'provider' => 'test',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'get_weather'],
                ]],
            ],
        );

        self::assertSame('tool_calls', $result['finish_reason']);
        self::assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
    }

    public function testToolChoiceNoneDoesNotInjectOrLoop(): void
    {
        $search = $this->createMock(WebSearchTool::class);
        $search->expects(self::never())->method('execute');
        $search->method('isAvailable')->willReturn(true);

        $loop = $this->loop($search, mcpEnabled: false);
        $result = $loop->complete(
            $this->user(),
            [['role' => 'user', 'content' => 'TOOLTEST:web_search:{"query":"x"}']],
            ['model' => 'test-model', 'provider' => 'test', 'tool_choice' => 'none'],
        );

        // TestProvider only returns tool_calls when tools are present; none
        // skips inject so this is a normal text answer.
        self::assertArrayNotHasKey('tool_calls', $result);
        self::assertNotSame('tool_calls', $result['finish_reason'] ?? 'stop');
    }

    public function testMixedTurnExecutesOwnedThenReturnsClient(): void
    {
        $search = $this->createMock(WebSearchTool::class);
        $search->method('isAvailable')->willReturn(true);
        $search->method('declaration')->willReturn((new WebSearchTool(
            $this->createMock(\App\Service\Search\BraveSearchService::class),
            new NullLogger(),
        ))->declaration());
        $search->expects(self::once())->method('execute')->willReturn([
            'text' => 'ok',
            'isError' => false,
            'query' => 'x',
            'resultCount' => 1,
        ]);

        $facade = $this->createMock(AiFacade::class);
        $facade->method('chat')->willReturn([
            'content' => '',
            'provider' => 'test',
            'model' => 'test-model',
            'finish_reason' => 'tool_calls',
            'tool_calls' => [
                [
                    'id' => 'call_search',
                    'type' => 'function',
                    'function' => ['name' => 'web_search', 'arguments' => '{"query":"x"}'],
                ],
                [
                    'id' => 'call_weather',
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}'],
                ],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);

        $loop = $this->loop($search, mcpEnabled: false, facade: $facade);
        $result = $loop->complete(
            $this->user(),
            [['role' => 'user', 'content' => 'mixed']],
            [
                'model' => 'test-model',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'get_weather'],
                ]],
            ],
        );

        self::assertSame('tool_calls', $result['finish_reason']);
        self::assertCount(1, $result['tool_calls']);
        self::assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        self::assertSame(['[web_search:x]'], $result['loop_notes']);
    }

    public function testStreamSuppressesIntermediateServerRound(): void
    {
        $search = $this->createMock(WebSearchTool::class);
        $search->method('isAvailable')->willReturn(true);
        $search->method('declaration')->willReturn((new WebSearchTool(
            $this->createMock(\App\Service\Search\BraveSearchService::class),
            new NullLogger(),
        ))->declaration());
        $search->method('execute')->willReturn([
            'text' => 'secret-intermediate',
            'isError' => false,
            'query' => 'q',
            'resultCount' => 1,
        ]);

        $loop = $this->loop($search, mcpEnabled: false);
        $emitted = [];
        $meta = $loop->stream(
            $this->user(),
            [['role' => 'user', 'content' => 'TOOLTEST:web_search:{"query":"q"}']],
            static function ($chunk) use (&$emitted): void {
                $emitted[] = $chunk;
            },
            ['model' => 'test-model', 'provider' => 'test'],
        );

        $visible = '';
        foreach ($emitted as $chunk) {
            if (is_string($chunk)) {
                $visible .= $chunk;
            }
            if (is_array($chunk) && 'tool_call_delta' === ($chunk['type'] ?? '')) {
                self::fail('intermediate server tool_call_delta must not be streamed');
            }
        }
        self::assertSame('Tool result received: secret-intermediate', $visible);
        self::assertSame(['[web_search:q]'], $meta['loop_notes']);
    }

    private function loop(
        WebSearchTool $search,
        bool $mcpEnabled,
        ?AiFacade $facade = null,
    ): OpenAiGatewayToolLoop {
        $mcpConfig = $this->createMock(McpClientConfig::class);
        $mcpConfig->method('isClientEnabled')->willReturn($mcpEnabled);
        $gw = $this->createMock(MessagesGatewayConfig::class);
        $gw->method('webSearchMode')->willReturn(
            $search->isAvailable() ? MessagesGatewayConfig::WEB_SEARCH_AUTO : MessagesGatewayConfig::WEB_SEARCH_OFF
        );
        $gw->method('mcpMaxIterations')->willReturn(8);
        $catalog = new OpenAiGatewayToolCatalog(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $mcpConfig,
            $search,
            $gw,
        );

        return new OpenAiGatewayToolLoop(
            $catalog,
            $facade ?? $this->facade(),
            $search,
            $this->createMock(McpClient::class),
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $this->createMock(McpServerConfigRepository::class),
            $gw,
            $this->rateLimits(),
            new NullLogger(),
        );
    }

    private function facade(): AiFacade
    {
        $provider = new TestProvider();
        $facade = $this->createMock(AiFacade::class);
        $facade->method('chat')->willReturnCallback(
            static function (array $messages, ?int $userId, array $options) use ($provider): array {
                $result = $provider->chat($messages, $options);
                $out = [
                    'content' => $result['content'],
                    'provider' => 'test',
                    'model' => $options['model'] ?? 'test-model',
                    'usage' => $result['usage'],
                ];
                if (isset($result['tool_calls'])) {
                    $out['tool_calls'] = $result['tool_calls'];
                }
                if (isset($result['finish_reason'])) {
                    $out['finish_reason'] = $result['finish_reason'];
                }

                return $out;
            }
        );

        return $facade;
    }

    private function unavailableSearch(): WebSearchTool
    {
        $search = $this->createMock(WebSearchTool::class);
        $search->method('isAvailable')->willReturn(false);

        return $search;
    }

    private function rateLimits(): RateLimitService
    {
        $rate = $this->createMock(RateLimitService::class);
        $rate->method('checkLimit')->willReturn(['allowed' => true]);

        return $rate;
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        return $user;
    }
}
