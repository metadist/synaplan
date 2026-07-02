<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\McpServerConfig;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\PromptMeta;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Service\EncryptionService;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\McpFetchRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\PromptService;
use App\Service\Security\SsrfGuard;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `mcp_fetch` node contract (plan 09 §3.2, locked Option A): the full gate
 * chain (flags → topic entitlement → ownership → tool existence → pull-only),
 * grounded result formatting, and the dynamic planner sub-catalog.
 */
final class McpFetchRunnerTest extends TestCase
{
    private const USER_ID = 7;

    /** @var list<array<string, mixed>> */
    private array $serverTools = [
        ['name' => 'search_customers', 'description' => 'Find CRM customers', 'inputSchema' => ['properties' => ['query' => ['type' => 'string']]], 'annotations' => []],
        ['name' => 'delete_customer', 'description' => 'Remove a customer', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => false]],
    ];

    private function server(bool $enabled = true): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(self::USER_ID)->setName('Company CRM')->setUrl('https://8.8.8.8/mcp')->setEnabled($enabled);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 3);

        return $server;
    }

    /**
     * @param array<string, string> $flags         BCONFIG values by "GROUP.SETTING"
     * @param array<string, string> $topicMetaRows BPROMPTMETA key => value for the resolved topic
     */
    private function runner(
        array $flags = ['MCP.CLIENT_ENABLED' => '1', 'MULTITASK.MCP_FETCH_ENABLED' => '1'],
        array $topicMetaRows = ['tool_mcp' => '1'],
        ?McpServerConfig $server = null,
        string $callToolResultText = 'Customer: Acme GmbH, last order #42.',
    ): McpFetchRunner {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => $flags["{$group}.{$setting}"] ?? null,
        );

        $httpFactory = function (string $method, string $url, array $options) use ($callToolResultText): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $rpcMethod = is_array($body) ? ($body['method'] ?? '') : '';

            $result = match ($rpcMethod) {
                'tools/list' => ['tools' => $this->serverTools],
                'tools/call' => ['content' => [['type' => 'text', 'text' => $callToolResultText]], 'isError' => false],
                default => [],
            };

            return new MockResponse(
                (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        };

        $clientConfig = new McpClientConfig($configRepo);
        $client = new McpClient(
            new MockHttpClient($httpFactory),
            new SsrfGuard(),
            new EncryptionService('test-secret', new NullLogger()),
            $clientConfig,
            new NullLogger(),
        );

        $servers = $this->createMock(McpServerConfigRepository::class);
        $resolved = $server ?? $this->server();
        $servers->method('findByIdAndUser')->willReturnCallback(
            static fn (int $id, int $userId): ?McpServerConfig => 3 === $id && self::USER_ID === $userId ? $resolved : null,
        );
        $servers->method('findEnabledByUser')->willReturn([$resolved]);

        return new McpFetchRunner(
            $client,
            new McpToolRegistry($client, $servers, new ArrayAdapter(), new NullLogger()),
            $servers,
            $clientConfig,
            new MultitaskRoutingConfig($configRepo),
            $this->promptService($topicMetaRows),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, string> $metaRows
     */
    private function promptService(array $metaRows): PromptService
    {
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getId')->willReturn(99);
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('findByTopicAndUser')->willReturn($prompt);

        $metaEntities = [];
        foreach ($metaRows as $key => $value) {
            $meta = $this->createMock(PromptMeta::class);
            $meta->method('getMetaKey')->willReturn($key);
            $meta->method('getMetaValue')->willReturn($value);
            $metaEntities[] = $meta;
        }
        $metaRepo = $this->createMock(PromptMetaRepository::class);
        $metaRepo->method('findBy')->willReturn($metaEntities);

        return new PromptService($prompts, $metaRepo, $this->createMock(EntityManagerInterface::class), new NullLogger());
    }

    private function context(): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn('Look up Acme GmbH in our CRM');
        $message->method('getUserId')->willReturn(self::USER_ID);
        $message->method('getFiles')->willReturn(new ArrayCollection([]));
        $message->method('getFileText')->willReturn('');

        return new NodeContext($message, [], self::USER_ID, ['topic' => 'general']);
    }

    private function node(string $tool = 'search_customers', int $serverId = 3): TaskNode
    {
        return new TaskNode('n1', Capability::McpFetch, [], ['arguments' => ['query' => 'Acme GmbH']], [
            'server_id' => $serverId,
            'tool' => $tool,
        ]);
    }

    public function testPullsDataAndReturnsGroundedText(): void
    {
        $result = $this->runner()->run($this->node(), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('Acme GmbH, last order #42', (string) $result->text);
        self::assertSame('Company CRM · search_customers', $result->metadata['query']);
        self::assertSame(3, $result->metadata['mcp']['server_id']);
    }

    public function testDisabledFlagsFailTheNode(): void
    {
        $result = $this->runner(flags: [])->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('disabled', (string) $result->error);
    }

    public function testTopicWithoutToolMcpIsRefused(): void
    {
        $result = $this->runner(topicMetaRows: [])->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('not allowed', (string) $result->error);
    }

    public function testTopicServerAllowlistIsEnforced(): void
    {
        $result = $this->runner(topicMetaRows: ['tool_mcp' => '1', 'mcp_servers' => '55,56'])
            ->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('not allowed', (string) $result->error);
    }

    public function testHallucinatedToolIsRefused(): void
    {
        $result = $this->runner()->run($this->node(tool: 'made_up_tool'), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('does not exist', (string) $result->error);
    }

    public function testSelfDeclaredMutatingToolIsRefusedPullOnly(): void
    {
        $result = $this->runner()->run($this->node(tool: 'delete_customer'), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('read-only', (string) $result->error);
    }

    public function testForeignOrUnknownServerIsInvisible(): void
    {
        $result = $this->runner()->run($this->node(serverId: 999), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('not available', (string) $result->error);
    }

    public function testDynamicSubCatalogRendersOnlyForEntitledTopicsAndReadSafeTools(): void
    {
        $runner = $this->runner();
        $descriptor = $runner->describe()[0];
        self::assertNotNull($descriptor->dynamicNote);
        self::assertTrue($descriptor->requiresDynamicNote);

        // Entitled topic → sub-catalog with the read-safe tool only.
        $note = ($descriptor->dynamicNote)(self::USER_ID, ['topic' => 'general', 'topic_metadata' => ['tool_mcp' => true]]);
        self::assertIsString($note);
        self::assertStringContainsString('server_id 3 "Company CRM"', $note);
        self::assertStringContainsString('search_customers(query)', $note);
        self::assertStringNotContainsString('delete_customer', $note, 'mutating tools must not be offered to the planner');

        // Topic without tool_mcp → the block stays invisible.
        self::assertNull(($descriptor->dynamicNote)(self::USER_ID, ['topic' => 'general', 'topic_metadata' => []]));

        // Allowlist excluding this server → invisible.
        self::assertNull(($descriptor->dynamicNote)(self::USER_ID, ['topic' => 'general', 'topic_metadata' => ['tool_mcp' => true, 'mcp_servers' => '55']]));
    }
}
