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
use App\Service\Multitask\Execution\Runner\McpActionRunner;
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
 * `mcp_action` node contract: the write gate chain on top of the read gates
 * (server `allow_write` opt-in, destructive tools refused), plus the dynamic
 * planner sub-catalog that only advertises declared write tools of
 * write-enabled servers.
 */
final class McpActionRunnerTest extends TestCase
{
    private const USER_ID = 7;

    /** @var list<array<string, mixed>> */
    private array $serverTools = [
        ['name' => 'search_pages', 'description' => 'Find wiki pages', 'inputSchema' => ['properties' => ['query' => ['type' => 'string']]], 'annotations' => []],
        ['name' => 'create_page', 'description' => 'Create a Confluence page', 'inputSchema' => ['properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => false]],
        ['name' => 'delete_page', 'description' => 'Delete a page', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true]],
    ];

    private function server(bool $enabled = true, bool $allowWrite = true): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(self::USER_ID)->setName('Confluence')->setUrl('https://8.8.8.8/mcp')
            ->setEnabled($enabled)->setAllowWrite($allowWrite);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 5);

        return $server;
    }

    /**
     * @param array<string, string> $flags         BCONFIG values by "GROUP.SETTING"
     * @param array<string, string> $topicMetaRows BPROMPTMETA key => value for the resolved topic
     */
    private function runner(
        array $flags = ['MCP.CLIENT_ENABLED' => '1', 'MULTITASK.MCP_ACTION_ENABLED' => '1'],
        array $topicMetaRows = ['tool_mcp' => '1'],
        ?McpServerConfig $server = null,
        string $callToolResultText = 'Created page "Launch plan" at https://wiki.example.com/x/abc',
    ): McpActionRunner {
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
            static fn (int $id, int $userId): ?McpServerConfig => 5 === $id && self::USER_ID === $userId ? $resolved : null,
        );
        $servers->method('findEnabledByUser')->willReturn([$resolved]);

        return new McpActionRunner(
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
        $message->method('getText')->willReturn('Create a Confluence page about the launch plan');
        $message->method('getUserId')->willReturn(self::USER_ID);
        $message->method('getFiles')->willReturn(new ArrayCollection([]));
        $message->method('getFileText')->willReturn('');

        return new NodeContext($message, [], self::USER_ID, ['topic' => 'general']);
    }

    private function node(string $tool = 'create_page', int $serverId = 5): TaskNode
    {
        return new TaskNode('n1', Capability::McpAction, [], ['arguments' => ['title' => 'Launch plan', 'content' => 'Summary…']], [
            'server_id' => $serverId,
            'tool' => $tool,
        ]);
    }

    public function testWriteActionSucceedsOnWriteEnabledServer(): void
    {
        $result = $this->runner()->run($this->node(), $this->context());

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertStringContainsString('Created page "Launch plan"', (string) $result->text);
        self::assertSame('Confluence · create_page', $result->metadata['query']);
        self::assertTrue($result->metadata['mcp']['write']);
    }

    public function testServerWithoutWriteOptInIsRefused(): void
    {
        $result = $this->runner(server: $this->server(allowWrite: false))
            ->run($this->node(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('does not allow write actions', (string) $result->error);
    }

    public function testDestructiveToolIsRefused(): void
    {
        $result = $this->runner()->run($this->node(tool: 'delete_page'), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('destructive', (string) $result->error);
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

    public function testHallucinatedToolIsRefused(): void
    {
        $result = $this->runner()->run($this->node(tool: 'made_up_tool'), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('does not exist', (string) $result->error);
    }

    public function testForeignOrUnknownServerIsInvisible(): void
    {
        $result = $this->runner()->run($this->node(serverId: 999), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('not available', (string) $result->error);
    }

    public function testDynamicSubCatalogAdvertisesOnlyDeclaredWriteToolsOfWriteEnabledServers(): void
    {
        $runner = $this->runner();
        $descriptor = $runner->describe()[0];
        self::assertSame(Capability::McpAction, $descriptor->capability);
        self::assertNotNull($descriptor->dynamicNote);
        self::assertTrue($descriptor->requiresDynamicNote);

        $note = ($descriptor->dynamicNote)(self::USER_ID, ['topic' => 'general', 'topic_metadata' => ['tool_mcp' => true]]);
        self::assertIsString($note);
        self::assertStringContainsString('server_id 5 "Confluence"', $note);
        self::assertStringContainsString('create_page(title, content)', $note);
        self::assertStringNotContainsString('search_pages', $note, 'read tools belong to mcp_fetch, not mcp_action');
        self::assertStringNotContainsString('delete_page', $note, 'destructive tools must never be offered');

        // Topic without tool_mcp → invisible.
        self::assertNull(($descriptor->dynamicNote)(self::USER_ID, ['topic' => 'general', 'topic_metadata' => []]));
    }

    public function testDynamicSubCatalogStaysInvisibleWithoutWriteEnabledServers(): void
    {
        $runner = $this->runner(server: $this->server(allowWrite: false));
        $descriptor = $runner->describe()[0];

        self::assertNotNull($descriptor->dynamicNote);
        self::assertNull(($descriptor->dynamicNote)(self::USER_ID, ['topic' => 'general', 'topic_metadata' => ['tool_mcp' => true]]));
    }
}
