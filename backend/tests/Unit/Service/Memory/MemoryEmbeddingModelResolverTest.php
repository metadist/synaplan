<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Memory;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\Memory\MemoryEmbeddingModelResolver;
use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #985 follow-up: a VECTORIZE swap now leaves the memories
 * collection untouched (to avoid data loss). That decouples the
 * memory layer's embedding model from the active VECTORIZE default —
 * the resolver under test is what finds the "still-correct-for-this-
 * collection" model so writes/reads keep landing in the right vector
 * space.
 */
final class MemoryEmbeddingModelResolverTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private QdrantClientInterface&MockObject $qdrantClient;
    private MemoryEmbeddingModelResolver $resolver;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);

        $this->resolver = new MemoryEmbeddingModelResolver(
            $this->configRepository,
            $this->modelConfigService,
            $this->qdrantClient,
            new NullLogger(),
        );
    }

    public function testReturnsStickyPointerWhenCompatibleWithCollection(): void
    {
        $this->configRepository
            ->expects(self::any())
            ->method('getValue')
            ->with(0, 'MEMORIES', 'EMBEDDING_MODEL_ID')
            ->willReturn('42');

        $this->modelConfigService->expects(self::any())->method('getProviderForModel')->with(42)->willReturn('openai');
        $this->modelConfigService->expects(self::any())->method('getModelName')->with(42)->willReturn('text-embedding-3-large');
        $this->modelConfigService->expects(self::any())->method('getVectorDimForModel')->with(42)->willReturn(3072);

        $this->qdrantClient->method('getMemoriesCollectionInfo')->willReturn([
            'exists' => true,
            'vector_dim' => 3072,
            'points_count' => 5,
            'distance' => 'Cosine',
        ]);

        // Sticky pointer is golden — no scroll or VECTORIZE fallback required.
        $this->qdrantClient->expects($this->never())->method('scrollAllMemoriesForReindex');
        $this->modelConfigService->expects($this->never())->method('getDefaultModel');

        $info = $this->resolver->resolve();

        $this->assertSame(42, $info['model_id']);
        $this->assertSame('text-embedding-3-large', $info['model']);
        $this->assertSame('openai', $info['provider']);
        $this->assertSame(3072, $info['vector_dim']);
    }

    public function testStickyPointerIsClearedAndPayloadInferredOnDimensionDrift(): void
    {
        // Stored sticky says 22 → text-embedding-3-large (3072), but the
        // collection is 1536 (e.g. ops dropped+recreated it manually
        // under a different model). The resolver MUST notice the drift
        // and fall back to payload inference instead of silently using
        // a bogus model id for every subsequent embed call.
        $this->configRepository
            ->expects(self::any())
            ->method('getValue')
            ->with(0, 'MEMORIES', 'EMBEDDING_MODEL_ID')
            ->willReturn('22');

        $this->configRepository
            ->expects(self::any())
            ->method('findByOwnerGroupAndSetting')
            ->with(0, 'MEMORIES', 'EMBEDDING_MODEL_ID')
            ->willReturn(new Config());

        // Sticky says 3072, collection actually 1536.
        $this->modelConfigService
            ->method('getProviderForModel')
            ->willReturnMap([
                [22, 'openai'],
                [11, 'openai'],
            ]);
        $this->modelConfigService
            ->method('getModelName')
            ->willReturnMap([
                [22, 'text-embedding-3-large'],
                [11, 'text-embedding-3-small'],
            ]);
        $this->modelConfigService
            ->method('getVectorDimForModel')
            ->willReturnMap([
                [22, 3072],
                [11, 1536],
            ]);

        $this->qdrantClient->method('getMemoriesCollectionInfo')->willReturn([
            'exists' => true,
            'vector_dim' => 1536,
            'points_count' => 4,
            'distance' => 'Cosine',
        ]);

        $this->qdrantClient->method('scrollAllMemoriesForReindex')->willReturn([
            ['id' => 'mem_1_42', 'payload' => ['embedding_model_id' => 11]],
        ]);

        // Old pointer cleared, new (inferred) pointer written. setValue
        // is called twice: once with '' to clear, once with '11' to pin.
        $writes = [];
        $this->configRepository
            ->method('setValue')
            ->willReturnCallback(function ($ownerId, $group, $setting, $value) use (&$writes) {
                $writes[] = [$ownerId, $group, $setting, $value];

                return new Config();
            });

        $info = $this->resolver->resolve();

        $this->assertSame(11, $info['model_id']);
        $this->assertSame(1536, $info['vector_dim']);
        $this->assertContains([0, 'MEMORIES', 'EMBEDDING_MODEL_ID', ''], $writes, 'stale sticky cleared');
        $this->assertContains([0, 'MEMORIES', 'EMBEDDING_MODEL_ID', '11'], $writes, 'inferred model pinned');
    }

    public function testFallsBackToVectorizeWhenCollectionIsEmpty(): void
    {
        // No sticky pointer, no payloads, no collection (or empty
        // collection) — this is the fresh-install case where the first
        // memory write defines the model.
        $this->configRepository->method('getValue')->willReturn(null);
        $this->qdrantClient->method('getMemoriesCollectionInfo')->willReturn([
            'exists' => false,
            'vector_dim' => null,
            'points_count' => null,
            'distance' => null,
        ]);
        $this->qdrantClient->method('scrollAllMemoriesForReindex')->willReturn([]);

        $this->modelConfigService->expects(self::any())->method('getDefaultModel')->with('VECTORIZE', null)->willReturn(7);
        $this->modelConfigService->expects(self::any())->method('getProviderForModel')->with(7)->willReturn('ollama');
        $this->modelConfigService->expects(self::any())->method('getModelName')->with(7)->willReturn('nomic-embed-text');
        $this->modelConfigService->expects(self::any())->method('getVectorDimForModel')->with(7)->willReturn(768);

        // VECTORIZE choice must be stamped as the new sticky.
        $this->configRepository
            ->expects($this->atLeastOnce())
            ->method('setValue')
            ->with(0, 'MEMORIES', 'EMBEDDING_MODEL_ID', '7');

        $info = $this->resolver->resolve();

        $this->assertSame(7, $info['model_id']);
        $this->assertSame('nomic-embed-text', $info['model']);
        $this->assertSame(768, $info['vector_dim']);
    }

    public function testRememberModelOverwritesStickyPointer(): void
    {
        // Used by EmbeddingReindexService after a successful memories
        // re-index — the new model becomes authoritative.
        $this->configRepository
            ->expects($this->once())
            ->method('setValue')
            ->with(0, 'MEMORIES', 'EMBEDDING_MODEL_ID', '99');

        $this->resolver->rememberModel(99);
    }

    public function testResolvesAreCachedInProcess(): void
    {
        // Cheapest correctness signal: two consecutive resolves must
        // hit BCONFIG only once (the cache prevents N reads per request).
        $this->configRepository
            ->expects($this->once())
            ->method('getValue')
            ->willReturn('42');

        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-large');
        $this->modelConfigService->method('getVectorDimForModel')->willReturn(3072);
        $this->qdrantClient->method('getMemoriesCollectionInfo')->willReturn([
            'exists' => true,
            'vector_dim' => 3072,
            'points_count' => 1,
            'distance' => 'Cosine',
        ]);

        $first = $this->resolver->resolve();
        $second = $this->resolver->resolve();

        $this->assertSame($first, $second);
    }
}
