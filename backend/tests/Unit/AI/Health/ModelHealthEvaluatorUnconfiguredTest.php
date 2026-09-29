<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Health\FailureClassifier;
use App\AI\Health\ModelAutoDisabler;
use App\AI\Health\ModelHealthAlerter;
use App\AI\Health\ModelHealthConfig;
use App\AI\Health\ModelHealthEvaluator;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Health\ModelHealthState;
use App\AI\Health\ModelHealthVerdict;
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
 * A provider nobody configured is not broken. The evaluator already maps a
 * skipped probe to Unconfigured; this locks the alert side so three unused
 * Triton catalog rows never become "3 NVIDIA Triton model(s) failing".
 */
final class ModelHealthEvaluatorUnconfiguredTest extends TestCase
{
    public function testUnconfiguredTritonDoesNotRaiseAnIncident(): void
    {
        $models = [
            self::model(1, 'llama-3.1-8b'),
            self::model(2, 'bge-m3'),
            self::model(3, 'whisper-large-v3'),
        ];

        $run = $this->evaluatorFor($models, ProbeResult::skipped('NVIDIA Triton is not configured.'))
            ->run(dryRun: true);

        self::assertCount(3, $run->verdicts);
        foreach ($run->verdicts as $verdict) {
            self::assertSame(ModelHealthState::Unconfigured, $verdict->state);
            self::assertFalse($verdict->safeToDisable);
        }
        self::assertSame(['triton' => 'NVIDIA Triton is not configured.'], $run->skippedProviders);
        self::assertSame([], $run->alertsRaised, 'Unconfigured Triton must not page operators');
    }

    public function testUnconfiguredOllamaDoesNotRaiseAnIncident(): void
    {
        $models = [
            self::model(10, 'llama3.2', 'ollama'),
            self::model(11, 'bge-m3', 'ollama'),
            self::model(12, 'whisper', 'ollama'),
        ];

        $run = $this->evaluatorFor($models, ProbeResult::skipped('OLLAMA_BASE_URL is not configured.'), 'ollama')
            ->run(dryRun: true);

        self::assertCount(3, $run->verdicts);
        foreach ($run->verdicts as $verdict) {
            self::assertSame(ModelHealthState::Unconfigured, $verdict->state);
            self::assertFalse($verdict->safeToDisable);
        }
        self::assertSame(['ollama' => 'OLLAMA_BASE_URL is not configured.'], $run->skippedProviders);
        self::assertSame([], $run->alertsRaised, 'Unconfigured Ollama must not page operators');
    }

    /**
     * Catalog rows say "Ollama", imported rows say "ollama". The row lookup is
     * case-insensitive, so both spellings are one provider and every row must
     * be judged exactly once, not once per spelling.
     */
    public function testBothSpellingsOfOneServiceAreCheckedOnce(): void
    {
        $models = [
            self::model(20, 'gpt-oss:20b', 'Ollama'),
            self::model(21, 'nemotron-3-nano', 'ollama'),
        ];

        $run = $this->evaluatorFor(
            $models,
            ProbeResult::skipped('OLLAMA_BASE_URL is not configured.'),
            'ollama',
            ['Ollama', 'ollama'],
        )->run(dryRun: true);

        self::assertCount(2, $run->verdicts);
        self::assertSame([20, 21], array_map(static fn (ModelHealthVerdict $verdict): int => $verdict->modelId, $run->verdicts));
        self::assertSame(['Ollama' => 'OLLAMA_BASE_URL is not configured.'], $run->skippedProviders);
    }

    /**
     * @param list<Model>       $catalog
     * @param list<string>|null $services distinct BSERVICE values as stored; defaults to [$service]
     */
    private function evaluatorFor(array $catalog, ProbeResult $probeResult, string $service = 'triton', ?array $services = null): ModelHealthEvaluator
    {
        $probe = new class($probeResult, $service) implements ModelListProbeInterface {
            public function __construct(
                private readonly ProbeResult $result,
                private readonly string $service,
            ) {
            }

            public function supports(string $service): bool
            {
                return $this->service === mb_strtolower($service);
            }

            public function probe(string $service): ProbeResult
            {
                return $this->result;
            }

            public function confirm(string $service, string $providerModelId): ModelProbeResult
            {
                return ModelProbeResult::Inconclusive;
            }
        };

        $indexed = [];
        foreach ($catalog as $model) {
            $indexed[$model->getProviderId()][] = $model;
        }

        $models = $this->createStub(ModelRepository::class);
        $models->method('findAllServices')->willReturn($services ?? [$service]);
        $models->method('findByServiceIndexedByProviderId')->willReturn($indexed);

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);
        $config = new ModelHealthConfig($configRepository);

        $alerter = new ModelHealthAlerter(
            new ArrayAdapter(),
            $config,
            $this->createStub(InternalEmailService::class),
            $this->createStub(DiscordNotificationService::class),
            new NullLogger(),
        );

        return new ModelHealthEvaluator(
            $models,
            $this->createStub(ModelHealthRepository::class),
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

    private static function model(int $id, string $providerId, string $service = 'triton'): Model
    {
        $model = new Model();
        $model->setService($service)
            ->setProviderId($providerId)
            ->setName($providerId)
            ->setTag('chat');

        $reflection = new \ReflectionProperty(Model::class, 'id');
        $reflection->setValue($model, $id);

        return $model;
    }
}
