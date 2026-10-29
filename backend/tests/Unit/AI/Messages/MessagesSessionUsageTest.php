<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\MessagesContextInjector;
use App\AI\Messages\MessagesGateway;
use App\AI\Messages\MessagesModelResolver;
use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Tools\WebFetchPolicy;
use App\AI\Messages\Translator\AnthropicPassthroughTranslator;
use App\AI\Messages\Vision\VisionPolicy;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\Hook\UsageHook;
use App\AI\Pipeline\UsageHookConfig;
use App\AI\Pipeline\UsageHookMode;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use App\Repository\UserRepository;
use App\Service\Billing\BillingService;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use App\Service\Usage\AiPipelineMeter;
use App\Service\Usage\CostCalculationService;
use App\Service\Usage\LegacyUsageWriter;
use App\Service\Usage\TurnUsageLedger;
use App\Service\Usage\TurnUsageTotal;
use App\Service\Vision\VisionModelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A Messages session reuses one session key for conversation memory.
 * Each HTTP request still gets its own usage turn, and provider call ids
 * do not repeat after the meter resets.
 *
 * The ledger stub keeps closed turns when the meter resets. Redis does that
 * in production. The in-memory ledger forgets them, which hides a second
 * request whose turn id was the session key.
 */
#[AllowMockObjectsWithoutExpectations]
final class MessagesSessionUsageTest extends TestCase
{
    private const SESSION = 'claude-session-same';
    private const USER_ID = 7;
    private const MODEL_ID = 42;

    /** @var list<array{action: string, cost: string, prompt: int, completion: int, tokens: int}> */
    private array $rows = [];

    /** @var list<array<string, mixed>> */
    private array $mismatches = [];

    /** @var list<string> */
    private array $sessionKeys = [];

    /** @var list<string> */
    private array $turnIds = [];

    /** @var list<string> */
    private array $callIds = [];

    /** @var list<TurnUsageTotal|null> */
    private array $closes = [];

    protected function tearDown(): void
    {
        LegacyUsageWriter::bind(null);
        UsageHookConfig::setModeOverride(null);
    }

    public function testJoinedSourcesStayJoined(): void
    {
        self::assertTrue(AiCallSource::MessagesApi->isJoined());
        self::assertTrue(AiCallSource::VoiceLive->isJoined());
    }

    public function testSecondRequestInTheSameSessionIsNotAZeroUsageRow(): void
    {
        UsageHookConfig::setModeOverride(UsageHookMode::On);
        [$gateway, $meter, $user] = $this->build(providerCalls: 1);

        $this->execute($gateway, $user);
        $meter->reset();
        $this->execute($gateway, $user);

        $messages = $this->messagesRows();
        self::assertCount(2, $messages);
        self::assertSame(10, $messages[1]['prompt']);
        self::assertSame(4, $messages[1]['completion']);
        self::assertSame(14, $messages[1]['tokens']);
        self::assertNotSame('0.000000', $messages[1]['cost']);
        self::assertNotSame($this->turnIds[0], $this->turnIds[1]);
        self::assertStringNotContainsString(self::SESSION, $this->turnIds[0]);
        self::assertStringNotContainsString(self::SESSION, $this->turnIds[1]);
        self::assertNotSame($this->callIds[0], $this->callIds[1]);
        self::assertSame([self::SESSION, self::SESSION], $this->sessionKeys);
    }

    public function testShadowCompareOnTheSecondRequestReadsThatRequestLedger(): void
    {
        UsageHookConfig::setModeOverride(UsageHookMode::Shadow);
        [$gateway, $meter, $user] = $this->build(providerCalls: 1);

        $this->execute($gateway, $user);
        $meter->reset();
        $this->execute($gateway, $user);

        self::assertCount(2, $this->closes);
        self::assertInstanceOf(TurnUsageTotal::class, $this->closes[1]);
        self::assertGreaterThan(0, $this->closes[1]->callCount);
        self::assertSame([], $this->mismatches);
        self::assertNotSame($this->turnIds[0], $this->turnIds[1]);
        self::assertSame([self::SESSION, self::SESSION], $this->sessionKeys);
    }

    public function testThirdProviderCallInOneRequestSumsIntoThatTurn(): void
    {
        UsageHookConfig::setModeOverride(UsageHookMode::On);
        [$gateway, , $user] = $this->build(providerCalls: 3);

        $this->execute($gateway, $user);

        $messages = $this->messagesRows();
        self::assertCount(1, $messages);
        self::assertSame(30, $messages[0]['prompt']);
        self::assertSame(12, $messages[0]['completion']);
        self::assertSame(42, $messages[0]['tokens']);
        self::assertNotSame('0.000000', $messages[0]['cost']);
        self::assertCount(3, $this->callIds);
        self::assertCount(3, array_unique($this->callIds));
        self::assertCount(1, array_unique($this->turnIds));
        self::assertCount(1, $this->closes);
        self::assertInstanceOf(TurnUsageTotal::class, $this->closes[0]);
        self::assertSame(3, $this->closes[0]->callCount);
        self::assertSame([self::SESSION], $this->sessionKeys);
    }

