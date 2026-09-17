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
            'TOOLS',
            self::callback(static fn (mixed $meta): bool => is_array($meta)),
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
            self::anything(),
            'TOOLS',
            self::callback(static fn (mixed $meta): bool => is_array($meta)),
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
                        'content' => [['type' => 'text', 'text' => 'The current rate is 1.08 — https://example.test/ecb']],
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
        $this->assertSame('The current rate is 1.08 — https://example.test/ecb', $result['body']['content'][0]['text']);
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

    public function testCustomHttpToolIsExecutedWithoutTalkingAboutMcp(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $tool = new \App\Entity\CustomTool(5, 'walk_ticket_create', 'Create a ticket');
        (new \ReflectionProperty(\App\Entity\CustomTool::class, 'id'))->setValue($tool, 9);

        $repo = $this->createMock(\App\Repository\CustomToolRepository::class);
        $repo->expects($this->once())->method('find')->with(9)->willReturn($tool);

        $executor = $this->createMock(\App\Service\Tool\Custom\HttpToolExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->with($tool, ['title' => 'Printer on floor 3'], 5)
            ->willReturn(['status' => 200, 'summary' => 'Ticket #12 created', 'fields' => [], 'truncated' => false]);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true, 'remaining' => 10, 'limit' => 100]);

        $loop = new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $this->createMock(WebSearchTool::class),
            $this->createMock(AnalyzeImageTool::class),
            $this->createMock(McpClient::class),
            $this->createMock(McpServerConfigRepository::class),
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['mcpMaxIterations' => 8]),
            $rateLimits,
            new NullLogger(),
            httpExecutor: $executor,
            customTools: $repo,
        );

        $snapshot = [
            'tools' => [[
                'name' => 'custom:walk_ticket_create',
                'description' => 'Open a ticket',
                'input_schema' => ['type' => 'object'],
            ]],
            'dispatch' => [
                'custom:walk_ticket_create' => [
                    'kind' => GatewayToolCatalog::KIND_CUSTOM,
                    'serverId' => 0,
                    'tool' => 'custom:walk_ticket_create',
                    'annotations' => ['readOnlyHint' => false, 'toolId' => 9],
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
                    return [
                        'status' => 200,
                        'headers' => [],
                        'body' => [
                            'id' => 'msg_1',
                            'type' => 'message',
                            'role' => 'assistant',
                            'content' => [[
                                'type' => 'tool_use',
                                'id' => 'toolu_c1',
                                'name' => 'custom:walk_ticket_create',
                                'input' => ['title' => 'Printer on floor 3'],
                            ]],
                            'stop_reason' => 'tool_use',
                            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                        ],
                        'usage' => new MessagesUsage(10, 5, 0, 0, 'tool_use'),
                    ];
                }

                $last = $body['messages'][2]['content'][0];
                $this->assertSame('Ticket #12 created', $last['content']);
                $this->assertArrayNotHasKey('is_error', $last);

                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => [
                        'id' => 'msg_2',
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'Opened the ticket.']],
                        'stop_reason' => 'end_turn',
                        'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
                    ],
                    'usage' => new MessagesUsage(12, 4, 0, 0, 'end_turn'),
                ];
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 64,
                'messages' => [['role' => 'user', 'content' => 'open a ticket']],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $snapshot,
        );

        $this->assertSame(2, $calls);
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['body']);
        $this->assertSame('Opened the ticket.', $result['body']['content'][0]['text']);
    }

    public function testCustomHttpNon2xxIsMarkedAsToolError(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $tool = new \App\Entity\CustomTool(5, 'walk_ticket_create', 'Create a ticket');
        (new \ReflectionProperty(\App\Entity\CustomTool::class, 'id'))->setValue($tool, 9);

        $repo = $this->createMock(\App\Repository\CustomToolRepository::class);
        $repo->expects($this->once())->method('find')->with(9)->willReturn($tool);

        $executor = $this->createMock(\App\Service\Tool\Custom\HttpToolExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->willReturn(['status' => 502, 'summary' => 'Helpdesk unavailable', 'fields' => [], 'truncated' => false]);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true, 'remaining' => 10, 'limit' => 100]);

        $loop = new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $this->createMock(WebSearchTool::class),
            $this->createMock(AnalyzeImageTool::class),
            $this->createMock(McpClient::class),
            $this->createMock(McpServerConfigRepository::class),
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['mcpMaxIterations' => 8]),
            $rateLimits,
            new NullLogger(),
            httpExecutor: $executor,
            customTools: $repo,
        );

        $snapshot = [
            'tools' => [[
                'name' => 'custom:walk_ticket_create',
                'description' => 'Open a ticket',
                'input_schema' => ['type' => 'object'],
            ]],
            'dispatch' => [
                'custom:walk_ticket_create' => [
                    'kind' => GatewayToolCatalog::KIND_CUSTOM,
                    'serverId' => 0,
                    'tool' => 'custom:walk_ticket_create',
                    'annotations' => ['readOnlyHint' => false, 'toolId' => 9],
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
                    return [
                        'status' => 200,
                        'headers' => [],
                        'body' => [
                            'id' => 'msg_1',
                            'type' => 'message',
                            'role' => 'assistant',
                            'content' => [[
                                'type' => 'tool_use',
                                'id' => 'toolu_c1',
                                'name' => 'custom:walk_ticket_create',
                                'input' => ['title' => 'Printer on floor 3'],
                            ]],
                            'stop_reason' => 'tool_use',
                            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                        ],
                        'usage' => new MessagesUsage(10, 5, 0, 0, 'tool_use'),
                    ];
                }

                $last = $body['messages'][2]['content'][0];
                $this->assertSame('Helpdesk unavailable', $last['content']);
                $this->assertTrue($last['is_error']);

                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => [
                        'id' => 'msg_2',
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'The helpdesk is down.']],
                        'stop_reason' => 'end_turn',
                        'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
                    ],
                    'usage' => new MessagesUsage(12, 4, 0, 0, 'end_turn'),
                ];
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 64,
                'messages' => [['role' => 'user', 'content' => 'open a ticket']],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $snapshot,
        );

        $this->assertSame(2, $calls);
        $this->assertSame(200, $result['status']);
    }

    public function testWebSearchWrapsAfterTwoRoundsAndFillsEmptyAnswer(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->expects($this->exactly(2))
            ->method('execute')
            ->willReturn([
                'text' => 'Node.js LTS is 24.x — https://nodejs.org',
                'isError' => false,
                'query' => 'node lts',
                'resultCount' => 1,
            ]);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $rateLimits->method('recordUsage');

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
                if ($calls <= 2) {
                    return [
                        'status' => 200,
                        'headers' => [],
                        'body' => [
                            'content' => [[
                                'type' => 'tool_use',
                                'id' => 'toolu_search_'.$calls,
                                'name' => 'web_search',
                                'input' => ['query' => 'node lts'],
                            ]],
                            'stop_reason' => 'tool_use',
                            'usage' => ['input_tokens' => 8, 'output_tokens' => 4],
                        ],
                        'usage' => new MessagesUsage(8, 4, 0, 0, 'tool_use'),
                    ];
                }

                $this->assertArrayNotHasKey('tools', $body);
                $this->assertArrayNotHasKey('tool_choice', $body);
                $last = $body['messages'][array_key_last($body['messages'])];
                $this->assertSame('user', $last['role']);
                $this->assertIsString($last['content']);
                $this->assertStringContainsString('Do not search again', $last['content']);
                $this->assertStringContainsString('http URL', $last['content']);

                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => [
                        'content' => [],
                        'stop_reason' => 'end_turn',
                        'usage' => ['input_tokens' => 30, 'output_tokens' => 1],
                    ],
                    'usage' => new MessagesUsage(30, 1, 0, 0, 'end_turn'),
                ];
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'groq:openai/gpt-oss-120b:chat',
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'What is the current Node LTS?']],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $snapshot,
            ['web_search'],
        );

        $this->assertSame(3, $calls);
        $this->assertIsArray($result['body']);
        $this->assertSame(
            'I looked this up but could not turn the results into an answer. Please try again, or ask in a different way.',
            $result['body']['content'][0]['text'],
        );
    }

    public function testEmptyThinkingBlocksAreDroppedFromToolFollowUp(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->expects($this->once())
            ->method('execute')
            ->willReturn([
                'text' => 'hit',
                'isError' => false,
                'query' => 'q',
                'resultCount' => 1,
            ]);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $rateLimits->method('recordUsage');

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
                    return [
                        'status' => 200,
                        'headers' => [],
                        'body' => [
                            'content' => [
                                ['type' => 'thinking', 'thinking' => ''],
                                [
                                    'type' => 'tool_use',
                                    'id' => 'toolu_search',
                                    'name' => 'web_search',
                                    'input' => ['query' => 'q'],
                                ],
                            ],
                            'stop_reason' => 'tool_use',
                            'usage' => ['input_tokens' => 8, 'output_tokens' => 4],
                        ],
                        'usage' => new MessagesUsage(8, 4, 0, 0, 'tool_use'),
                    ];
                }

                $assistant = $body['messages'][1];
                $this->assertSame('assistant', $assistant['role']);
                foreach ($assistant['content'] as $block) {
                    $this->assertNotSame('thinking', $block['type'] ?? '');
                }

                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => [
                        'content' => [['type' => 'text', 'text' => 'The current rate is 3.1% — https://example.test']],
                        'stop_reason' => 'end_turn',
                        'usage' => ['input_tokens' => 30, 'output_tokens' => 9],
                    ],
                    'usage' => new MessagesUsage(30, 9, 0, 0, 'end_turn'),
                ];
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'search']],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $snapshot,
            ['web_search'],
        );

        $this->assertSame(2, $calls);
        $this->assertSame(200, $result['status']);
    }

    public function testWebSearchWrapsWhenAnswerHasNoHttpUrl(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->expects($this->once())
            ->method('execute')
            ->willReturn([
                'text' => 'Node.js LTS is 24.x — https://nodejs.org',
                'isError' => false,
                'query' => 'node lts',
                'resultCount' => 1,
            ]);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $rateLimits->method('recordUsage');

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
                    return [
                        'status' => 200,
                        'headers' => [],
                        'body' => [
                            'content' => [[
                                'type' => 'tool_use',
                                'id' => 'toolu_search',
                                'name' => 'web_search',
                                'input' => ['query' => 'node lts'],
                            ]],
                            'stop_reason' => 'tool_use',
                            'usage' => ['input_tokens' => 8, 'output_tokens' => 4],
                        ],
                        'usage' => new MessagesUsage(8, 4, 0, 0, 'tool_use'),
                    ];
                }
                if (2 === $calls) {
                    return [
                        'status' => 200,
                        'headers' => [],
                        'body' => [
                            'content' => [['type' => 'text', 'text' => 'The current LTS is Node 24【3†L1】.']],
                            'stop_reason' => 'end_turn',
                            'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
                        ],
                        'usage' => new MessagesUsage(20, 8, 0, 0, 'end_turn'),
                    ];
                }

                $this->assertArrayNotHasKey('tools', $body);
                $last = $body['messages'][array_key_last($body['messages'])];
                $this->assertSame('user', $last['role']);
                $this->assertIsString($last['content']);
                $this->assertStringContainsString('http URL', $last['content']);

                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => [
                        'content' => [['type' => 'text', 'text' => 'Node 24 LTS — https://nodejs.org/en']],
                        'stop_reason' => 'end_turn',
                        'usage' => ['input_tokens' => 30, 'output_tokens' => 9],
                    ],
                    'usage' => new MessagesUsage(30, 9, 0, 0, 'end_turn'),
                ];
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'groq:openai/gpt-oss-120b:chat',
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'What is the current Node LTS?']],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $snapshot,
            ['web_search'],
        );

        $this->assertSame(3, $calls);
        $this->assertIsArray($result['body']);
        $this->assertStringContainsString('https://nodejs.org', $result['body']['content'][0]['text']);
    }

    public function testWebSearchWrapsWhenAnswerOnlyMentionsHttpUrlWords(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->expects($this->once())
            ->method('execute')
            ->willReturn([
                'text' => 'Node.js LTS is 24.x — https://nodejs.org',
                'isError' => false,
                'query' => 'node lts',
                'resultCount' => 1,
            ]);

        $loop = $this->webSearchLoop($webSearch);
        $calls = 0;
        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->method('complete')->willReturnCallback(
            function (array $body) use (&$calls): array {
                ++$calls;
                if (1 === $calls) {
                    return $this->toolUseBody('toolu_search', 'web_search', ['query' => 'node lts']);
                }
                if (2 === $calls) {
                    return $this->textBody('I could not provide an HTTP URL.');
                }

                $this->assertArrayNotHasKey('tools', $body);

                return $this->textBody('Node 24 LTS — https://nodejs.org/en');
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'groq:openai/gpt-oss-120b:chat',
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'What is the current Node LTS?']],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $this->webSearchSnapshot(),
            ['web_search'],
        );

        $this->assertSame(3, $calls);
        $this->assertIsArray($result['body']);
        $this->assertStringContainsString('https://nodejs.org', $result['body']['content'][0]['text']);
    }

    public function testWrapUpWithoutARealUrlRecoversSearchSources(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->expects($this->once())
            ->method('execute')
            ->willReturn([
                'text' => 'Node.js LTS is 24.x — https://nodejs.org',
                'isError' => false,
                'query' => 'node lts',
                'resultCount' => 1,
            ]);

        $loop = $this->webSearchLoop($webSearch);
        $calls = 0;
        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->method('complete')->willReturnCallback(
            function (array $body) use (&$calls): array {
                ++$calls;
                if (1 === $calls) {
                    return $this->toolUseBody('toolu_search', 'web_search', ['query' => 'node lts']);
                }
                if (2 === $calls) {
                    return $this->textBody('The current LTS is Node 24【3†URL】.');
                }

                $this->assertArrayNotHasKey('tools', $body);

                return $this->textBody('I could not provide an HTTP URL.');
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'groq:openai/gpt-oss-120b:chat',
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'What is the current Node LTS?']],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $this->webSearchSnapshot(),
            ['web_search'],
        );

        $this->assertSame(3, $calls);
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['body']);
        $this->assertStringContainsString('https://nodejs.org', $result['body']['content'][0]['text']);
    }

    public function testWrapUpGroqToolChoiceConflictRecoversUrls(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->expects($this->exactly(2))
            ->method('execute')
            ->willReturn([
                'text' => 'Node.js LTS is 24.x — https://nodejs.org https://github.com/nodejs/node',
                'isError' => false,
                'query' => 'node lts',
                'resultCount' => 2,
            ]);

        $loop = $this->webSearchLoop($webSearch);
        $calls = 0;
        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->method('complete')->willReturnCallback(
            function (array $body) use (&$calls): array {
                ++$calls;
                if ($calls <= 2) {
                    return $this->toolUseBody('toolu_search_'.$calls, 'web_search', ['query' => 'node lts']);
                }

                $this->assertArrayNotHasKey('tools', $body);

                return [
                    'status' => 400,
                    'headers' => [],
                    'body' => [
                        'type' => 'error',
                        'error' => [
                            'type' => 'invalid_request_error',
                            'message' => 'Tool choice is none, but model called a tool.',
                        ],
                    ],
                    'usage' => new MessagesUsage(12, 0, 0, 0, null),
                ];
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'groq:openai/gpt-oss-120b:chat',
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'What is the current Node LTS?']],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $this->webSearchSnapshot(),
            ['web_search'],
        );

        $this->assertSame(3, $calls);
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['body']);
        $this->assertSame('end_turn', $result['body']['stop_reason']);
        $this->assertStringContainsString('https://nodejs.org', $result['body']['content'][0]['text']);
    }

    public function testWrapUpToolUseIsRecoveredInsteadOfLooped(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $webSearch = $this->createMock(WebSearchTool::class);
        $webSearch->expects($this->exactly(2))
            ->method('execute')
            ->willReturn([
                'text' => 'See https://nodejs.org/en',
                'isError' => false,
                'query' => 'node lts',
                'resultCount' => 1,
            ]);

        $loop = $this->webSearchLoop($webSearch);
        $calls = 0;
        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->method('complete')->willReturnCallback(
            function () use (&$calls): array {
                ++$calls;
                if ($calls <= 2) {
                    return $this->toolUseBody('toolu_search_'.$calls, 'web_search', ['query' => 'node lts']);
                }

                return $this->toolUseBody('toolu_search_wrap', 'web_search', ['query' => 'again']);
            }
        );

        $result = $loop->runComplete(
            [
                'model' => 'groq:openai/gpt-oss-120b:chat',
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => 'What is the current Node LTS?']],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            ],
            ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            $translator,
            $user,
            $this->webSearchSnapshot(),
            ['web_search'],
        );

        $this->assertSame(3, $calls);
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['body']);
        $this->assertStringContainsString('https://nodejs.org', $result['body']['content'][0]['text']);
    }

    /**
     * @return array<string, mixed>
     */
    private function webSearchSnapshot(): array
    {
        return [
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
    }

    private function webSearchLoop(WebSearchTool $webSearch): GatewayToolLoop
    {
        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $rateLimits->method('recordUsage');

        return new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $webSearch,
            $this->createMock(AnalyzeImageTool::class),
            $this->createMock(McpClient::class),
            $this->createMock(McpServerConfigRepository::class),
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['mcpMaxIterations' => 8]),
            $rateLimits,
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{status: int, headers: list<string>, body: array<string, mixed>, usage: MessagesUsage}
     */
    private function toolUseBody(string $id, string $name, array $input): array
    {
        return [
            'status' => 200,
            'headers' => [],
            'body' => [
                'content' => [[
                    'type' => 'tool_use',
                    'id' => $id,
                    'name' => $name,
                    'input' => $input,
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 8, 'output_tokens' => 4],
            ],
            'usage' => new MessagesUsage(8, 4, 0, 0, 'tool_use'),
        ];
    }

    /**
     * @return array{status: int, headers: list<string>, body: array<string, mixed>, usage: MessagesUsage}
     */
    private function textBody(string $text): array
    {
        return [
            'status' => 200,
            'headers' => [],
            'body' => [
                'content' => [['type' => 'text', 'text' => $text]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
            ],
            'usage' => new MessagesUsage(20, 8, 0, 0, 'end_turn'),
        ];
    }
}
