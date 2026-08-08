<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Mcp\McpToolLoop;
use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

final class McpToolLoopTest extends TestCase
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

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->method('set')->willReturnSelf();
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);

        $loop = new McpToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $client,
            $servers,
            $config,
            $rateLimits,
            $cache,
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
                    'serverId' => 1,
                    'tool' => 'rag_search',
                    'annotations' => ['readOnlyHint' => true],
                ],
            ],
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

    public function testClientOwnedToolsStopsWithoutExecuting(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $client = $this->createMock(McpClient::class);
        $client->expects($this->never())->method('callTool');

        $loop = new McpToolLoop(
            new McpToolCatalogAdapter($this->createMock(\App\Service\Mcp\McpToolRegistry::class)),
            $client,
            $this->createMock(McpServerConfigRepository::class),
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['mcpMaxIterations' => 8]),
            $this->createConfiguredMock(RateLimitService::class, [
                'checkLimit' => ['allowed' => true],
            ]),
            $this->createMock(CacheItemPoolInterface::class),
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
            ['tools' => [], 'dispatch' => []],
        );

        $this->assertSame(1, $result['iterations']);
        $this->assertSame('tool_use', $result['usage']->stopReason);
    }
}
