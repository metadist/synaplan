<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Tools\WebSearchTool;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GatewayToolLoopTest extends TestCase
{
    public function testCompleteLoopExecutesToolAndRePrompts(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $server = new McpServerConfig();
        $server->setUserId(5)->setName('local')->setUrl('https://example.test/mcp')->setEnabled(true);
        $ref = new \ReflectionProperty(McpServerConfig::class, 'id');
        $ref->setValue($server, 1);

        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->method('findByIdAndUser')->willReturnMap([[1, 5, $server]]);

        $client = $this->createMock(McpClient::class);
        $client->expects($this->once())->method('callTool')->with($server, 'rag_search', ['query' => 'test'])->willReturn([
            'content' => [['type' => 'text', 'text' => 'rag-hit']],
            'isError' => false,
        ]);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true, 'remaining' => 10, 'limit' => 100]);
        $rateLimits->expects($this->once())->method('recordUsage')->with(
            $user,
            'MESSAGES',
            $this->callback(static fn (array $m): bool => ($m['source'] ?? '') === 'MCP_TOOL'),
        );

        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('mcpMaxIterations')->willReturn(8);

        $loop = new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $this->createMock(WebSearchTool::class),
            $this->createMock(AnalyzeImageTool::class),
            $client,
            $servers,
            $config,
            $rateLimits,
            new NullLogger(),
        );

        $snapshot = [
            'tools' => [[
                'name' => 'mcp__1__rag_search',
                'description' => 'search',
                'input_schema' => ['type' => 'object'],
            ]],
            'dispatch' => [
                'mcp__1__rag_search' => [
                    'kind' => GatewayToolCatalog::KIND_MCP,
                    'serverId' => 1,
                    'tool' => 'rag_search',
                    'annotations' => ['readOnlyHint' => true],
                ],
            ],
            'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
        ];

        $calls = 0;
        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->method('complete')->willReturnCallback(
            function (array $body) use (&$calls): array {
                ++$calls;
                if (1 === $calls) {
                    $this->assertNotEmpty($body['tools']);
                    $this->assertSame('mcp__1__rag_search', $body['tools'][0]['name']);

                    return [
                        'status' => 200,
                        'headers' => [],
                        'body' => [
                            'id' => 'msg_1',
                            'type' => 'message',
                            'role' => 'assistant',
                            'content' => [[
                                'type' => 'tool_use',
                                'id' => 'toolu_1',
                                'name' => 'mcp__1__rag_search',
                                'input' => ['query' => 'test'],
                            ]],
                            'stop_reason' => 'tool_use',
                            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                        ],
                        'usage' => new MessagesUsage(10, 5, 0, 0, 'tool_use'),
                    ];
                }

                $this->assertCount(3, $body['messages']); // original + assistant + tool_result
                $last = $body['messages'][2];
                $this->assertSame('user', $last['role']);
                $this->assertSame('tool_result', $last['content'][0]['type']);
                $this->assertSame('rag-hit', $last['content'][0]['content']);

                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => [
                        'id' => 'msg_2',
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'done']],
                        'stop_reason' => 'end_turn',
                        'usage' => ['input_tokens' => 20, 'output_tokens' => 3],
                    ],
                    'usage' => new MessagesUsage(20, 3, 0, 0, 'end_turn'),
                ];
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 64,
                'messages' => [['role' => 'user', 'content' => 'hi']],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $snapshot,
        );

        $this->assertSame(2, $calls);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['iterations']);
        $this->assertSame(30, $result['usage']->inputTokens);
        $this->assertSame(8, $result['usage']->outputTokens);
        $this->assertSame('end_turn', $result['usage']->stopReason);
        $this->assertIsArray($result['body']);
        $this->assertSame('done', $result['body']['content'][0]['text']);
    }

    public function testWebSearchIsExecutedAndReplacesTheAnthropicServerToolDeclaration(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->expects($this->once())
            ->method('execute')
            ->with(['query' => 'euro dollar rate'])
            ->willReturn([
                'text' => "Web Search Results for: \"euro dollar rate\"\n\n[1] ECB reference rates",
                'isError' => false,
                'query' => 'euro dollar rate',
                'resultCount' => 1,
            ]);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $rateLimits->expects($this->once())->method('recordUsage')->with(
            $user,
            'MESSAGES',
            $this->callback(static fn (array $m): bool => 'WEB_SEARCH' === ($m['source'] ?? '')),
        );

        $loop = new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $webSearch,
            $this->createMock(AnalyzeImageTool::class),
            $this->createMock(McpClient::class),
            $this->createMock(McpServerConfigRepository::class),
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['mcpMaxIterations' => 8]),
            $rateLimits,
            new NullLogger(),
        );

        $snapshot = [
            'tools' => [[
                'name' => 'web_search',
                'description' => 'Search the live web',
                'input_schema' => ['type' => 'object'],
            ]],
            'dispatch' => [
                'web_search' => [
                    'kind' => GatewayToolCatalog::KIND_NATIVE,
                    'serverId' => 0,
                    'tool' => 'web_search',
                    'annotations' => ['readOnlyHint' => true],
                ],
            ],
            'web_search' => GatewayToolCatalog::WEB_SEARCH_SYNAPLAN,
        ];

        $calls = 0;
        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->method('complete')->willReturnCallback(
            function (array $body) use (&$calls): array {
                ++$calls;
                if (1 === $calls) {
                    // The `web_search_20250305` declaration is replaced by the
                    // executable tool, not forwarded alongside it.
                    $this->assertCount(1, $body['tools']);
                    $this->assertSame('web_search', $body['tools'][0]['name']);
                    $this->assertArrayHasKey('input_schema', $body['tools'][0]);

                    return [
                        'status' => 200,
                        'headers' => [],
                        'body' => [
                            'content' => [[
                                'type' => 'tool_use',
                                'id' => 'toolu_search',
                                'name' => 'web_search',
                                'input' => ['query' => 'euro dollar rate'],
                            ]],
                            'stop_reason' => 'tool_use',
                            'usage' => ['input_tokens' => 8, 'output_tokens' => 4],
                        ],
                        'usage' => new MessagesUsage(8, 4, 0, 0, 'tool_use'),
                    ];
                }

                $toolTurn = $body['messages'][2];
                $this->assertSame('tool_result', $toolTurn['content'][0]['type']);
                $this->assertStringContainsString('ECB reference rates', $toolTurn['content'][0]['content']);
                $this->assertArrayNotHasKey('is_error', $toolTurn['content'][0]);

                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => [
                        'content' => [['type' => 'text', 'text' => 'The current rate is …']],
                        'stop_reason' => 'end_turn',
                        'usage' => ['input_tokens' => 30, 'output_tokens' => 9],
                    ],
                    'usage' => new MessagesUsage(30, 9, 0, 0, 'end_turn'),
                ];
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'gpt-5',
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'What is the euro/dollar rate today?']],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $snapshot,
            ['web_search'],
        );

        $this->assertSame(2, $calls);
        $this->assertSame('end_turn', $result['usage']->stopReason);
        $this->assertIsArray($result['body']);
        $this->assertSame('The current rate is …', $result['body']['content'][0]['text']);
    }

    public function testClientOwnedToolsStopsWithoutExecuting(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $client = $this->createMock(McpClient::class);
        $client->expects($this->never())->method('callTool');

        $loop = new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $this->createMock(WebSearchTool::class),
            $this->createMock(AnalyzeImageTool::class),
            $client,
            $this->createMock(McpServerConfigRepository::class),
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['mcpMaxIterations' => 8]),
            $this->createConfiguredMock(RateLimitService::class, [
                'checkLimit' => ['allowed' => true],
            ]),
            new NullLogger(),
        );

        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->expects($this->once())->method('complete')->willReturn([
            'status' => 200,
            'headers' => [],
            'body' => [
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_bash',
                    'name' => 'Bash',
                    'input' => ['command' => 'ls'],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ],
            'usage' => new MessagesUsage(1, 1, 0, 0, 'tool_use'),
        ]);

        $result = $loop->runComplete(
            ['model' => 'x', 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'x']]],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            ['tools' => [], 'dispatch' => [], 'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE],
        );

        $this->assertSame(1, $result['iterations']);
        $this->assertSame('tool_use', $result['usage']->stopReason);
    }

    /**
     * Regression for a Copilot review comment on #1680: withUsage() must never
     * emit a negative ephemeral_5m_input_tokens in the aggregated multi-turn
     * usage body, even if a (defensively impossible, but not enforced by the
     * type system) cacheCreation1hTokens > cacheCreationTokens slips through.
     */
    public function testWithUsageClampsOneHourCacheBreakdownToCacheCreationTotal(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $loop = new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $this->createMock(WebSearchTool::class),
            $this->createMock(AnalyzeImageTool::class),
            $this->createMock(McpClient::class),
            $this->createMock(McpServerConfigRepository::class),
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['mcpMaxIterations' => 8]),
            $this->createConfiguredMock(RateLimitService::class, [
                'checkLimit' => ['allowed' => true],
            ]),
            new NullLogger(),
        );

        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->expects($this->once())->method('complete')->willReturn([
            'status' => 200,
            'headers' => [],
            'body' => [
                'content' => [['type' => 'text', 'text' => 'done']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ],
            // Inconsistent on purpose: cacheCreation1hTokens (999) exceeds
            // cacheCreationTokens (20). A real Anthropic response can't do
            // this, but withUsage() must still clamp rather than compute a
            // negative 5m count.
            'usage' => new MessagesUsage(
                inputTokens: 10,
                outputTokens: 5,
                cacheCreationTokens: 20,
                cacheReadTokens: 0,
                stopReason: 'end_turn',
                cacheCreation1hTokens: 999,
            ),
        ]);

        $result = $loop->runComplete(
            ['model' => 'x', 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'x']]],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            ['tools' => [], 'dispatch' => [], 'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE],
        );

        $this->assertIsArray($result['body']);
        $cacheCreation = $result['body']['usage']['cache_creation'];
        $this->assertSame(20, $cacheCreation['ephemeral_1h_input_tokens']);
        $this->assertSame(0, $cacheCreation['ephemeral_5m_input_tokens']);
        $this->assertGreaterThanOrEqual(0, $cacheCreation['ephemeral_5m_input_tokens']);
    }
}
