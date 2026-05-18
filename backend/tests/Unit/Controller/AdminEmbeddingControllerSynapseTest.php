<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Service\ProviderRegistry;
use App\Controller\AdminEmbeddingController;
use App\Entity\Model;
use App\Entity\RevectorizeRun;
use App\Entity\User;
use App\Message\ReVectorizeMessage;
use App\Repository\ModelRepository;
use App\Repository\RevectorizeRunRepository;
use App\Service\Embedding\EmbeddingCostEstimator;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Embedding\VectorizeBindingService;
use App\Service\Message\SynapseIndexer;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Targets the two new Synapse-specific admin endpoints:
 *   GET  /api/v1/admin/embedding/synapse/status
 *   POST /api/v1/admin/embedding/synapse/switch
 *
 * These cover the contract surface exposed to the frontend:
 *   - status returns the SYNAPSE_VECTORIZE binding + selectable models
 *     + most-recent run, in the JSON shape consumed by
 *     `SortingPromptConfiguration.vue`
 *   - switch validates the incoming model id, persists the new global
 *     binding via ConfigRepository, queues a synapse-scoped re-vector
 *     run, and dispatches the Messenger job
 */
final class AdminEmbeddingControllerSynapseTest extends TestCase
{
    private EmbeddingMetadataService&MockObject $embeddingMetadata;
    private EmbeddingCostEstimator&MockObject $costEstimator;
    private EmbeddingModelChangeGuard&MockObject $changeGuard;
    private RevectorizeRunRepository&MockObject $runRepository;
    private ModelRepository&MockObject $modelRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private ProviderRegistry&MockObject $providerRegistry;
    private VectorizeBindingService&MockObject $bindingService;
    private SynapseIndexer&MockObject $synapseIndexer;
    private MessageBusInterface&MockObject $messageBus;
    private AdminEmbeddingController $controller;

