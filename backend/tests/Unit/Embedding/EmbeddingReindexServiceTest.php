<?php

declare(strict_types=1);

namespace App\Tests\Unit\Embedding;

use App\AI\Service\AiFacade;
use App\Entity\RevectorizeRun;
use App\Entity\UserMemory;
use App\Repository\RevectorizeRunRepository;
use App\Repository\UserMemoryRepository;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingReindexService;
use App\Service\Memory\MemoryEmbeddingModelResolver;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #985 — switching the VECTORIZE model could destroy every user
 * memory because the reindex flow dropped the Qdrant collection BEFORE
 * confirming the new model actually produced the catalog-claimed
 * dimensions. These tests pin the new safety net: a probe-embed runs
 * first, dimension mismatches abort BEFORE any destructive operation,
 * and successful runs rebuild Qdrant exclusively from durable SQL rows.
 */
final class EmbeddingReindexServiceTest extends TestCase
{
    private QdrantClientInterface&MockObject $qdrantClient;
    private AiFacade&MockObject $aiFacade;
    private EmbeddingMetadataService&MockObject $metadata;
    private MemoryEmbeddingModelResolver&MockObject $memoryResolver;
    private RevectorizeRunRepository&MockObject $runRepository;
    private UserMemoryRepository&MockObject $memoryRepository;
    private Connection&MockObject $connection;
    private EmbeddingReindexService $service;

    protected function setUp(): void
    {
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->metadata = $this->createMock(EmbeddingMetadataService::class);
        $this->memoryResolver = $this->createMock(MemoryEmbeddingModelResolver::class);
        $this->runRepository = $this->createMock(RevectorizeRunRepository::class);
        $this->memoryRepository = $this->createMock(UserMemoryRepository::class);
        $this->connection = $this->createMock(Connection::class);

        $this->service = new EmbeddingReindexService(
            $this->qdrantClient,
            $this->aiFacade,
            $this->metadata,
            $this->memoryResolver,
            $this->runRepository,
            $this->memoryRepository,
            $this->connection,
            new NullLogger(),
        );
    }

