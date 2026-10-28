<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\OpenAI\OpenAiGatewayToolCatalog;
use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Pipeline\AiAction;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\Hook\CircuitBreakerHook;
use App\AI\Pipeline\Hook\ModelHealthHook;
use App\AI\Pipeline\Hook\RateLimitHook;
use App\AI\Pipeline\Hook\RequestContextHook;
use App\AI\Pipeline\Hook\UsageHook;
use App\AI\Pipeline\UsageHookConfig;
use App\AI\Pipeline\UsageHookMode;
use App\AI\Provider\OpenAIProvider;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use App\Repository\UserRepository;
use App\Repository\VoiceSessionRepository;
use App\Service\Billing\BillingService;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Document\ChatToolLoop;
use App\Service\Document\DocumentKind;
use App\Service\Document\DocumentToolsConfig;
use App\Service\Document\Model\WordModel;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolInterface;
use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Document\Tool\DocumentToolResult;
use App\Service\Email\InternalEmailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Infrastructure\CircuitBreaker;
use App\Service\Mcp\McpClient;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\ModelConfigService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\Usage\AiChatUsageMeter;
use App\Service\Usage\AiPipelineMeter;
use App\Service\Usage\CostCalculationService;
use App\Service\Usage\FacadeMeteredChat;
use App\Service\Usage\LegacyUsageWriter;
use App\Service\Usage\TranscriptionUsageRecorder;
use App\Service\Usage\TurnUsageLedger;
use App\Service\Usage\UsageFailureLogger;
use App\Service\Voice\VoiceLiveUsageService;
use App\Tests\Characterization\Support\OpenAIFixtures;
use App\Tests\Characterization\Support\OpenAISdkRecorder;
use App\Tests\Characterization\Support\ProviderHttpRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Unit tests for {@see UsageHook}, {@see RateLimitHook}, {@see LegacyUsageWriter}
 * and {@see TurnUsageLedger} across `legacy`, `shadow` and `on` modes
 * (`03_contracts.md` §1.5, S1 AU11).
 */
#[AllowMockObjectsWithoutExpectations]
final class UsageHookTest extends TestCase
{
    private const SECRET = 'test-usage-hook-key';
    private const USER_ID = 7;

    /** @var list<array<string, mixed>> */
    private array $buselog = [];

    /** @var list<array<string, mixed>> */
    private array $mismatches = [];

    protected function tearDown(): void
    {
        LegacyUsageWriter::bind(null);
        UsageHookConfig::setModeOverride(null);
    }

    public function testTwoRoundDocumentToolLoopWritesIdenticalRowsUnderAllThreeModes(): void
    {
        $rowsByMode = [];
        foreach ([UsageHookMode::Legacy, UsageHookMode::Shadow, UsageHookMode::On] as $mode) {
            $rowsByMode[$mode->value] = $this->runDocumentToolLoop($mode);
            self::assertSame([], $this->mismatches, 'No shadow mismatch expected on document tool loop.');
        }

        // Two AI_CALL rows (one per round) + one zero-cost MESSAGES quota row with summed facade_charged_cost.
        self::assertCount(3, $rowsByMode['legacy']);
        self::assertSame(['AI_CALL', 'AI_CALL', 'MESSAGES'], array_column($rowsByMode['legacy'], 'action'));
        self::assertSame('0.000000', $rowsByMode['legacy'][2]['cost']);
        self::assertSame('0.000304', $rowsByMode['legacy'][2]['metadata']['facade_charged_cost']);
        self::assertSame($rowsByMode['legacy'], $rowsByMode['shadow']);
        self::assertSame($rowsByMode['legacy'], $rowsByMode['on']);
    }

    public function testThreeRoundGatewayMcpToolLoopWritesIdenticalRowsUnderAllThreeModes(): void
    {
        $rowsByMode = [];
        foreach ([UsageHookMode::Legacy, UsageHookMode::Shadow, UsageHookMode::On] as $mode) {
            $rowsByMode[$mode->value] = $this->runThreeRoundGatewayLoop($mode);
            self::assertSame([], $this->mismatches, 'No shadow mismatch expected on 3-round gateway loop.');
        }

        // Three AI_CALL rows + three MCP_TOOL rows + one zero-cost MESSAGES quota row with summed facade_charged_cost.
        $messagesRows = array_values(array_filter(
            $rowsByMode['legacy'],
            static fn (array $row): bool => 'MESSAGES' === $row['action'],
        ));
        self::assertCount(1, $messagesRows, 'Exactly one MESSAGES quota row per turn.');
        self::assertSame('0.000000', $messagesRows[0]['cost']);
        self::assertSame('0.000456', $messagesRows[0]['metadata']['facade_charged_cost']);
        self::assertSame($rowsByMode['legacy'], $rowsByMode['shadow']);
        self::assertSame($rowsByMode['legacy'], $rowsByMode['on']);
    }