    protected function setUp(): void
    {
        $this->embeddingMetadata = $this->createMock(EmbeddingMetadataService::class);
        $this->costEstimator = $this->createMock(EmbeddingCostEstimator::class);
        $this->changeGuard = $this->createMock(EmbeddingModelChangeGuard::class);
        $this->runRepository = $this->createMock(RevectorizeRunRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->bindingService = $this->createMock(VectorizeBindingService::class);
        $this->synapseIndexer = $this->createMock(SynapseIndexer::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        // Default: provider is configured (has API key). Individual tests
        // override this to exercise the provider-availability gate (#948).
        $this->providerRegistry
            ->method('getEmbeddingProvider')
            ->willReturn($this->makeEmbeddingProvider(true));

        $this->controller = new AdminEmbeddingController(
            $this->embeddingMetadata,
            $this->costEstimator,
            $this->changeGuard,
            $this->runRepository,
            $this->modelRepository,
            $this->modelConfigService,
            $this->providerRegistry,
            $this->bindingService,
            $this->synapseIndexer,
            $this->messageBus,
            new NullLogger(),
        );

        // Minimal container so AbstractController::json() works (it
        // looks up the serializer service if present, otherwise falls
        // back to plain json_encode — both paths are fine for tests).
        $this->controller->setContainer(new Container());
    }

    private function makeEmbeddingProvider(bool $available): EmbeddingProviderInterface
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('isAvailable')->willReturn($available);

        return $provider;
    }

    public function testSynapseStatusReturnsCurrentModelAndCatalog(): void
    {
        $this->synapseIndexer
            ->method('getEmbeddingModelInfo')
            ->willReturn([
                'model_id' => 188,
                'provider' => 'cloudflare',
                'model' => '@cf/qwen/qwen3-embedding-0.6b',
                'vector_dim' => 1024,
            ]);

        $this->modelRepository
            ->method('findBy')
            ->willReturnCallback(fn (array $criteria) => 'vectorize' === ($criteria['tag'] ?? null)
                ? [$this->makeModel(187, 'bge-m3', 'Cloudflare', '@cf/baai/bge-m3'), $this->makeModel(188, 'Qwen3-Embedding-0.6B', 'Cloudflare', '@cf/qwen/qwen3-embedding-0.6b')]
                : []);

        $this->runRepository->method('findLatestForScope')->willReturn(null);
        $this->runRepository->method('findActive')->willReturn(null);

        $response = $this->controller->synapseStatus();
        $payload = $this->decode($response);

        $this->assertTrue($payload['success']);
        $this->assertSame(188, $payload['currentModel']['modelId']);
        $this->assertSame('cloudflare', $payload['currentModel']['provider']);
        $this->assertSame(1024, $payload['currentModel']['vectorDim']);
        $this->assertCount(2, $payload['availableModels']);
        $this->assertSame(187, $payload['availableModels'][0]['id']);
        $this->assertSame(188, $payload['availableModels'][1]['id']);
        $this->assertNull($payload['latestRun']);
        $this->assertNull($payload['activeRun']);
    }

    public function testSynapseStatusSerializesActiveRun(): void
    {
        $run = $this->makeRun(42, RevectorizeRun::SCOPE_SYNAPSE, RevectorizeRun::STATUS_RUNNING);

        $this->synapseIndexer->method('getEmbeddingModelInfo')->willReturn([
            'model_id' => 187, 'provider' => 'cloudflare', 'model' => 'bge-m3', 'vector_dim' => 1024,
        ]);
        $this->modelRepository->method('findBy')->willReturn([]);
        $this->runRepository->method('findLatestForScope')->willReturn($run);
        $this->runRepository->method('findActive')->willReturn($run);

        $payload = $this->decode($this->controller->synapseStatus());

        $this->assertNotNull($payload['activeRun']);
        $this->assertSame(42, $payload['activeRun']['id']);
        $this->assertSame('synapse', $payload['activeRun']['scope']);
        $this->assertSame('running', $payload['activeRun']['status']);
    }

    public function testSwitchRejectsUnauthenticated(): void
    {
        $request = Request::create('/api/v1/admin/embedding/synapse/switch', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['toModelId' => 188]));

        $response = $this->controller->synapseSwitch($request, null);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSwitchRejectsInvalidModelId(): void
    {
        $request = Request::create('/api/v1/admin/embedding/synapse/switch', 'POST', content: json_encode(['toModelId' => 0]));

        $response = $this->controller->synapseSwitch($request, $this->makeUser(1));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString('toModelId', (string) $response->getContent());
    }

    public function testSwitchRejectsMissingModel(): void
    {
        $this->modelRepository->method('find')->with(999)->willReturn(null);

        $request = Request::create('/api/v1/admin/embedding/synapse/switch', 'POST', content: json_encode(['toModelId' => 999]));

        $response = $this->controller->synapseSwitch($request, $this->makeUser(1));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testSwitchRejectsNonVectorizeModel(): void
    {
        // Tag mismatch — picking a chat model for an embedding binding
        // would silently break Synapse Routing once the new binding is
        // active. Fail fast here.
        $this->modelRepository->method('find')->willReturn($this->makeModel(99, 'gpt-x', 'openai', 'gpt-x', 'chat'));

        $request = Request::create('/api/v1/admin/embedding/synapse/switch', 'POST', content: json_encode(['toModelId' => 99]));

        $response = $this->controller->synapseSwitch($request, $this->makeUser(1));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testSwitchPersistsBindingBeforeDispatchingJob(): void
    {
        // The order matters: setSynapseVectorizeModel() MUST run before
        // dispatch() so the worker resolves the new model id; we
        // assert this by recording call order on the binding service.
        $callOrder = [];
        $this->modelRepository->method('find')->willReturn($this->makeModel(188, 'Qwen3', 'cloudflare', '@cf/qwen/qwen3'));
        $this->modelConfigService->method('getProviderForModel')->willReturn('cloudflare');
        $this->synapseIndexer->method('getEmbeddingModelInfo')->willReturn([
            'model_id' => 187, 'provider' => 'cloudflare', 'model' => 'bge-m3', 'vector_dim' => 1024,
        ]);
        $this->runRepository->method('findActive')->willReturn(null);

        $this->bindingService
            ->expects($this->once())
            ->method('setSynapseVectorizeModel')
            ->with(188)
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'bind:188';
            });

        $this->runRepository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (RevectorizeRun $run) use (&$callOrder) {
                $callOrder[] = 'save:run';
                // Simulate Doctrine assigning an id post-save so the
                // dispatched message carries something meaningful.
                $reflection = new \ReflectionClass(RevectorizeRun::class);
                $property = $reflection->getProperty('id');
                $property->setValue($run, 7);
            });

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (ReVectorizeMessage $msg) use (&$callOrder) {
                $callOrder[] = 'dispatch:'.$msg->runId;

                return new Envelope($msg);
            });

        $request = Request::create('/api/v1/admin/embedding/synapse/switch', 'POST', content: json_encode(['toModelId' => 188]));

