<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Health\FailureClassifier;
use App\AI\Health\FailureKind;
use App\AI\Health\ModelAutoDisabler;
use App\AI\Health\ModelHealthAlert;
use App\AI\Health\ModelHealthAlerter;
use App\AI\Health\ModelHealthConfig;
use App\AI\Health\ModelHealthEvaluator;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Health\ModelHealthState;
use App\AI\Health\Probe\ModelListProbeInterface;
use App\AI\Health\Probe\ModelListProbeRegistry;
use App\AI\Health\Probe\ProbeResult;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderDisplayNames;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Entity\ModelHealth;
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\Email\InternalEmailService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\Usage\UsageFailureLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The incident mail is once per model per day. A model that stays offline, or
 * that recovers and fails again, does not page again until that day has passed.
 * A different model still pages the same day, and a still-broken model is not
 * announced as recovered just because its message is being held back.
 */
#[AllowMockObjectsWithoutExpectations]
final class ModelHealthEvaluatorQuietPeriodTest extends TestCase
{
    /** @var array<int, ModelHealth> */
    private array $healthRows = [];

    private ArrayAdapter $alertCache;

    private InternalEmailService&MockObject $email;

    private DiscordNotificationService&MockObject $discord;

    protected function setUp(): void
    {
        $this->healthRows = [];
        $this->alertCache = new ArrayAdapter();
        $this->email = $this->createMock(InternalEmailService::class);
        $this->discord = $this->createMock(DiscordNotificationService::class);
    }

    public function testModelsThatStayOfflineAreReportedOnceAndNotCleared(): void
    {
        $this->email->expects(self::once())->method('sendModelHealthAlert');
        $this->discord->expects(self::once())->method('notifyModelHealth');

        $voice = self::model(1, 'xAI', 'grok-voice-3.0', 'Grok Voice 3');
        $grok = self::model(2, 'xAI', 'grok-4.20', 'Grok 4.20');
        $evaluator = $this->evaluatorFor([
            'xAI' => [
                'grok-voice-3.0' => [$voice],
                'grok-4.20' => [$grok],
            ],
        ], ['grok-4.5']);

        $first = $evaluator->run();
        self::assertCount(1, $first->alertsRaised);
        self::assertEqualsCanonicalizing(
            ['Grok Voice 3', 'Grok 4.20'],
            $first->alertsRaised[0]->modelNames,
        );
        self::assertSame([], $first->alertsResolved);
        self::assertGreaterThan(0, $this->healthRows[1]->getLastAlerted());
        self::assertGreaterThan(0, $this->healthRows[2]->getLastAlerted());

        $second = $evaluator->run();
        self::assertSame([], $second->alertsRaised, 'The same models must stay quiet while they are still offline');
        self::assertSame([], $second->alertsResolved, 'Holding a message back is not a recovery');
        self::assertSame(ModelHealthState::Offline, $second->verdicts[0]->state);
    }

    public function testADifferentModelIsReportedWhileAnEarlierOneStaysQuiet(): void
    {
        $this->healthRows[1] = (new ModelHealth())
            ->setModelId(1)
            ->setLastAlerted(time())
            ->setLastAlertedKind(ModelHealthAlert::KIND_OFFLINE);

        $this->email->expects(self::once())->method('sendModelHealthAlert');
        $this->discord->expects(self::once())->method('notifyModelHealth');

        $voice = self::model(1, 'xAI', 'grok-voice-3.0', 'Grok Voice 3');
        $gemini = self::model(2, 'Google', 'gemini-2.5-pro', 'Gemini 2.5 Pro');
        $run = $this->evaluatorFor([
            'xAI' => ['grok-voice-3.0' => [$voice]],
            'Google' => ['gemini-2.5-pro' => [$gemini]],
        ], ['grok-4.5', 'gemini-2.5-flash'])->run();

        self::assertCount(1, $run->alertsRaised);
        self::assertSame(['Gemini 2.5 Pro'], $run->alertsRaised[0]->modelNames);
        self::assertSame([2], $run->alertsRaised[0]->modelIds);
        self::assertSame('Google', $run->alertsRaised[0]->provider);
        self::assertSame([], $run->alertsResolved);
        self::assertGreaterThan(0, $this->healthRows[2]->getLastAlerted());
    }

    public function testRecoveryDoesNotReopenTheSameModelTheSameDay(): void
    {
        $this->email->expects(self::exactly(2))->method('sendModelHealthAlert');
        $this->discord->expects(self::exactly(2))->method('notifyModelHealth');

        $voice = self::model(1, 'xAI', 'grok-voice-3.0', 'Grok Voice 3');
        $probe = new class implements ModelListProbeInterface {
            /** @var list<string> */
            public array $listed = ['grok-4.5'];

            public function supports(string $service): bool
            {
                return true;
            }

            public function probe(string $service): ProbeResult
            {
                return ProbeResult::ok($this->listed);
            }

            public function confirm(string $service, string $providerModelId): ModelProbeResult
            {
                return ModelProbeResult::Gone;
            }
        };

        $evaluator = $this->evaluatorFor(
            ['xAI' => ['grok-voice-3.0' => [$voice]]],
            ['grok-4.5'],
            $probe,
        );

        $down = $evaluator->run();
        self::assertCount(1, $down->alertsRaised);
        self::assertSame([], $down->alertsResolved);

        $probe->listed = ['grok-4.5', 'grok-voice-3.0'];
        $back = $evaluator->run();
        self::assertSame([], $back->alertsRaised);
        self::assertCount(1, $back->alertsResolved);
        self::assertSame(ModelHealthState::Online, $back->verdicts[0]->state);

        $probe->listed = ['grok-4.5'];
        $downAgain = $evaluator->run();
        self::assertSame([], $downAgain->alertsRaised, 'The same model must not page again the day it was already reported');
        self::assertSame([], $downAgain->alertsResolved);
        self::assertSame(ModelHealthState::Offline, $downAgain->verdicts[0]->state);
    }

