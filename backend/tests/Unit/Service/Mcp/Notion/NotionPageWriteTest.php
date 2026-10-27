<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\McpServerConfig;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\PromptMeta;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Mcp\Notion\NotionFetchArguments;
use App\Service\Mcp\Notion\NotionPageWrite;
use App\Service\ModelConfigService;
use App\Service\Multitask\Execution\Runner\McpActionRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlanValidator;
use App\Service\Multitask\Skill\SkillCatalog;
use App\Service\Multitask\TaskPlanner;
use App\Service\Prompt\PromptService;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\RateLimitService;
use App\Service\Security\EncryptionService;
use App\Service\Security\SsrfGuard;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NotionPageWriteTest extends TestCase
{
    private const PAGE_ID = '39797ad6-0eb3-806d-b59e-ef7653838664';

    public function testTitlesFromTheChatQuestion(): void
    {
        self::assertSame(
            ['Metadist GmbH', 'Synaplan'],
            NotionPageWrite::titles('Ändere den Titel in mein Notion von der Seite "Metadist GmbH" zu "Synaplan"'),
        );
        self::assertSame(
            ['Metadist GmbH', 'Synaplan'],
            NotionPageWrite::titles('Ändere den Titel von Metadist GmbH in Synaplan'),
        );
        self::assertSame(
            ['Metadist GmbH', 'Synaplan'],
            NotionPageWrite::titles('Kannst du dort mal den Titel von Metadist GmbH auf Synaplan ändern?'),
        );
        self::assertNull(NotionPageWrite::titles('Moin, was steht auf der Metadist GmbH Seite in Notion?'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function renameInEveryLocale(): iterable
    {
        yield 'en' => ['Change the title of Roadmap to Plan 2027'];
        yield 'en rename' => ['Rename the page Roadmap to Plan 2027 please'];
        yield 'de rename' => ['Benenne die Seite Roadmap in Plan 2027 um'];
        yield 'es' => ['Cambia el título de la página Roadmap a Plan 2027'];
        yield 'es rename' => ['¿Puedes renombrar Roadmap a Plan 2027?'];
        yield 'fr' => ['Change le titre de la page Roadmap en Plan 2027'];
        yield 'fr rename' => ['Renomme la page Roadmap en Plan 2027 s\'il te plaît'];
        yield 'tr' => ['Roadmap sayfasının başlığını Plan 2027 olarak değiştir'];
        yield 'tr quoted' => ['Notion\'da "Roadmap" sayfasını "Plan 2027" olarak yeniden adlandır'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('renameInEveryLocale')]
    public function testTitlesInEveryLocale(string $question): void
    {
        self::assertSame(['Roadmap', 'Plan 2027'], NotionPageWrite::titles($question));
    }

    public function testQuestionsAboutTitlesAreNotRenames(): void
    {
        self::assertNull(NotionPageWrite::titles('Quel est le titre de la page Roadmap ?'));
        self::assertNull(NotionPageWrite::titles('¿Cuál es el título de Roadmap?'));
        self::assertNull(NotionPageWrite::titles('Roadmap sayfasının başlığı ne?'));
        self::assertNull(NotionPageWrite::titles('Which page title was modified last?'));
    }

    public function testRunnerRenamesThePageFromThePastedSearchResult(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $rpc = is_array($body) ? ($body['method'] ?? '') : '';
            if ('tools/call' === $rpc) {
                $sent[] = $body['params'];
            }
            $result = match ($rpc) {
                'tools/list' => ['tools' => [
                    ['name' => 'notion-search', 'description' => '', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => true]],
                    ['name' => 'notion-update-page', 'description' => '', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true]],
                ]],
                'tools/call' => ['content' => [['type' => 'text', 'text' => '{"page_id":"'.self::PAGE_ID.'"}']], 'isError' => false],
                default => [],
            };

            return new MockResponse((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]), ['http_code' => 200]);
        });
        $runner = $this->actionRunner($http);
        $pasted = (string) json_encode(['results' => [
            ['id' => self::PAGE_ID, 'title' => 'Metadist GmbH'],
            ['id' => '3fb97ad6-0eb3-81d3-96a3-f62d469c8531', 'title' => 'Knowledge Base'],
        ], 'type' => 'workspace_search']);
        $node = new \App\Service\Multitask\Plan\TaskNode('n2', Capability::McpAction, [], [
            'arguments' => ['page_id' => $pasted, 'command' => 'update_properties', 'properties' => ['title' => 'Synaplan']],
        ], ['server_id' => 3, 'tool' => 'notion-update-page']);
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn('Ändere den Titel von in mein Notion von der Seite "Metadist GmbH" zu "Synaplan"');
        $message->method('getUserId')->willReturn(1);
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $message->method('getFileText')->willReturn('');

        $result = $runner->run($node, new \App\Service\Multitask\Execution\NodeContext($message, [], 1, ['topic' => 'general']));

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame('notion-update-page', $sent[0]['name']);
        self::assertSame(self::PAGE_ID, $sent[0]['arguments']['page_id']);
        self::assertSame(['title' => 'Synaplan'], $sent[0]['arguments']['properties']);
    }

    public function testInstallPutsSearchBeforeTheTitleChange(): void
    {
        $payload = [
            'version' => 1,
            'language' => 'de',
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat', 'depends_on' => [], 'inputs' => ['text' => 'I cannot change it'], 'params' => []],
            ],
        ];

        $plan = NotionPageWrite::install(
            $payload,
            'Ändere den Titel in mein Notion von der Seite "Metadist GmbH" zu "Synaplan"',
            3,
            'notion-search',
            'notion-update-page',
        );

        self::assertSame('mcp_fetch', $plan['tasks'][0]['capability']);
        self::assertSame('Metadist GmbH', $plan['tasks'][0]['inputs']['arguments']['query']);
        self::assertSame(['server_id' => 3, 'tool' => 'notion-search'], $plan['tasks'][0]['params']);
        self::assertSame('mcp_action', $plan['tasks'][1]['capability']);
        self::assertSame('$n_notion_search.text', $plan['tasks'][1]['inputs']['arguments']['page_id']);
        self::assertSame('update_properties', $plan['tasks'][1]['inputs']['arguments']['command']);
        self::assertSame(['title' => 'Synaplan'], $plan['tasks'][1]['inputs']['arguments']['properties']);
        self::assertSame(['n_notion_search'], $plan['tasks'][1]['depends_on']);
        self::assertContains('n_notion_update', $plan['tasks'][2]['depends_on']);
        self::assertStringContainsString('$n_notion_update.text', $plan['tasks'][2]['inputs']['text']);
        self::assertSame('n1', $plan['reply_node']);
    }

    public function testCompleteReplacesASearchPayloadWithThePageId(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects(self::never())->method('callTool');
        $write = new NotionPageWrite($client, $this->createMock(McpToolRegistry::class), new NotionFetchArguments(new NullLogger()), new NullLogger());
        $server = $this->notionServer();

        $arguments = $write->complete($server, ['name' => 'notion-update-page'], [
            'page_id' => json_encode([
                'results' => [
                    ['id' => self::PAGE_ID, 'title' => 'Metadist GmbH', 'url' => 'https://www.notion.so/Metadist'],
                    ['id' => '11111111-1111-1111-1111-111111111111', 'title' => 'Knowledge Base', 'url' => 'https://www.notion.so/kb'],
                ],
            ], JSON_THROW_ON_ERROR),
            'command' => 'update_properties',
            'properties' => ['title' => 'Synaplan'],
        ], 'Ändere den Titel von "Metadist GmbH" zu "Synaplan"');

        self::assertSame(self::PAGE_ID, $arguments['page_id']);
        self::assertSame('Synaplan', $arguments['properties']['title']);
    }

    public function testCompleteSearchesWhenThePlanOnlyHasTheTitle(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects(self::once())->method('callTool')->with(
            self::anything(),
            'notion-search',
            ['query' => 'Metadist GmbH'],
        )->willReturn([
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['results' => [['id' => self::PAGE_ID, 'title' => 'Metadist GmbH']]], JSON_THROW_ON_ERROR),
            ]],
            'isError' => false,
        ]);
        $registry = $this->createMock(McpToolRegistry::class);
        $registry->method('toolsFor')->willReturn([
            ['name' => 'notion-search', 'description' => '', 'inputSchema' => [], 'annotations' => []],
        ]);
        $write = new NotionPageWrite($client, $registry, new NotionFetchArguments(new NullLogger()), new NullLogger());

        $arguments = $write->complete($this->notionServer(), ['name' => 'notion-update-page'], [
            'page_id' => 'Metadist GmbH',
        ], 'Ändere den Titel von Metadist GmbH in Synaplan');

        self::assertSame(self::PAGE_ID, $arguments['page_id']);
        self::assertSame('update_properties', $arguments['command']);
        self::assertSame('Synaplan', $arguments['properties']['title']);
    }

    public function testPlannerInsertsTheSearchWhenTheModelOnlyChats(): void
    {
        $planner = $this->planner();
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn('Ändere den Titel in mein Notion von der Seite "Metadist GmbH" zu "Synaplan"');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getTopic')->willReturn('general');
        $message->method('getUserId')->willReturn(1);
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new ArrayCollection());

        $result = $planner->plan($message, [], 1);

        self::assertFalse($result->fallback);
        $search = $result->plan->nodeById('n_notion_search');
        $update = $result->plan->nodeById('n_notion_update');
        self::assertNotNull($search);
        self::assertSame(Capability::McpFetch, $search->capability);
        self::assertSame('Metadist GmbH', $search->inputs['arguments']['query']);
        self::assertNotNull($update);
        self::assertSame(Capability::McpAction, $update->capability);
        self::assertSame('notion-update-page', $update->params['tool']);
        self::assertSame('$n_notion_search.text', $update->inputs['arguments']['page_id']);
        self::assertSame('Synaplan', $update->inputs['arguments']['properties']['title']);
    }

    private function notionServer(): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(1)->setName('Notion')->setUrl('https://mcp.notion.com/mcp')->setAllowWrite(true);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 3);

        return $server;
    }

    private function planner(): TaskPlanner
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $rpc = is_array($body) ? ($body['method'] ?? '') : '';
            $result = 'tools/list' === $rpc
                ? ['tools' => [
                    ['name' => 'notion-search', 'description' => 'Search', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => true]],
                    ['name' => 'notion-update-page', 'description' => 'Update', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => false]],
                ]]
                : [];

            return new MockResponse((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]), ['http_code' => 200]);
        });
        $runner = $this->actionRunner($http);

        $prompts = $this->createMock(PromptRepository::class);
        $planPrompt = $this->createMock(Prompt::class);
        $planPrompt->method('getPrompt')->willReturn('PLAN [CAPABILITYLIST]');
        $prompts->method('findByTopic')->willReturn($planPrompt);
        $prompts->method('getAllTopics')->willReturn(['general']);
        $prompts->method('getTopicsWithDescriptions')->willReturn([['topic' => 'general', 'description' => 'catch-all']]);
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(76);
        $models->method('getProviderForModel')->willReturn('test');
        $models->method('getModelName')->willReturn('test');
        $ai = $this->createMock(AiFacade::class);
        $ai->method('chat')->willReturn(['content' => (string) json_encode([
            'version' => 1,
            'language' => 'de',
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat', 'depends_on' => [], 'inputs' => ['text' => 'I need a page URL'], 'params' => []],
            ],
        ])]);
        $structured = $this->createMock(StructuredOutputConfig::class);
        $structured->method('isEnabled')->willReturn(false);

        return new TaskPlanner(
            $ai,
            $prompts,
            $models,
            new TaskPlanValidator(),
            new NullLogger(),
            $this->createStub(UserRepository::class),
            new TimeContextBuilder(),
            new SkillCatalog([$runner]),
            $this->createStub(PromptService::class),
            $this->createStub(RateLimitService::class),
            $structured,
        );
    }

    private function actionRunner(MockHttpClient $http): McpActionRunner
    {
        $server = $this->notionServer();
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => match ($group.'.'.$setting) {
                'MCP.CLIENT_ENABLED', 'MULTITASK.MCP_FETCH_ENABLED', 'MULTITASK.MCP_ACTION_ENABLED' => '1',
                default => null,
            },
        );
        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->method('findEnabledByUser')->willReturn([$server]);
        $client = new McpClient($http, new SsrfGuard(), new EncryptionService('test-secret', new NullLogger()), new McpClientConfig($config), new NullLogger());
        $servers->method('findByIdAndUser')->willReturn($server);

        return new McpActionRunner(
            $client,
            new McpToolRegistry($client, $servers, new ArrayAdapter(), new NullLogger()),
            $servers,
            new McpClientConfig($config),
            new MultitaskRoutingConfig($config),
            $this->promptService(),
            new NullLogger(),
            new \App\Service\Mcp\AtlassianToolArguments($client, new NullLogger()),
        );
    }

    private function promptService(): PromptService
    {
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getId')->willReturn(99);
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('findByTopicAndUser')->willReturn($prompt);
        $meta = $this->createMock(PromptMeta::class);
        $meta->method('getMetaKey')->willReturn('tool_mcp');
        $meta->method('getMetaValue')->willReturn('1');
        $metas = $this->createMock(PromptMetaRepository::class);
        $metas->method('findBy')->willReturn([$meta]);

        return new PromptService($prompts, $metas, $this->createStub(EntityManagerInterface::class), new NullLogger());
    }
}
