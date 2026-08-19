<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Health\FailureClassifier;
use App\AI\Health\FailureKind;
use App\AI\Health\ModelAutoDisabler;
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
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\DiscordNotificationService;
use App\Service\InternalEmailService;
use App\Service\Usage\UsageFailureLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Locks the rule that decides whether a model is declared retired.
 *
 * Both scenarios below are real, and both were verified against the live APIs
 * with this installation's own keys:
 *
 *   - Google omits `imagen-4.0-generate-001` from `models.list` while
 *     `GET /v1beta/models/imagen-4.0-generate-001` answers 200. It is alive.
 *   - xAI omits `grok-tts` from `/v1/models` and answers 404 for it. It is gone.
 *
 * A listing-only verdict cannot tell these apart, and an earlier capability
 * heuristic got both wrong: it condemned the three working Imagen models and
 * excused the dead xAI one. Only the provider's answer about the exact model id
 * decides now.
 */
final class ModelHealthEvaluatorRetirementTest extends TestCase
{
    /** The probe handed to the most recent evaluator, for counting requests. */
    private object $lastProbe;

    /**
     * @param array<string, ModelProbeResult> $confirmations provider model id => what the provider says
     * @param list<string>                    $listed        model ids the bulk listing returns
     */
    private function evaluatorFor(Model $model, array $listed, array $confirmations, bool $authoritative = false): ModelHealthEvaluator
    {
        $probe = new class($listed, $confirmations, $authoritative) implements ModelListProbeInterface {
            public int $confirmCalls = 0;

            /**
             * @param list<string>                    $listed
             * @param array<string, ModelProbeResult> $confirmations
             */
            public function __construct(
                private readonly array $listed,
                private readonly array $confirmations,
                private readonly bool $authoritative,
            ) {
            }

            public function supports(string $service): bool
            {
                return true;
            }

            public function probe(string $service): ProbeResult
            {
                return ProbeResult::ok($this->listed, listingAuthoritative: $this->authoritative);
            }

            public function confirm(string $service, string $providerModelId): ModelProbeResult
            {
                ++$this->confirmCalls;

                return $this->confirmations[$providerModelId] ?? ModelProbeResult::Inconclusive;
            }
        };

        $this->lastProbe = $probe;

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);
        $config = new ModelHealthConfig($configRepository);

        $models = $this->createMock(ModelRepository::class);
        $models->method('findAllServices')->willReturn([$model->getService()]);
        $models->method('findByServiceIndexedByProviderId')
            ->willReturn([$model->getProviderId() => [$model]]);

        $recorder = new ModelHealthRecorder(
            new ArrayAdapter(),
            $config,
            new FailureClassifier(),
            $models,
            $this->createMock(UsageFailureLogger::class),
            new NullLogger(),
        );

        $alerter = new ModelHealthAlerter(
            new ArrayAdapter(),
            $config,
            $this->createMock(InternalEmailService::class),
            $this->createMock(DiscordNotificationService::class),
            new NullLogger(),
        );