    /**
     * A flaky provider that then rejects the key: the credential incident
     * (the one that pings everyone) goes out although the same models were
     * reported an hour ago, and the flaky incident is not declared over —
     * those models are worse, not recovered.
     */
    public function testARejectedKeyAfterAFlakyHourIsReportedWithoutAFalseAllClear(): void
    {
        $sent = [];
        $this->email->method('sendModelHealthAlert')->willReturnCallback(
            static function (ModelHealthAlert $alert, bool $resolved = false) use (&$sent): void {
                $sent[] = [$alert->kind, $resolved];
            }
        );

        $voice = self::model(1, 'xAI', 'grok-voice-3.0', 'Grok Voice 3');
        $probe = new class implements ModelListProbeInterface {
            public ProbeResult $next;

            public function supports(string $service): bool
            {
                return true;
            }

            public function probe(string $service): ProbeResult
            {
                return $this->next;
            }

            public function confirm(string $service, string $providerModelId): ModelProbeResult
            {
                return ModelProbeResult::Inconclusive;
            }
        };
        $evaluator = $this->evaluatorFor(['xAI' => ['grok-voice-3.0' => [$voice]]], [], $probe);

        $probe->next = ProbeResult::failed(FailureKind::Transient, 'xAI answered 503.');
        $flaky = $evaluator->run();
        self::assertSame(ModelHealthAlert::KIND_DEGRADED, $flaky->alertsRaised[0]->kind);

        $probe->next = ProbeResult::failed(FailureKind::Credential, 'xAI rejected the API key.');
        $rejected = $evaluator->run();
        self::assertCount(1, $rejected->alertsRaised);
        self::assertSame(ModelHealthAlert::KIND_CREDENTIAL, $rejected->alertsRaised[0]->kind);
        self::assertSame([], $rejected->alertsResolved, 'A model that got worse has not recovered');

        self::assertSame([
            [ModelHealthAlert::KIND_DEGRADED, false],
            [ModelHealthAlert::KIND_CREDENTIAL, false],
        ], $sent);
    }

    /**
     * @param array<string, array<string, list<Model>>> $catalog service => provider id => rows
     * @param list<string>                              $listed
     */
    private function evaluatorFor(array $catalog, array $listed, ?ModelListProbeInterface $probe = null): ModelHealthEvaluator
    {
        $probe ??= new class($listed) implements ModelListProbeInterface {
            /** @param list<string> $listed */
            public function __construct(private readonly array $listed)
            {
            }

            public function supports(string $service): bool
            {
                return true;
            }

            public function probe(string $service): ProbeResult
            {
                return ProbeResult::ok($this->listed);
            }

            public function confirm(string $service, string $providerModelId): ModelProbeResult
            {
                return ModelProbeResult::Gone;
            }
        };

        $models = $this->createStub(ModelRepository::class);
        $models->method('findAllServices')->willReturn(array_keys($catalog));
        $models->method('findByServiceIndexedByProviderId')->willReturnCallback(
            static fn (string $service): array => $catalog[$service] ?? [],
        );

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);
        $config = new ModelHealthConfig($configRepository);

        $health = $this->createStub(ModelHealthRepository::class);
        $health->method('findIndexedByModelId')->willReturnCallback(function (array $ids = []): array {
            if ([] === $ids) {
                return $this->healthRows;
            }

            $found = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if (isset($this->healthRows[$id])) {
                    $found[$id] = $this->healthRows[$id];
                }
            }

            return $found;
        });
        $health->method('findOrCreate')->willReturnCallback(function (int $id): ModelHealth {
            return $this->healthRows[$id] ??= (new ModelHealth())->setModelId($id);
        });
        $health->method('stampAlerted')->willReturnCallback(function (array $ids, string $kind, int $at): void {
            foreach ($ids as $id) {
                $this->healthRows[$id] ??= (new ModelHealth())->setModelId($id);
                $this->healthRows[$id]->setLastAlerted($at)->setLastAlertedKind($kind);
            }
        });

        $alerter = new ModelHealthAlerter(
            $this->alertCache,
            $health,
            $config,
            $this->email,
            $this->discord,
            new NullLogger(),
        );

        return new ModelHealthEvaluator(
            $models,
            $health,
            new ModelListProbeRegistry([$probe]),
            new ModelHealthRecorder(
                new ArrayAdapter(),
                $config,
                new FailureClassifier(),
                $models,
                $this->createStub(UsageFailureLogger::class),
                new NullLogger(),
            ),
            $config,
            $alerter,
            new ModelAutoDisabler($config, new NullLogger()),
            $this->createStub(EntityManagerInterface::class),
            new ArrayAdapter(),
            new ProviderDisplayNames($this->createStub(ProviderRegistry::class)),
            new NullLogger(),
        );
    }

    private static function model(int $id, string $service, string $providerId, string $name): Model
    {
        $model = new Model();
        $model->setService($service)
            ->setProviderId($providerId)
            ->setName($name)
            ->setTag('chat');

        $reflection = new \ReflectionProperty(Model::class, 'id');
        $reflection->setValue($model, $id);

        return $model;
    }
}