        $response = $this->controller->synapseSwitch($request, $this->makeUser(1));
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(7, $payload['runId']);
        $this->assertSame(187, $payload['fromModelId']);
        $this->assertSame(188, $payload['toModelId']);

        // bind MUST come before save:run + dispatch, otherwise a
        // freshly-spawned worker would still read the old binding.
        $bindIndex = array_search('bind:188', $callOrder, true);
        $dispatchIndex = array_search('dispatch:7', $callOrder, true);
        $this->assertNotFalse($bindIndex);
        $this->assertNotFalse($dispatchIndex);
        $this->assertLessThan($dispatchIndex, $bindIndex, 'Binding must be persisted before the re-vectorize job is dispatched.');
    }

    public function testSwitchRefusesProviderWithoutApiKey(): void
    {
        // Override the default "provider available" mock for this test.
        $unavailableRegistry = $this->createMock(ProviderRegistry::class);
        $unavailableRegistry->method('getEmbeddingProvider')
            ->willReturn($this->makeEmbeddingProvider(false));

        $controller = new AdminEmbeddingController(
            $this->embeddingMetadata,
            $this->costEstimator,
            $this->changeGuard,
            $this->runRepository,
            $this->modelRepository,
            $this->modelConfigService,
            $unavailableRegistry,
            $this->bindingService,
            $this->synapseIndexer,
            $this->messageBus,
            new NullLogger(),
        );
        $controller->setContainer(new Container());

        $this->modelRepository->method('find')->willReturn(
            $this->makeModel(188, 'Qwen3', 'cloudflare', '@cf/qwen/qwen3')
        );
        $this->modelConfigService->method('getProviderForModel')->willReturn('cloudflare');

        // The switch MUST short-circuit before touching BCONFIG / dispatching.
        $this->bindingService->expects($this->never())->method('setSynapseVectorizeModel');
        $this->messageBus->expects($this->never())->method('dispatch');

        $request = Request::create('/api/v1/admin/embedding/synapse/switch', 'POST', content: json_encode(['toModelId' => 188]));
        $response = $controller->synapseSwitch($request, $this->makeUser(1));
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('provider_not_configured', $payload['error']);
        $this->assertSame('cloudflare', $payload['provider']);
    }

    public function testSwitchRefusesWhenAnotherRunIsActive(): void
    {
        $this->modelRepository->method('find')->willReturn(
            $this->makeModel(188, 'Qwen3', 'cloudflare', '@cf/qwen/qwen3')
        );
        $this->modelConfigService->method('getProviderForModel')->willReturn('cloudflare');
        $this->synapseIndexer->method('getEmbeddingModelInfo')->willReturn([
            'model_id' => 187, 'provider' => 'cloudflare', 'model' => 'bge-m3', 'vector_dim' => 1024,
        ]);

        // A previous switch is still being processed.
        $this->runRepository
            ->method('findActive')
            ->willReturn($this->makeRun(99, RevectorizeRun::SCOPE_SYNAPSE, RevectorizeRun::STATUS_RUNNING));

        // Must NOT swap BCONFIG or dispatch — that's the rapid-click bug
        // from the issue (admin bypasses cooldown → overlapping runs).
        $this->bindingService->expects($this->never())->method('setSynapseVectorizeModel');
        $this->messageBus->expects($this->never())->method('dispatch');

        $request = Request::create('/api/v1/admin/embedding/synapse/switch', 'POST', content: json_encode(['toModelId' => 188]));
        $response = $this->controller->synapseSwitch($request, $this->makeUser(1));
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame('run_in_progress', $payload['error']);
        $this->assertSame(99, $payload['activeRun']['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionClass(User::class);
        $property = $reflection->getProperty('id');
        $property->setValue($user, $id);

        return $user;
    }

    private function makeModel(int $id, string $name, string $service, string $providerId, string $tag = 'vectorize'): Model
    {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getName')->willReturn($name);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getTag')->willReturn($tag);

        return $model;
    }

    private function makeRun(int $id, string $scope, string $status): RevectorizeRun
    {
        $run = (new RevectorizeRun())
            ->setUserId(1)
            ->setScope($scope)
            ->setModelToId(188)
            ->setStatus($status)
            ->setSeverity('info')
            ->setChunksTotal(0);

        $reflection = new \ReflectionClass(RevectorizeRun::class);
        $property = $reflection->getProperty('id');
        $property->setValue($run, $id);

        return $run;
    }
}