    public function testLegacyStillWritesOneRowPerRequest(): void
    {
        UsageHookConfig::setModeOverride(UsageHookMode::Legacy);
        [$gateway, $meter, $user] = $this->build(providerCalls: 1);

        $this->execute($gateway, $user);
        $meter->reset();
        $this->execute($gateway, $user);

        $messages = $this->messagesRows();
        self::assertCount(2, $messages);
        self::assertSame(10, $messages[0]['prompt']);
        self::assertSame(4, $messages[0]['completion']);
        self::assertSame(14, $messages[0]['tokens']);
        self::assertSame($messages[0], $messages[1]);
        self::assertNotSame('0.000000', $messages[1]['cost']);
        self::assertSame([self::SESSION, self::SESSION], $this->sessionKeys);
    }

    /**
     * @return list<array{action: string, cost: string, prompt: int, completion: int, tokens: int}>
     */
    private function messagesRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => 'MESSAGES' === $row['action'],
        ));
    }

    private function execute(MessagesGateway $gateway, User $user): void
    {
        $prepared = $gateway->prepare($this->request(), $user);
        if (false === $prepared['ok']) {
            self::fail($prepared['message']);
        }
        self::assertSame(self::SESSION, $prepared['session_key']);

        $executed = $gateway->executeComplete($prepared, $user);
        if (false === $executed['ok']) {
            self::fail($executed['message']);
        }
        self::assertSame(200, $executed['status']);
        self::assertSame(self::SESSION, $executed['session_key']);
    }

    /**
     * @return array{0: MessagesGateway, 1: AiPipelineMeter, 2: User}
     */
    private function build(int $providerCalls): array
    {
        $this->rows = [];
        $this->mismatches = [];
        $this->sessionKeys = [];
        $this->turnIds = [];
        $this->callIds = [];
        $this->closes = [];

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('getRateLimitLevel')->willReturn('PRO');
        $user->method('getSubscriptionData')->willReturn([]);

        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        $model = $this->createStub(Model::class);
        $model->method('getId')->willReturn(self::MODEL_ID);
        $model->method('getService')->willReturn('scripted');
        $model->method('getProviderId')->willReturn('scripted-1');
        $model->method('getPriceIn')->willReturn(2.5);
        $model->method('getPriceOut')->willReturn(10.0);
        $model->method('getInUnit')->willReturn('per1M');
        $model->method('getOutUnit')->willReturn('per1M');
        $model->method('isPriceKnown')->willReturn(true);
        $model->method('getJson')->willReturn([]);

        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturn($model);

        $prices = $this->createStub(ModelPriceHistoryRepository::class);
        $prices->method('findPriceAtTimestamp')->willReturn(null);
        $costs = new CostCalculationService($models, $prices, new NullLogger());

        $configRepo = $this->createStub(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn('0');

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            if (str_contains($sql, 'INSERT INTO BUSELOG')) {
                $this->rows[] = [
                    'action' => (string) ($params['action'] ?? ''),
                    'cost' => number_format((float) ($params['cost'] ?? 0), 6, '.', ''),
                    'prompt' => (int) ($params['prompt_tokens'] ?? 0),
                    'completion' => (int) ($params['completion_tokens'] ?? 0),
                    'tokens' => (int) ($params['tokens'] ?? 0),
                ];
            }

            return 1;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $subscriptions = $this->createStub(SubscriptionRepository::class);
        $subscriptions->method('findOneBy')->willReturn(null);
        $topups = $this->createStub(TopupRepository::class);
        $topups->method('sumForUserInPeriod')->willReturn(0.0);

        $limits = new RateLimitService(
            $configRepo,
            $em,
            new NullLogger(),
            new BillingService('', ''),
            $costs,
            $subscriptions,
            $topups,
        );

        $mismatchLogger = new class(function (array $context): void {
            $this->mismatches[] = $context;
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

        $stored = new TurnUsageLedger();
        $ledger = $this->createStub(TurnUsageLedger::class);
        $ledger->method('addContribution')->willReturnCallback(
            function (string $turnId, string $callId, array $contribution) use ($stored): bool {
                $accepted = $stored->addContribution($turnId, $callId, $contribution);
                if ($accepted) {
                    $this->turnIds[] = $turnId;
                    $this->callIds[] = $callId;
                }

                return $accepted;
            },
        );
        $ledger->method('close')->willReturnCallback(
            function (string $turnId) use ($stored): ?TurnUsageTotal {
                $closed = $stored->close($turnId);
                $this->closes[] = $closed;

                return $closed;
            },
        );
        $meter = new AiPipelineMeter(
            $limits,
            $costs,
            $users,
            $models,
            new UsageHookConfig($configRepo),
            $ledger,
            null,
            $mismatchLogger,
        );

        $config = $this->createStub(MessagesGatewayConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('allowOperatorKey')->willReturn(true);
        $config->method('isContextInjectionEnabled')->willReturn(false);
        $config->method('isBudgetNoticeEnabled')->willReturn(false);
        $config->method('isSessionSummaryEnabled')->willReturn(false);
        $config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_AUTO);
        $config->method('visionImageDetail')->willReturn(MessagesGatewayConfig::IMAGE_DETAIL_AUTO);
        $config->method('visionMaxImages')->willReturn(0);
        $config->method('webFetchMode')->willReturn(MessagesGatewayConfig::WEB_FETCH_OFF);
        $config->method('webFetchMaxPerTurn')->willReturn(0);
        $config->method('upstreamUrl')->willReturn('https://upstream.test');

        $resolver = $this->createStub(MessagesModelResolver::class);
        $resolver->method('resolve')->willReturn([
            'provider' => 'scripted',
            'providerModelId' => 'scripted-1',
            'displayModel' => 'Scripted',
            'model_id' => self::MODEL_ID,
            'requested' => 'scripted-1',
            'aliased_from' => null,
        ]);

        $keys = $this->createStub(UserProviderKeyResolver::class);
        $keys->method('resolve')->willReturn(['key' => 'sk-test', 'source' => 'operator']);

        $passthrough = $this->createStub(AnthropicPassthroughTranslator::class);
        $passthrough->method('supports')->willReturn(false);

        $catalog = $this->createStub(GatewayToolCatalog::class);
        $catalog->method('build')->willReturnCallback(function (User $owner, string $sessionKey) use ($providerCalls): array {
            $this->sessionKeys[] = $sessionKey;

            return [
                'tools' => $providerCalls > 1 ? [[
                    'name' => 'lookup',
                    'description' => 'Look up',
                    'input_schema' => ['type' => 'object'],
                ]] : [],
                'dispatch' => [],
                'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
            ];
        });
        $catalog->method('replacedServerTools')->willReturn([]);

        $loop = $this->createStub(GatewayToolLoop::class);
        if ($providerCalls > 1) {
            $loop->method('runComplete')->willReturnCallback(
                static function (array $requestBody, array $translatorContext, MessagesTranslatorInterface $translator) use ($providerCalls): array {
                    $result = $translator->complete($requestBody, $translatorContext);
                    for ($i = 1; $i < $providerCalls; ++$i) {
                        $result = $translator->complete($requestBody, $translatorContext);
                    }

                    return $result;
                },
            );
        }

        $gateway = new MessagesGateway(
            $config,
            $resolver,
            $models,
            $this->createStub(VisionModelResolver::class),
            $keys,
            $limits,
            new PremiumFeatureGate(new BillingService('', '')),
            $passthrough,
            $catalog,
            $loop,
            new WebFetchPolicy(),
            new VisionPolicy($config, new NullLogger()),
            $this->createStub(MessagesContextInjector::class),
            $this->createStub(CacheItemPoolInterface::class),
            $this->createStub(MessageBusInterface::class),
            new NullLogger(),
            translators: [new ScriptedMessagesTranslator(), $passthrough],
        );
        $gateway->setPipeline(new AiCallPipeline([new UsageHook($meter)]));

        return [$gateway, $meter, $user];
    }

    private function request(): Request
    {
        return Request::create('/v1/messages', 'POST', [], [], [], [
            'HTTP_X_CLAUDE_CODE_SESSION_ID' => self::SESSION,
        ], (string) json_encode([
            'model' => 'scripted-1',
            'max_tokens' => 32,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ], \JSON_THROW_ON_ERROR));
    }
}

final class ScriptedMessagesTranslator implements MessagesTranslatorInterface
{
    public function supports(string $providerName): bool
    {
        return 'scripted' === strtolower($providerName);
    }

    public function complete(array $requestBody, array $context): array
    {
        return [
            'status' => 200,
            'headers' => [],
            'body' => [
                'id' => 'msg_scripted',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Answer']],
                'stop_reason' => 'end_turn',
            ],
            'usage' => new MessagesUsage(10, 4, 0, 0, 'end_turn'),
        ];
    }

    public function stream(array $requestBody, array $context, callable $emit): MessagesUsage
    {
        return new MessagesUsage();
    }
}
