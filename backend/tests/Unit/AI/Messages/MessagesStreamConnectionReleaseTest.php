<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\MessagesContextInjector;
use App\AI\Messages\MessagesGateway;
use App\AI\Messages\MessagesModelResolver;
use App\AI\Messages\MessagesUsage;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Tools\NativeToolRegistry;
use App\AI\Messages\Tools\WebFetchPolicy;
use App\AI\Messages\Tools\WebFetchTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\Messages\Translator\AnthropicPassthroughTranslator;
use App\AI\Messages\Vision\VisionPolicy;
use App\AI\Pipeline\AiCallPipeline;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Infrastructure\DatabaseConnectionRelease;
use App\Service\Mcp\McpClient;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Service\Vision\VisionModelResolver;
use App\Tests\Support\StubConnectionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A Messages stream keeps the PHP worker waiting on the provider. The worker
 * gives the database connection back before every provider stream, including
 * the direct path and the stream after a tool round writes a usage row.
 */
final class MessagesStreamConnectionReleaseTest extends TestCase
{
    private bool $closed = false;

    protected function setUp(): void
    {
        $this->closed = false;
    }

    public function testTwoToolRoundsReleaseBeforeEachProviderStream(): void
    {
        $user = $this->user();
        $toolWrites = 0;
        $rateLimits = $this->createStub(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true, 'remaining' => 10, 'limit' => 100]);
        $rateLimits->method('recordUsage')->willReturnCallback(function (User $user, string $action) use (&$toolWrites): RecordedUsage {
            if ('TOOLS' === $action) {
                ++$toolWrites;
                // The usage row opens a connection the next provider stream must close.
                $this->closed = false;
            }

            return new RecordedUsage('0', '0', 0, 0, 0);
        });

        $rounds = 0;
        $translator = $this->createMock(AnthropicPassthroughTranslator::class);
        $translator->method('supports')->willReturn(true);
        $translator->expects(self::exactly(2))->method('stream')->willReturnCallback(
            function (array $body, array $context, callable $emit) use (&$rounds): MessagesUsage {
                ++$rounds;
                self::assertTrue($this->closed, 'the connection is still open when provider stream '.$rounds.' starts');
                if (1 === $rounds) {
                    $this->emitToolUse($emit);

                    return new MessagesUsage(8, 4, 0, 0, 'tool_use');
                }
                $this->emitText($emit, 'done');

                return new MessagesUsage(12, 2, 0, 0, 'end_turn');
            },
        );

        $this->gateway($translator, $this->toolLoop($rateLimits), $rateLimits, $this->pipeline($this->connection(2)))
            ->executeStream($this->prepared(toolLoop: true), $user, static function (): void {});

