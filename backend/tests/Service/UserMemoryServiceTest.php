<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Exception\MemoryServiceUnavailableException;
use App\Service\Memory\MemoryEmbeddingModelResolver;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests für UserMemoryService (Qdrant-basiert).
 */
final class UserMemoryServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private QdrantClientInterface&MockObject $qdrantClient;
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private RateLimitService&MockObject $rateLimitService;
    private EmbeddingMetadataService&MockObject $embeddingMetadata;
    private MemoryEmbeddingModelResolver&MockObject $memoryEmbeddingResolver;
    private LoggerInterface&MockObject $logger;
    private UserMemoryService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->embeddingMetadata = $this->createMock(EmbeddingMetadataService::class);
        $this->memoryEmbeddingResolver = $this->createMock(MemoryEmbeddingModelResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default: stale-filter pass-through so legacy tests behave as before.
        $this->embeddingMetadata->method('filterStaleHits')->willReturnCallback(
            static fn (array $hits) => ['fresh' => $hits, 'stale_count' => 0]
        );

        // Default: resolver returns null model — the legacy tests don't
        // care which model is used because they mock $aiFacade->embed()
        // unconditionally. Specific tests below override this to assert
        // the sticky-model behaviour.
        $this->memoryEmbeddingResolver->method('resolve')->willReturn([
            'provider' => null,
            'model' => null,
            'model_id' => null,
            'vector_dim' => null,
        ]);
        $this->memoryEmbeddingResolver->method('getModelId')->willReturn(null);

        $this->service = new UserMemoryService(
            $this->em,
            $this->qdrantClient,
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->embeddingMetadata,
            $this->memoryEmbeddingResolver,
            $this->logger
        );
    }

    public function testIsAvailableDelegatesToQdrantClient(): void
    {
        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $result = $this->service->isAvailable();

        $this->assertTrue($result);
    }

    public function testIsAvailableReturnsFalseWhenQdrantUnavailable(): void
    {
        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $result = $this->service->isAvailable();

        $this->assertFalse($result);
    }

    public function testGetQdrantClientReturnsClient(): void
    {
        $client = $this->service->getQdrantClient();

        $this->assertSame($this->qdrantClient, $client);
    }

    public function testDeleteMemoryCallsQdrantClient(): void
    {
        $memoryId = 1768900000;
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->qdrantClient
            ->expects($this->once())
            ->method('deleteMemory')
            ->with("mem_123_{$memoryId}");

        $this->service->deleteMemory($memoryId, $user);
    }

    public function testDeleteMemoryThrowsWhenQdrantUnavailable(): void
    {
        $memoryId = 1768900000;
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->qdrantClient
            ->expects($this->never())
            ->method('deleteMemory');

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteMemory($memoryId, $user);
    }

    public function testCreateMemoryThrowsWhenQdrantUnavailable(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->qdrantClient
            ->expects($this->never())
            ->method('upsertMemory');

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->createMemory($user, 'personal', 'favourite_colour', 'green');
    }

    public function testUpdateMemoryThrowsWhenQdrantUnavailable(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->qdrantClient
            ->expects($this->never())
            ->method('getMemory');
        $this->qdrantClient
            ->expects($this->never())
            ->method('upsertMemory');

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->updateMemory(1768900000, $user, 'new value');
    }

    /**
     * Covers the race window where isAvailable() returns true (health cache
     * hit) but the subsequent Qdrant call fails. The service must translate
     * the underlying \RuntimeException into MemoryServiceUnavailableException
     * so the controller returns 503 instead of leaking the raw error as a
     * misleading 400 or 500.
     */
    public function testDeleteMemoryMapsRuntimeExceptionFromQdrantTo503(): void
    {
        $memoryId = 1768900000;
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->qdrantClient
            ->expects($this->once())
            ->method('deleteMemory')
            ->with("mem_123_{$memoryId}")
            ->willThrowException(new \RuntimeException('Qdrant request failed: HTTP 500'));

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteMemory($memoryId, $user);
    }

    /**
     * Same race as above, but for updateMemory() where the failure happens
     * during the preflight getMemory() lookup.
     */
    public function testUpdateMemoryMapsRuntimeExceptionFromQdrantTo503(): void
    {
        $memoryId = 1768900000;
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->qdrantClient
            ->expects($this->once())
            ->method('getMemory')
            ->with("mem_123_{$memoryId}")
            ->willThrowException(new \RuntimeException('Qdrant request failed: connection refused'));

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->updateMemory($memoryId, $user, 'new value');
    }

    public function testServiceIsAvailableWhenQdrantConfigured(): void
    {
        $this->qdrantClient
            ->method('isAvailable')
            ->willReturn(true);

        $result = $this->service->isAvailable();

        $this->assertTrue($result);
    }

    public function testResolveMemoryTagsWithNoTags(): void
    {
        $user = $this->createMock(User::class);

        $result = $this->service->resolveMemoryTags('Hello world', $user);

        $this->assertSame('Hello world', $result);
    }

    public function testResolveMemoryTagsSingleTag(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->expects(self::any())->method('getMemory')
            ->with('mem_1_12345')
            ->willReturn(['key' => 'name', 'value' => 'Cristian', 'category' => 'personal']);

        $result = $this->service->resolveMemoryTags('Hallo [Memory:12345]', $user);

        $this->assertSame('Hallo Cristian', $result);
    }

    public function testResolveMemoryTagsMultipleDifferentTags(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->method('getMemory')
            ->willReturnCallback(fn (string $pointId): ?array => match ($pointId) {
                'mem_1_111' => ['key' => 'name', 'value' => 'Cristian', 'category' => 'personal'],
                'mem_1_222' => ['key' => 'city', 'value' => 'Berlin', 'category' => 'personal'],
                default => null,
            });

        $result = $this->service->resolveMemoryTags(
            'Hallo [Memory:111], du wohnst in [Memory:222]!',
            $user
        );

        $this->assertSame('Hallo Cristian, du wohnst in Berlin!', $result);
    }

    public function testResolveMemoryTagsRepeatedIdOnlyLookedUpOnce(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->expects($this->once())
            ->method('getMemory')
            ->with('mem_1_111')
            ->willReturn(['key' => 'name', 'value' => 'Cristian', 'category' => 'personal']);

        $result = $this->service->resolveMemoryTags(
            '[Memory:111] ist [Memory:111]',
            $user
        );

        $this->assertSame('Cristian ist Cristian', $result);
    }

    public function testResolveMemoryTagsUnknownIdRemoved(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->method('getMemory')->willReturn(null);

        $result = $this->service->resolveMemoryTags('Hallo [Memory:99999]!', $user);

        $this->assertSame('Hallo !', $result);
    }

    public function testResolveMemoryTagsHandlesTrailingDots(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->expects(self::any())->method('getMemory')
            ->with('mem_1_12345')
            ->willReturn(['key' => 'name', 'value' => 'Cristian', 'category' => 'personal']);

        $result = $this->service->resolveMemoryTags('Hallo [Memory:12345...]', $user);

        $this->assertSame('Hallo Cristian', $result);
    }

    /**
     * Regression for #948.
     *
     * After an embedding-model swap to a wider model (1024 → 1536) the
     * live path used to call `upsertMemory()` with a 1536-dim vector
     * against a 1024-dim collection. Qdrant returned HTTP 400 and the
     * memory was silently lost — the controller saw a "success" because
     * the upsert exception was swallowed by the worker. The dim safety
     * net MUST short-circuit BEFORE the upsert call so the failure
     * surfaces as a clean RuntimeException (mapped to 503 / 5xx by the
     * outer layers).
     */
    public function testCreateMemoryRejectsDimensionMismatchBeforeQdrantUpsert(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->method('scrollMemories')->willReturn([]);

        // Collection was created with 1024-dim but the new model produces 1536-dim vectors.
        $this->qdrantClient->method('getMemoriesCollectionInfo')->willReturn([
            'exists' => true,
            'vector_dim' => 1024,
            'points_count' => 15,
            'distance' => 'Cosine',
        ]);

        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->em->method('getRepository')->willReturn($this->createMock(\Doctrine\ORM\EntityRepository::class));

        $this->aiFacade
            ->method('embed')
            ->willReturn([
                'embedding' => array_fill(0, 1536, 0.1),
                'usage' => ['total_tokens' => 4],
            ]);

        // The upsert MUST NOT be reached — that's the silent-data-loss path.
        $this->qdrantClient->expects($this->never())->method('upsertMemory');

        $this->expectException(MemoryServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/dimension mismatch/i');

        $this->service->createMemory($user, 'personal', 'city', 'Berlin');
    }

    /**
     * Issue #985 follow-up: after a VECTORIZE swap that left the
     * memories collection frozen, write paths must keep embedding
     * against the *memory-pinned* (sticky) model — NOT the new
     * VECTORIZE default. Without this distinction every new memory
     * would either be rejected (dim mismatch with the still-old
     * collection) or stored in a vector space that's incompatible
     * with every existing point (useless for similarity search).
     *
     * The mock has VECTORIZE = 99 (1536-dim) but the sticky pointer
     * is 42 (3072-dim, matching the collection). We must see embed()
     * receive the STICKY model name, not VECTORIZE's.
     */
    public function testCreateMemoryEmbedsAgainstStickyMemoryModelNotVectorize(): void
    {
        // Override the per-class default to point at a real sticky model.
        $resolver = $this->createMock(MemoryEmbeddingModelResolver::class);
        $resolver->method('resolve')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'model_id' => 42,
            'vector_dim' => 3072,
        ]);
        $resolver->method('getModelId')->willReturn(42);
        $service = new UserMemoryService(
            $this->em,
            $this->qdrantClient,
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->embeddingMetadata,
            $resolver,
            $this->logger,
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->method('scrollMemories')->willReturn([]);
        $this->qdrantClient->method('getMemoriesCollectionInfo')->willReturn([
            'exists' => true,
            'vector_dim' => 3072,
            'points_count' => 10,
            'distance' => 'Cosine',
        ]);

        // VECTORIZE points at a NARROWER model in BCONFIG (e.g. the
        // operator just swapped to text-embedding-3-small) — the
        // service must ignore it for memory writes.
        $this->modelConfigService->expects(self::any())->method('getDefaultModel')->with('VECTORIZE')->willReturn(99);

        // The crucial assertion: embed() is called with the sticky
        // model's name, not the active VECTORIZE one. If the service
        // ever falls back to VECTORIZE again, this expectation fails.
        $this->aiFacade
            ->expects($this->once())
            ->method('embed')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $opts): bool {
                    return ('text-embedding-3-large' === ($opts['model'] ?? null))
                        && ('openai' === ($opts['provider'] ?? null));
                })
            )
            ->willReturn([
                'embedding' => array_fill(0, 3072, 0.1),
                'usage' => ['total_tokens' => 4],
            ]);

        $upsertedPayload = null;
        $this->qdrantClient
            ->expects($this->once())
            ->method('upsertMemory')
            ->willReturnCallback(function (string $pointId, array $vector, array $payload) use (&$upsertedPayload): void {
                $upsertedPayload = $payload;
            });

        $service->createMemory($user, 'personal', 'city', 'Berlin');

        // The persisted payload must record the sticky model id so a
        // later stale-filter pass keeps treating these as fresh.
        $this->assertSame(42, $upsertedPayload['embedding_model_id']);
        $this->assertSame(3072, $upsertedPayload['vector_dim']);
        $this->assertSame('text-embedding-3-large', $upsertedPayload['embedding_model']);
    }
}
