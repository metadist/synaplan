<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AdminSynapseController;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\Message\SynapseIndexer;
use App\Service\Message\SynapseRouter;
use App\Service\Message\TopicAliasResolver;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class AdminSynapseControllerTest extends TestCase
{
    private SynapseIndexer&MockObject $indexer;
    private SynapseRouter&MockObject $router;
    private QdrantClientInterface&MockObject $qdrant;
    private PromptRepository&MockObject $promptRepository;
    private AdminSynapseController $controller;

    protected function setUp(): void
    {
        $this->indexer = $this->createMock(SynapseIndexer::class);
        $this->router = $this->createMock(SynapseRouter::class);
        $this->qdrant = $this->createMock(QdrantClientInterface::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);

        $this->controller = new AdminSynapseController(
            $this->indexer,
            $this->router,
            $this->qdrant,
            $this->promptRepository,
            new TopicAliasResolver(),
            new NullLogger(),
        );

        $container = new Container();
        $container->set('serializer', new class {
            public function serialize(mixed $data, string $format): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }
        });
        $this->controller->setContainer($container);
    }

    private function makePrompt(string $topic, int $ownerId = 0, bool $enabled = true): Prompt&MockObject
    {
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getTopic')->willReturn($topic);
        $prompt->method('getOwnerId')->willReturn($ownerId);
        $prompt->method('isEnabled')->willReturn($enabled);

        return $prompt;
    }

    public function testStatusReturnsActiveModelAndAggregations(): void
    {
        $this->indexer->method('getEmbeddingModelInfo')->willReturn([
            'provider' => 'cloudflare',
            'model' => 'bge-m3',
            'model_id' => 42,
            'vector_dim' => 1024,
        ]);

        $this->qdrant->method('getSynapseCollectionInfo')->willReturn([
            'exists' => true,
            'vector_dim' => 1024,
            'points_count' => 2,
            'distance' => 'Cosine',
        ]);
        $this->qdrant->method('getSynapseCollection')->willReturn('synapse_topics');

        // Two indexed points: one fresh (model 42), one stale (model 7)
        $this->qdrant->method('scrollSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'payload' => [
                    'topic' => 'general',
                    'embedding_model_id' => 42,
                    'embedding_provider' => 'cloudflare',
                    'embedding_model' => 'bge-m3',
                    'vector_dim' => 1024,
                ],
            ],
            [
                'id' => 'synapse_0_coding',
                'payload' => [
                    'topic' => 'coding',
                    'embedding_model_id' => 7,
                    'embedding_provider' => 'openai',
                    'embedding_model' => 'text-embedding-3-large',
                    'vector_dim' => 1024,
                ],
            ],
        ]);

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('general'),
            $this->makePrompt('coding'),
            $this->makePrompt('disabled-topic', enabled: false),
        ]);

        $response = $this->controller->status();
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertSame(42, $body['activeModel']['modelId']);
        self::assertSame(1024, $body['activeModel']['vectorDim']);
        self::assertSame(2, $body['totalIndexed']);
        self::assertSame(1, $body['staleCount']);
        self::assertFalse($body['dimensionMismatch']);
        self::assertCount(2, $body['perModel']);
        self::assertCount(3, $body['topics']);
        self::assertArrayHasKey('aliases', $body);
    }

    public function testStatusFlagsDimensionMismatch(): void
    {
        $this->indexer->method('getEmbeddingModelInfo')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'model_id' => 99,
            'vector_dim' => 3072,
        ]);
        $this->qdrant->method('getSynapseCollectionInfo')->willReturn([
            'exists' => true,
            'vector_dim' => 1024,
            'points_count' => 0,
            'distance' => 'Cosine',
        ]);
        $this->qdrant->method('getSynapseCollection')->willReturn('synapse_topics');
        $this->qdrant->method('scrollSynapseTopics')->willReturn([]);
        $this->promptRepository->method('findAllForUser')->willReturn([]);

        $response = $this->controller->status();
        $body = json_decode((string) $response->getContent(), true);

        self::assertTrue($body['dimensionMismatch']);
    }

    public function testReindexAllReturnsCounters(): void
    {
        $this->indexer->expects($this->once())
            ->method('indexAllTopics')
            ->with(null, false)
            ->willReturn(['indexed' => 5, 'skipped' => 2, 'errors' => 1]);

        $request = Request::create('/api/v1/admin/synapse/reindex', 'POST', content: json_encode([]));
        $response = $this->controller->reindex($request);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertFalse($body['recreated']);
        self::assertFalse($body['force']);
        self::assertSame(5, $body['indexed']);
        self::assertSame(2, $body['skipped']);
        self::assertSame(1, $body['errors']);
    }

    public function testReindexWithRecreateDropsCollectionFirst(): void
    {
        $this->indexer->method('getEmbeddingModelInfo')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'model_id' => 99,
            'vector_dim' => 3072,
        ]);

        $this->qdrant->expects($this->once())
            ->method('recreateSynapseCollection')
            ->with(3072);

        $this->indexer->expects($this->once())
            ->method('indexAllTopics')
            ->with(null, true) // recreate implies force
            ->willReturn(['indexed' => 7, 'skipped' => 0, 'errors' => 0]);

        $request = Request::create(
            '/api/v1/admin/synapse/reindex',
            'POST',
            content: json_encode(['recreate' => true])
        );
        $response = $this->controller->reindex($request);
        $body = json_decode((string) $response->getContent(), true);

        self::assertTrue($body['recreated']);
        self::assertTrue($body['force']);
        self::assertSame(7, $body['indexed']);
    }

    public function testReindexSingleTopicReturnsTopicResult(): void
    {
        $this->indexer->expects($this->once())
            ->method('indexTopic')
            ->with('coding', 0, true)
            ->willReturn('indexed');

        $request = Request::create(
            '/api/v1/admin/synapse/reindex',
            'POST',
            content: json_encode(['topic' => 'coding', 'force' => true])
        );
        $response = $this->controller->reindex($request);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame('coding', $body['topic']);
        self::assertSame('indexed', $body['topicResult']);
        self::assertSame(1, $body['indexed']);
    }

    public function testDryRunRequiresText(): void
    {
        $request = Request::create('/api/v1/admin/synapse/dry-run', 'POST', content: json_encode([]));
        $response = $this->controller->dryRun($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testDryRunDelegatesToRouter(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);

        // Make the controller see a logged-in user.
        $token = new class($user) {
            public function __construct(private $user)
            {
            }

            public function getUser()
            {
                return $this->user;
            }
        };

        // The controller calls $this->getUser(); use reflection to inject the user.
        $tokenStorage = new class($user) implements \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface {
            public function __construct(private \Symfony\Component\Security\Core\User\UserInterface $user)
            {
            }

            public function getToken(): \Symfony\Component\Security\Core\Authentication\Token\TokenInterface
            {
                return new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($this->user, 'main');
            }

            public function setToken(?\Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token = null): void
            {
            }
        };

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('serializer', new class {
            public function serialize(mixed $data, string $format): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }
        });
        $this->controller->setContainer($container);

        $this->router->expects($this->once())
            ->method('dryRun')
            ->with('test message', 7, 5)
            ->willReturn([
                'query' => 'test message',
                'model' => ['provider' => 'cloudflare', 'model' => 'bge-m3', 'model_id' => 42],
                'candidates' => [],
                'latency_ms' => 12.3,
                'error' => null,
            ]);

        $request = Request::create(
            '/api/v1/admin/synapse/dry-run',
            'POST',
            content: json_encode(['text' => 'test message'])
        );
        $response = $this->controller->dryRun($request);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertSame('test message', $body['query']);
    }
}