    public function testDeliberatelyWrongShadowComputationLogsMismatch(): void
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::message('Reply')]),
        ], [self::SECRET]);
        [$facade, $limits, , $user, , , $meter] = $this->buildSpine($sdk, UsageHookMode::Shadow);

        $meter->setShadowPerturbation(static function (array $expected): array {
            $expected['total_tokens'] += 99;

            return $expected;
        });

        $result = $facade->chat([['role' => 'user', 'content' => 'Hello']], self::USER_ID, [
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'model_id' => 12,
        ]);
        LegacyUsageWriter::record($limits, $user, 'MESSAGES', FacadeMeteredChat::messages($result, 'WEB', 'Hello', 'Reply'));

        self::assertNotEmpty($this->mismatches, 'Deliberately wrong shadow must log ai.usage_mismatch.');
        self::assertSame('AI_CALL', $this->mismatches[0]['action']);
        self::assertSame(99, $this->mismatches[0]['token_delta']);
    }

    public function testTurnUsageLedgerDeduplicatesContributionsAndIdempotentlyCloses(): void
    {
        $ledger = new TurnUsageLedger();

        self::assertTrue($ledger->addContribution('turn-42', 'call-1', [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
            'charged_cost' => '0.000100',
            'price_known' => true,
            'facade_metered' => true,
        ]));
        // Duplicate contribution with same callId is ignored:
        self::assertFalse($ledger->addContribution('turn-42', 'call-1', [
            'prompt_tokens' => 999,
            'completion_tokens' => 999,
            'total_tokens' => 1998,
            'charged_cost' => '9.999999',
        ]));
        self::assertTrue($ledger->addContribution('turn-42', 'call-2', [
            'prompt_tokens' => 20,
            'completion_tokens' => 10,
            'total_tokens' => 30,
            'charged_cost' => '0.000250',
            'price_known' => true,
            'facade_metered' => true,
        ]));

        $closed = $ledger->close('turn-42');
        self::assertNotNull($closed);
        self::assertSame(2, $closed->callCount);
        self::assertSame(30, $closed->promptTokens);
        self::assertSame(15, $closed->completionTokens);
        self::assertSame(45, $closed->totalTokens);
        self::assertSame('0.000350', $closed->chargedCost);

        // Second close returns null (idempotent):
        self::assertNull($ledger->close('turn-42'));
        self::assertTrue($ledger->isClosed('turn-42'));
    }

    public function testRollbackFromOnToLegacyRestoresCallerWriterWithExactlyOneRowPerCall(): void
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::message('First under on')]),
            OpenAIFixtures::response([OpenAIFixtures::message('Second under legacy')]),
        ], [self::SECRET]);
        [$facade, $limits, , $user] = $this->buildSpine($sdk, UsageHookMode::On);

        $first = $facade->chat([['role' => 'user', 'content' => 'One']], self::USER_ID, ['provider' => 'openai', 'model' => 'gpt-4o', 'model_id' => 12]);
        LegacyUsageWriter::record($limits, $user, 'MESSAGES', FacadeMeteredChat::messages($first, 'WEB', 'One', 'First under on'));
        self::assertCount(2, $this->buselog);

        // Flip mode back to Legacy at runtime (rollback):
        UsageHookConfig::setModeOverride(UsageHookMode::Legacy);
        $second = $facade->chat([['role' => 'user', 'content' => 'Two']], self::USER_ID, ['provider' => 'openai', 'model' => 'gpt-4o', 'model_id' => 12]);
        LegacyUsageWriter::record($limits, $user, 'MESSAGES', FacadeMeteredChat::messages($second, 'WEB', 'Two', 'Second under legacy'));

        self::assertCount(4, $this->buselog);
        self::assertSame(['AI_CALL', 'MESSAGES', 'AI_CALL', 'MESSAGES'], array_column($this->buselog, 'action'));
    }

    public function testLiveVoiceWritesOneRowUnderAllThreeModes(): void
    {
        foreach ([UsageHookMode::Legacy, UsageHookMode::Shadow, UsageHookMode::On] as $mode) {
            $sdk = new OpenAISdkRecorder([], [self::SECRET]);
            [, $limits, $em, $user, $modelRepo] = $this->buildSpine($sdk, $mode);

            $sessions = $this->createStub(VoiceSessionRepository::class);
            $sessions->method('findOpenForUser')->willReturn(null);

            $priceHistory = $this->createStub(ModelPriceHistoryRepository::class);
            $priceHistory->method('findPriceAtTimestamp')->willReturn(null);
            $costs = new CostCalculationService($modelRepo, $priceHistory, new NullLogger());

            $budgetGate = $this->createStub(PremiumFeatureGate::class);
            $budgetGate->method('isCostBudgetEnforced')->willReturn(false);

            $configRepo = $this->createStub(ConfigRepository::class);
            $configRepo->method('getValue')->willReturn('1800');

            $voiceService = new VoiceLiveUsageService($em, $sessions, $costs, $limits, $budgetGate, $configRepo);
            $model = $modelRepo->find(12);
            self::assertInstanceOf(Model::class, $model);

            $session = $voiceService->open($user, $model, 5, 'web');
            self::assertInstanceOf(VoiceSession::class, $session);
            self::assertCount(1, $this->buselog, sprintf('Live voice must write 1 row under mode %s.', $mode->value));
            self::assertSame(AiAction::VoiceLive->value, $this->buselog[0]['action']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runDocumentToolLoop(UsageHookMode $mode): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([
                OpenAIFixtures::message('Editing section.'),
                OpenAIFixtures::functionCall('doc_replace_text', '{"old":"a","new":"b"}', 'call_doc_1'),
            ]),
            OpenAIFixtures::response([OpenAIFixtures::message('Updated the document.')]),
        ], [self::SECRET]);
        [$facade, $limits, , $user] = $this->buildSpine($sdk, $mode);

        $tool = new class implements DocumentToolInterface {
            public function name(): string
            {
                return 'doc_replace_text';
            }

            public function appliesTo(): array
            {
                return [DocumentKind::DOCX];
            }

            public function declaration(): array
            {
                return [
                    'type' => 'function',
                    'function' => [
                        'name' => 'doc_replace_text',
                        'description' => 'Replace text',
                        'parameters' => ['type' => 'object', 'properties' => []],
                    ],
                ];
            }

            public function execute(DocumentSession $session, array $arguments): DocumentToolResult
            {
                return DocumentToolResult::ok('Replaced text', 'processing.documentStepDone');
            }
        };

        $docConfig = $this->createStub(DocumentToolsConfig::class);
        $docConfig->method('maxIterations')->willReturn(4);
        $docConfig->method('maxOpsPerTurn')->willReturn(8);

        $loop = new ChatToolLoop(
            $facade,
            new DocumentToolRegistry([$tool]),
            $docConfig,
            new NullLogger(),
        );

        $session = new DocumentSession(WordModel::empty());
        $loopResult = $loop->run(
            [['role' => 'user', 'content' => 'Replace a with b']],
            $session,
            ['provider' => 'openai', 'model' => 'gpt-4o', 'model_id' => 12, 'usage_source' => 'WEB'],
            self::USER_ID,
        );

        $metadata = [
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'model_id' => 12,
            'usage' => $loopResult->usage,
            'facade_metered' => $loopResult->facadeMetered,
            'facade_charged_cost' => $loopResult->facadeChargedCost,
            'facade_price_known' => $loopResult->facadePriceKnown,
        ];
        LegacyUsageWriter::record($limits, $user, 'MESSAGES', FacadeMeteredChat::messages($metadata, 'WEB', 'Replace a with b', $loopResult->content));

        return $this->buselog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runThreeRoundGatewayLoop(UsageHookMode $mode): array
    {
        $sdk = new OpenAISdkRecorder([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::functionCall('mcp__1__search', '{"q":"one"}', 'call_mcp_1')]),
            OpenAIFixtures::response([OpenAIFixtures::functionCall('mcp__1__search', '{"q":"two"}', 'call_mcp_2')]),
            OpenAIFixtures::response([OpenAIFixtures::message('All three rounds done.')]),
        ], [self::SECRET]);
        [$facade, $limits, , $user] = $this->buildSpine($sdk, $mode);

        $catalog = $this->createStub(OpenAiGatewayToolCatalog::class);
        $catalog->method('build')->willReturn([
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'mcp__1__search',
                    'description' => 'Search',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ]],
            'dispatch' => [
                'mcp__1__search' => [
                    'kind' => 'mcp',
                    'serverId' => 1,
                    'tool' => 'search',
                    'annotations' => ['readOnlyHint' => true],
                ],
            ],
        ]);

        $server = $this->createStub(\App\Entity\McpServerConfig::class);
        $server->method('isEnabled')->willReturn(true);
        $servers = $this->createStub(McpServerConfigRepository::class);
        $servers->method('findByIdAndUser')->willReturn($server);

        $mcpClient = $this->createStub(McpClient::class);
        $mcpClient->method('callTool')->willReturn([
            'content' => [['type' => 'text', 'text' => 'hit']],
            'isError' => false,
        ]);

        $gwConfig = $this->createStub(MessagesGatewayConfig::class);
        $gwConfig->method('mcpMaxIterations')->willReturn(5);

        $loop = new OpenAiGatewayToolLoop(
            $catalog,
            $facade,
            $this->createStub(\App\AI\Messages\Tools\WebSearchTool::class),
            $mcpClient,
            $this->createStub(\App\AI\Messages\Mcp\McpToolCatalogAdapter::class),
            $servers,
            $gwConfig,
            $limits,
            new NullLogger(),
        );

        $result = $loop->complete(
            $user,
            [['role' => 'user', 'content' => 'Search twice']],
            ['provider' => 'openai', 'model' => 'gpt-4o', 'model_id' => 12],
        );

        LegacyUsageWriter::record($limits, $user, 'MESSAGES', FacadeMeteredChat::messages($result, 'OPENAI_API', 'Search twice', (string) ($result['content'] ?? ''), [
            'model_id' => 12,
        ]));

        return $this->buselog;
    }

    /**
     * @return array{0: AiFacade, 1: RateLimitService, 2: EntityManagerInterface, 3: User, 4: ModelRepository, 5: ModelConfigService, 6: AiPipelineMeter}
     */
    private function buildSpine(OpenAISdkRecorder $sdk, UsageHookMode $mode): array
    {
        $this->buselog = [];
        $this->mismatches = [];

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('getRateLimitLevel')->willReturn('PRO');
        $user->method('getUserLevel')->willReturn('PRO');
        $user->method('getSubscriptionData')->willReturn([]);

        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('find')->willReturn($user);

        $model = $this->createStub(Model::class);
        $model->method('getId')->willReturn(12);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('gpt-4o');
        $model->method('getName')->willReturn('GPT-4o');
        $model->method('getTag')->willReturn('chat');
        $model->method('getActive')->willReturn(1);
        $model->method('getPriceIn')->willReturn(2.50);
        $model->method('getInUnit')->willReturn('per1M');
        $model->method('getPriceOut')->willReturn(10.00);
        $model->method('getOutUnit')->willReturn('per1M');
        $model->method('isPriceKnown')->willReturn(true);
        $model->method('getJson')->willReturn([]);

        $modelRepo = $this->createStub(ModelRepository::class);
        $modelRepo->method('getProviderCapabilities')->willReturn(['openai' => ['chat']]);
        $modelRepo->method('find')->willReturn($model);
        $modelRepo->method('findChatIdByProvider')->willReturn(12);
        $modelRepo->method('findIdByServiceProviderIdAndTag')->willReturn(12);

        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn('0');
        $conn->method('lastInsertId')->willReturn('1');
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            if (str_contains($sql, 'INSERT INTO BUSELOG')) {
                $metadata = json_decode((string) ($params['metadata'] ?? '{}'), true);
                $metadata = \is_array($metadata) ? $metadata : [];
                unset($metadata['latency'], $metadata['last_tick'], $metadata['voice_session_id']);
                ksort($metadata);

                $this->buselog[] = [
                    'action' => (string) ($params['action'] ?? ''),
                    'cost' => number_format((float) ($params['cost'] ?? 0), 6, '.', ''),
                    'metadata' => $metadata,
                    'model' => (string) ($params['model'] ?? ''),
                    'model_id' => isset($params['model_id']) ? (int) $params['model_id'] : null,
                    'provider' => (string) ($params['provider'] ?? ''),
                    'tokens' => (int) ($params['tokens'] ?? 0),
                ];
            }

            return 1;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('find')->willReturnCallback(static fn (string $class): object => User::class === $class ? $user : $model);

        $configRepo = $this->createStub(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static fn (int $owner, string $group, string $setting): string => 'AI' === $group && 'USAGE_HOOK' === $setting ? $mode->value : '10');
        $configRepo->method('findBy')->willReturn([]);

        $billing = $this->createStub(BillingService::class);
        $billing->method('isEnabled')->willReturn(true);

        $priceHistory = $this->createStub(ModelPriceHistoryRepository::class);
        $priceHistory->method('findPriceAtTimestamp')->willReturn(null);
        $costs = new CostCalculationService($modelRepo, $priceHistory, new NullLogger());

        $subscription = $this->createStub(Subscription::class);
        $subscription->method('getCostBudgetMonthly')->willReturn('50.00');
        $subscriptions = $this->createStub(SubscriptionRepository::class);
        $subscriptions->method('findOneBy')->willReturn($subscription);

        $topups = $this->createStub(TopupRepository::class);
        $topups->method('sumForUserInPeriod')->willReturn(0.0);

        $limits = new RateLimitService($configRepo, $em, new NullLogger(), $billing, $costs, $subscriptions, $topups);

        $mismatchLogger = new class(function (array $ctx): void {
            $this->mismatches[] = $ctx;
        }) extends AbstractLogger {
            /** @param \Closure(array<string, mixed>): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('ai.usage_mismatch' === ($context['event'] ?? null)) {
                    ($this->record)($context);
                }
            }
        };

        $hookConfig = new UsageHookConfig($configRepo);
        $meter = new AiPipelineMeter($limits, $costs, $userRepo, $modelRepo, $hookConfig, new TurnUsageLedger(), null, $mismatchLogger);

        $http = new ProviderHttpRecorder([], [self::SECRET]);
        $openAi = new OpenAIProvider(new NullLogger(), $http->client(), self::SECRET, '/tmp', false, sdkFactory: $sdk->factory());
        $locator = new ServiceLocator(['openai' => static fn (): OpenAIProvider => $openAi]);
        $registry = new ProviderRegistry($locator, $locator, $locator, $locator, $locator, $locator, $locator, $locator, $modelRepo, new NullLogger(), 'openai', 'test');

        $modelConfig = $this->createStub(ModelConfigService::class);
        $modelConfig->method('getDefaultProvider')->willReturn('openai');

        $breaker = new CircuitBreaker(new ArrayAdapter(), new NullLogger());
        $healthConfig = $this->createStub(\App\AI\Health\ModelHealthConfig::class);
        $healthConfig->method('windowSeconds')->willReturn(3600);
        $health = new \App\AI\Health\ModelHealthRecorder(
            new ArrayAdapter(),
            $healthConfig,
            new \App\AI\Health\FailureClassifier(),
            $modelRepo,
            new UsageFailureLogger($conn, new NullLogger()),
            new NullLogger(),
        );

        $pipeline = new AiCallPipeline([
            new RequestContextHook(),
            new RateLimitHook($meter),
            new CircuitBreakerHook($breaker),
            new ModelHealthHook($health),
            new UsageHook($meter),
        ]);

        $sharedCache = new ArrayAdapter();
        $facade = new AiFacade(
            $registry,
            $modelConfig,
            $breaker,
            new NullLogger(),
            new UserUploadPathBuilder(),
            $this->createStub(DiscordNotificationService::class),
            $this->createStub(InternalEmailService::class),
            $sharedCache,
            $sharedCache,
            $this->createStub(\App\AI\Credential\HiggsfieldCredentialResolver::class),
            new TranscriptionUsageRecorder($limits, $userRepo, new NullLogger()),
            $health,
            '/tmp',
        );
        $facade->setUsageMeter(new AiChatUsageMeter($limits, $userRepo, $modelRepo, new NullLogger()));
        $facade->setPipeline($pipeline);

        return [$facade, $limits, $em, $user, $modelRepo, $modelConfig, $meter];
    }
}