        self::assertSame(1, $toolWrites);
        self::assertSame(2, $rounds);
    }

    public function testAStreamWithNoServerToolsReleasesBeforeTheProviderAndNotOnLaterChunks(): void
    {
        $translator = $this->createMock(AnthropicPassthroughTranslator::class);
        $translator->method('supports')->willReturn(true);
        $translator->expects(self::once())->method('stream')->willReturnCallback(
            function (array $body, array $context, callable $emit): MessagesUsage {
                self::assertTrue($this->closed, 'the connection is still open when the provider stream starts');

                // Provider setup reads its endpoint and credentials.
                $this->closed = false;
                $emit(['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'delta' => ['text' => 'Hel']]]);
                self::assertTrue($this->closed, 'the connection reopened by provider setup is still open after the first chunk');

                $this->closed = false;
                $emit(['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'delta' => ['text' => 'lo']]]);
                self::assertFalse($this->closed, 'a later chunk released the connection again');

                return new MessagesUsage(1, 2, 0, 0, 'end_turn');
            },
        );

        $rateLimits = $this->createStub(RateLimitService::class);
        $rateLimits->method('recordUsage')->willReturn(new RecordedUsage('0', '0', 0, 0, 0));

        $this->gateway($translator, $this->toolLoop($rateLimits), $rateLimits, $this->pipeline($this->connection(2)))
            ->executeStream($this->prepared(toolLoop: false), $this->user(), static function (): void {});
    }

    public function testABufferedAnswerKeepsItsConnection(): void
    {
        $translator = $this->createMock(AnthropicPassthroughTranslator::class);
        $translator->method('supports')->willReturn(true);
        $translator->expects(self::never())->method('stream');
        $translator->expects(self::once())->method('complete')->willReturn([
            'status' => 200,
            'headers' => [],
            'body' => [
                'content' => [['type' => 'text', 'text' => 'Hello']],
                'stop_reason' => 'end_turn',
            ],
            'usage' => new MessagesUsage(1, 1, 0, 0, 'end_turn'),
        ]);

        $rateLimits = $this->createStub(RateLimitService::class);
        $rateLimits->method('recordUsage')->willReturn(new RecordedUsage('0', '0', 0, 0, 0));

        $this->gateway($translator, $this->toolLoop($rateLimits), $rateLimits, $this->pipeline($this->connection(0)))
            ->executeComplete($this->prepared(toolLoop: false), $this->user());
    }

    public function testAConnectionInsideATransactionStaysOpen(): void
    {
        $translator = $this->createMock(AnthropicPassthroughTranslator::class);
        $translator->method('supports')->willReturn(true);
        $translator->expects(self::once())->method('stream')->willReturnCallback(
            function (array $body, array $context, callable $emit): MessagesUsage {
                self::assertFalse($this->closed, 'a stream closed a connection that is inside a transaction');
                $emit(['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'delta' => ['text' => 'Hi']]]);
                self::assertFalse($this->closed);

                return new MessagesUsage(1, 1, 0, 0, 'end_turn');
            },
        );

        $rateLimits = $this->createStub(RateLimitService::class);
        $rateLimits->method('recordUsage')->willReturn(new RecordedUsage('0', '0', 0, 0, 0));

        $this->gateway($translator, $this->toolLoop($rateLimits), $rateLimits, $this->pipeline($this->connection(0, inTransaction: true)))
            ->executeStream($this->prepared(toolLoop: false), $this->user(), static function (): void {});
    }

    private function gateway(
        AnthropicPassthroughTranslator $translator,
        GatewayToolLoop $toolLoop,
        RateLimitService $rateLimits,
        AiCallPipeline $pipeline,
    ): MessagesGateway {
        $config = $this->createStub(MessagesGatewayConfig::class);
        $config->method('isBudgetNoticeEnabled')->willReturn(false);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $gateway = new MessagesGateway(
            $config,
            $this->createStub(MessagesModelResolver::class),
            $this->createStub(ModelRepository::class),
            $this->createStub(VisionModelResolver::class),
            $this->createStub(UserProviderKeyResolver::class),
            $rateLimits,
            $this->createStub(PremiumFeatureGate::class),
            $translator,
            $this->createStub(GatewayToolCatalog::class),
            $toolLoop,
            new WebFetchPolicy(),
            $this->createStub(VisionPolicy::class),
            $this->createStub(MessagesContextInjector::class),
            $this->createStub(CacheItemPoolInterface::class),
            $bus,
            new NullLogger(),
        );
        $gateway->setPipeline($pipeline);

        return $gateway;
    }

    private function toolLoop(RateLimitService $rateLimits): GatewayToolLoop
    {
        $server = new McpServerConfig();
        $server->setUserId(5)->setName('local')->setUrl('https://example.test/mcp')->setEnabled(true);
        (new \ReflectionProperty(McpServerConfig::class, 'id'))->setValue($server, 1);

        $servers = $this->createStub(McpServerConfigRepository::class);
        $servers->method('findByIdAndUser')->willReturn($server);
        $client = $this->createStub(McpClient::class);
        $client->method('callTool')->willReturn([
            'content' => [['type' => 'text', 'text' => 'rag-hit']],
            'isError' => false,
        ]);
        $config = $this->createStub(MessagesGatewayConfig::class);
        $config->method('mcpMaxIterations')->willReturn(8);
        $config->method('webSearchMaxRounds')->willReturn(2);
        $config->method('webFetchMaxPerTurn')->willReturn(3);

        return new GatewayToolLoop(
            new McpToolCatalogAdapter($this->createStub(\App\Service\Mcp\McpToolRegistry::class)),
            new NativeToolRegistry($this->createStub(WebSearchTool::class), $this->createStub(WebFetchTool::class)),
            $this->createStub(AnalyzeImageTool::class),
            $client,
            $servers,
            $config,
            $rateLimits,
            new NullLogger(),
        );
    }

    private function pipeline(Connection $connection): AiCallPipeline
    {
        return new AiCallPipeline(
            connections: new DatabaseConnectionRelease(new StubConnectionRegistry(['default' => $connection]), new NullLogger()),
        );
    }

    private function connection(int $closes, bool $inTransaction = false): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturnCallback(fn (): bool => !$this->closed);
        $connection->method('isTransactionActive')->willReturn($inTransaction);
        $connection->expects(self::exactly($closes))->method('close')->willReturnCallback(function (): void {
            $this->closed = true;
        });

        return $connection;
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(5);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepared(bool $toolLoop): array
    {
        return [
            'ok' => true,
            'stream' => true,
            'status' => 200,
            'headers' => [],
            'body' => null,
            'raw_stream' => false,
            'usage' => new MessagesUsage(),
            'key_source' => 'operator',
            'resolved' => [
                'provider' => 'anthropic',
                'providerModelId' => 'claude-test',
                'displayModel' => 'claude-test',
                'model_id' => 1,
                'requested' => 'claude-test',
                'aliased_from' => null,
            ],
            'budget' => ['budget' => 0, 'percent' => 0],
            'session_id' => null,
            'session_key' => 'sess',
            'session_client' => 'test',
            'body_mutated' => false,
            'request_body' => [
                'model' => 'claude-test',
                'max_tokens' => 64,
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ],
            'translator_context' => ['api_key' => 'k', 'upstream_url' => 'http://example.test'],
            'tool_loop' => $toolLoop,
            'tool_catalog' => $toolLoop ? [
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
            ] : null,
            'replaced_server_tools' => [],
            'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
            'web_fetch' => 'off',
            'vision' => [
                'handling' => 'forward',
                'mode' => 'auto',
                'detail' => 'auto',
                'images_forwarded' => 0,
                'images_omitted' => 0,
            ],
            'context_hash' => null,
            'debug' => false,
        ];
    }

    /**
     * @param callable(string|array{event: string, data: array<string, mixed>}): void $emit
     */
    private function emitToolUse(callable $emit): void
    {
        $emit([
            'event' => 'message_start',
            'data' => [
                'type' => 'message_start',
                'message' => [
                    'id' => 'msg_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'usage' => ['input_tokens' => 8, 'output_tokens' => 0],
                ],
            ],
        ]);
        $emit([
            'event' => 'content_block_start',
            'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => [
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'mcp__1__rag_search',
                    'input' => [],
                ],
            ],
        ]);
        $emit([
            'event' => 'content_block_delta',
            'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":"test"}'],
            ],
        ]);
        $emit([
            'event' => 'content_block_stop',
            'data' => ['type' => 'content_block_stop', 'index' => 0],
        ]);
        $emit([
            'event' => 'message_delta',
            'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'tool_use'],
                'usage' => ['output_tokens' => 4],
            ],
        ]);
        $emit([
            'event' => 'message_stop',
            'data' => ['type' => 'message_stop'],
        ]);
    }

    /**
     * @param callable(string|array{event: string, data: array<string, mixed>}): void $emit
     */
    private function emitText(callable $emit, string $text): void
    {
        $emit([
            'event' => 'message_start',
            'data' => [
                'type' => 'message_start',
                'message' => [
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'usage' => ['input_tokens' => 12, 'output_tokens' => 0],
                ],
            ],
        ]);
        $emit([
            'event' => 'content_block_start',
            'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'text', 'text' => ''],
            ],
        ]);
        $emit([
            'event' => 'content_block_delta',
            'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $text],
            ],
        ]);
        $emit([
            'event' => 'content_block_stop',
            'data' => ['type' => 'content_block_stop', 'index' => 0],
        ]);
        $emit([
            'event' => 'message_delta',
            'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 2],
            ],
        ]);
        $emit([
            'event' => 'message_stop',
            'data' => ['type' => 'message_stop'],
        ]);
    }
}