    public function testMemoriesScopeAbortsBeforeRecreateOnProbeDimMismatch(): void
    {
        $this->metadata->method('getCurrentModel')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'model_id' => 22,
            'vector_dim' => 3072,
        ]);

        // The provider hands us a 1536-dim vector even though the
        // catalog says 3072 — exactly the scenario from #985 with the
        // old OpenAIProvider hardcoded `dimensions: 1536`. We must
        // refuse to drop the collection.
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1536, 0.01),
        ]);

        $this->qdrantClient->expects($this->never())->method('recreateMemoriesCollection');
        $this->memoryRepository->expects($this->never())->method('findActiveBatchAfterId');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('catalog metadata for model "text-embedding-3-large" claims 3072');

        $run = $this->makeRun(RevectorizeRun::SCOPE_MEMORIES, fromId: 10, toId: 22);
        $this->service->execute($run);
    }

    public function testMemoriesScopeAbortsBeforeRecreateWhenProbeEmbedFails(): void
    {
        $this->metadata->method('getCurrentModel')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'model_id' => 22,
            'vector_dim' => 3072,
        ]);

        // Provider blew up — e.g. missing API key after the BCONFIG
        // was updated. The exception must propagate so the handler
        // rolls BCONFIG back; if we proceeded, we'd drop the
        // collection only to fail the rebuild and lose every memory.
        $this->aiFacade->method('embed')->willThrowException(
            new \RuntimeException('OPENAI_API_KEY missing'),
        );

        $this->qdrantClient->expects($this->never())->method('recreateMemoriesCollection');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('memories probe-embed failed before collection recreate');

        $run = $this->makeRun(RevectorizeRun::SCOPE_MEMORIES, fromId: 10, toId: 22);
        $this->service->execute($run);
    }

    public function testMemoriesScopeProceedsWhenProbeMatchesCatalogDim(): void
    {
        $this->metadata->method('getCurrentModel')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'model_id' => 21,
            'vector_dim' => 1536,
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1536, 0.01),
        ]);

        $this->memoryRepository->method('findActiveNamespaces')->willReturn([]);
        $this->memoryRepository->method('findActiveBatchAfterId')->willReturn([]);

        $this->qdrantClient
            ->expects($this->once())
            ->method('recreateMemoriesCollection')
            ->with(1536, null);

        // After a successful memories re-index the sticky pointer must
        // advance to the new model — otherwise UserMemoryService would
        // keep embedding writes/reads against the OLD model and every
        // freshly migrated point would immediately look stale.
        $this->memoryResolver
            ->expects($this->once())
            ->method('rememberModel')
            ->with(21);

        $run = $this->makeRun(RevectorizeRun::SCOPE_MEMORIES, fromId: 10, toId: 21);
        $this->service->execute($run);
    }

    public function testMemoriesScopeDoesNotAdvanceStickyPointerOnAbort(): void
    {
        // Probe-dim mismatch aborts before any destructive work — the
        // sticky pointer must stay on the OLD model so subsequent
        // writes keep landing in the still-intact old collection.
        $this->metadata->method('getCurrentModel')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-large',
            'model_id' => 22,
            'vector_dim' => 3072,
        ]);
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1536, 0.01),
        ]);

        $this->memoryResolver->expects($this->never())->method('rememberModel');

        $this->expectException(\RuntimeException::class);

        $run = $this->makeRun(RevectorizeRun::SCOPE_MEMORIES, fromId: 10, toId: 22);
        $this->service->execute($run);
    }

    public function testMemoriesScopeRebuildsQdrantFromSqlRows(): void
    {
        $this->metadata->method('getCurrentModel')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'model_id' => 21,
            'vector_dim' => 3,
        ]);
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => [0.1, 0.2, 0.3],
        ]);
        $memory = new UserMemory(
            id: 456,
            userId: 12,
            category: 'preferences',
            key: 'ui_theme',
            value: 'dark',
            source: UserMemory::SOURCE_USER_CREATED,
        );
        $this->memoryRepository->method('findActiveNamespaces')->willReturn([null]);
        $this->memoryRepository->expects($this->exactly(2))
            ->method('findActiveBatchAfterId')
            ->willReturnOnConsecutiveCalls([$memory], []);
        $this->aiFacade->expects($this->once())
            ->method('embedBatch')
            ->with(['ui_theme: dark'], 0, 'openai', $this->anything())
            ->willReturn([
                'embeddings' => [[0.1, 0.2, 0.3]],
                'usage' => ['total_tokens' => 2],
            ]);
        $this->qdrantClient->expects($this->once())
            ->method('upsertMemory')
            ->with(
                'mem_12_456',
                [0.1, 0.2, 0.3],
                $this->callback(static fn (array $payload): bool => 'dark' === $payload['value']),
                null,
            );
        $this->qdrantClient->expects($this->never())->method('scrollAllMemoriesForReindex');
        $this->memoryResolver->expects($this->once())->method('rememberModel')->with(21);

        $run = $this->makeRun(RevectorizeRun::SCOPE_MEMORIES, fromId: 10, toId: 21);
        $this->service->execute($run);

        self::assertSame(1, $run->getChunksProcessed());
        self::assertSame(0, $run->getChunksFailed());
    }

    public function testScopeAllRebuildsMemoriesFromSql(): void
    {
        $this->metadata->method('getCurrentModel')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'model_id' => 21,
            'vector_dim' => 1536,
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1536, 0.01),
        ]);
        $this->memoryRepository->method('findActiveNamespaces')->willReturn([]);
        $this->memoryRepository->method('findActiveBatchAfterId')->willReturn([]);
        $this->qdrantClient->expects($this->once())
            ->method('recreateMemoriesCollection')
            ->with(1536, null);
        $this->memoryResolver->expects($this->once())->method('rememberModel')->with(21);

        // Documents are still invoked. Stub to no-op.
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $run = $this->makeRun(RevectorizeRun::SCOPE_ALL, fromId: 10, toId: 21);
        $this->service->execute($run);
    }

    private function makeRun(string $scope, int $fromId, int $toId): RevectorizeRun
    {
        return (new RevectorizeRun())
            ->setUserId(1)
            ->setScope($scope)
            ->setModelFromId($fromId)
            ->setModelToId($toId)
            ->setStatus(RevectorizeRun::STATUS_RUNNING)
            ->setSeverity('info')
            ->setChunksTotal(0);
    }
}