        return new ModelHealthEvaluator(
            $models,
            $this->createMock(ModelHealthRepository::class),
            new ModelListProbeRegistry([$probe]),
            $recorder,
            $config,
            $alerter,
            new ModelAutoDisabler($config, new NullLogger()),
            $this->createMock(EntityManagerInterface::class),
            new ArrayAdapter(),
            new ProviderDisplayNames($this->createStub(ProviderRegistry::class)),
            new NullLogger(),
        );
    }

    private static function model(int $id, string $service, string $providerId, string $tag): Model
    {
        $model = new Model();
        $model->setService($service)
            ->setProviderId($providerId)
            ->setName($providerId)
            ->setTag($tag);

        $reflection = new \ReflectionProperty(Model::class, 'id');
        $reflection->setValue($model, $id);

        return $model;
    }

    public function testAModelTheProviderStillServesSurvivesBeingMissingFromTheListing(): void
    {
        // Google's models.list carries gemini-2.5-flash-image but not Imagen.
        $imagen = self::model(115, 'Google', 'imagen-4.0-generate-001', 'text2pic');

        $run = $this->evaluatorFor(
            $imagen,
            ['gemini-2.5-flash-image', 'gemini-2.5-pro'],
            ['imagen-4.0-generate-001' => ModelProbeResult::Alive],
        )->run(dryRun: true);

        $verdict = $run->verdicts[0];
        self::assertSame(ModelHealthState::Online, $verdict->state, 'A model the provider answers 200 for must never be reported as retired');
        self::assertFalse($verdict->safeToDisable);
        self::assertSame([], $run->alertsRaised);
    }

    public function testAModelTheProviderRejectsIsRetiredAndActionable(): void
    {
        // xAI's /v1/models is chat-only, so grok-tts is absent either way —
        // the 404 is what separates it from the Imagen case above.
        $tts = self::model(320, 'xAI', 'grok-tts', 'text2sound');

        $run = $this->evaluatorFor(
            $tts,
            ['grok-4.5'],
            ['grok-tts' => ModelProbeResult::Gone],
        )->run(dryRun: true);

        $verdict = $run->verdicts[0];
        self::assertSame(ModelHealthState::Offline, $verdict->state);
        self::assertSame(FailureKind::Permanent, $verdict->kind);
        self::assertTrue($verdict->safeToDisable);
        self::assertCount(1, $run->alertsRaised);
    }

    /**
     * The dangerous middle ground. A rate-limited or unauthorised confirmation
     * says nothing about the model, and reporting it as retired would route
     * users away from something that very likely works.
     */
    public function testAnInconclusiveConfirmationNeverRetiresAModel(): void
    {
        $model = self::model(320, 'xAI', 'grok-tts', 'text2sound');

        $run = $this->evaluatorFor(
            $model,
            ['grok-4.5'],
            ['grok-tts' => ModelProbeResult::Inconclusive],
        )->run(dryRun: true);

        $verdict = $run->verdicts[0];
        self::assertSame(ModelHealthState::Unknown, $verdict->state);
        self::assertNull($verdict->kind);
        self::assertFalse($verdict->safeToDisable);
        self::assertSame([], $run->alertsRaised);
    }

    /**
     * The check runs every few minutes; a model that the provider already
     * rejected must not be re-asked on every single run. Without this, six
     * retired models cost several hundred pointless requests a day.
     */
    public function testASettledAnswerIsReusedInsteadOfReprobed(): void
    {
        $model = self::model(320, 'xAI', 'grok-tts', 'text2sound');

        $evaluator = $this->evaluatorFor($model, ['grok-4.5'], ['grok-tts' => ModelProbeResult::Gone]);
        $evaluator->run(dryRun: true);
        $evaluator->run(dryRun: true);
        $third = $evaluator->run(dryRun: true);

        self::assertSame(1, $this->lastProbe->confirmCalls);
        self::assertSame(ModelHealthState::Offline, $third->verdicts[0]->state, 'The remembered verdict must survive, not decay into unknown');
    }

    /**
     * An inconclusive answer is the one thing that must NOT be remembered:
     * caching a rate limit would turn a five-minute hiccup into an hour-long
     * blind spot on the status page.
     */
    public function testAnInconclusiveAnswerIsNotRemembered(): void
    {
        $model = self::model(320, 'xAI', 'grok-tts', 'text2sound');

        $evaluator = $this->evaluatorFor($model, ['grok-4.5'], ['grok-tts' => ModelProbeResult::Inconclusive]);
        $evaluator->run(dryRun: true);
        $evaluator->run(dryRun: true);

        self::assertSame(2, $this->lastProbe->confirmCalls);
    }

    /**
     * Ollama lists every pulled model in one namespace, so absence is already
     * the answer and must not cost an extra request per model.
     */
    public function testAnAuthoritativeListingRetiresWithoutAskingAgain(): void
    {
        $model = self::model(7, 'Ollama', 'llama3', 'chat');

        $evaluator = $this->evaluatorFor($model, ['mistral'], [], authoritative: true);
        $run = $evaluator->run(dryRun: true);

        self::assertSame(ModelHealthState::Offline, $run->verdicts[0]->state);
        self::assertTrue($run->verdicts[0]->safeToDisable);
    }
}
