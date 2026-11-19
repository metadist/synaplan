<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\GatewayOwnedDeps;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Tools\NativeToolRegistry;
use App\AI\Messages\Tools\WebFetchTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpToolRegistry;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use App\Service\Tool\Execution\ToolLimits;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A turn with more Synaplan tool calls than the per-turn cap still replays
 * one tool_result per tool_use, or the next upstream call is rejected.
 */
final class GatewayToolCapTest extends TestCase
{
    public function testEveryToolUsePastTheCapGetsASkippedResult(): void
    {
        $uses = array_map(static fn (int $n): array => [
            'type' => 'tool_use',
            'id' => 'toolu_'.$n,
            'name' => 'mcp__1__gone',
            'input' => [],
        ], range(1, ToolLimits::MAX_TOOLS_PER_TURN + 2));

        $replayed = [];
        $translator = $this->createMock(MessagesTranslatorInterface::class);
        $translator->method('complete')->willReturnCallback(static function (array $body) use ($uses, &$replayed): array {
            $first = 1 === count($body['messages']);
            if (!$first) {
                $replayed = $body['messages'];
            }

            return [
                'status' => 200,
                'headers' => [],
                'body' => [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => $first ? $uses : [['type' => 'text', 'text' => 'done']],
                    'stop_reason' => $first ? 'tool_use' : 'end_turn',
                ],
                'usage' => new MessagesUsage(1, 1, 0, 0, $first ? 'tool_use' : 'end_turn'),
            ];
        });

        $result = $this->loop()->runComplete(
            ['model' => 'm', 'max_tokens' => 64, 'messages' => [['role' => 'user', 'content' => 'hi']]],
            [],
            $translator,
            $this->user(),
            [
                'tools' => [['name' => 'mcp__1__other', 'description' => 'x', 'input_schema' => ['type' => 'object']]],
                'dispatch' => [],
                'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
            ],
        );

        self::assertSame(200, $result['status']);
        self::assertCount(3, $replayed);
        $results = array_column($replayed[2]['content'], 'content', 'tool_use_id');
        self::assertSame(array_column($uses, 'id'), array_keys($results));
        self::assertSame(ToolLimits::SKIPPED_RESULT, $results['toolu_17']);
        self::assertSame(ToolLimits::SKIPPED_RESULT, $results['toolu_18']);
        self::assertNotSame(ToolLimits::SKIPPED_RESULT, $results['toolu_16']);
    }

    private function loop(): GatewayToolLoop
    {
        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true, 'remaining' => 10, 'limit' => 100]);
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('mcpMaxIterations')->willReturn(8);
        $config->method('webSearchMaxRounds')->willReturn(2);

        return new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            new GatewayOwnedDeps(
                $this->createMock(McpClient::class),
                $this->createMock(McpServerConfigRepository::class),
                new NativeToolRegistry($this->createMock(WebSearchTool::class), $this->createMock(WebFetchTool::class)),
                $this->createMock(AnalyzeImageTool::class),
            ),
            $config,
            $rateLimits,
            new NullLogger(),
        );
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        return $user;
    }
}
