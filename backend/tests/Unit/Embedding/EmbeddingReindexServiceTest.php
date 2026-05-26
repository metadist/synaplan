<?php

declare(strict_types=1);

namespace App\Tests\Unit\Embedding;

use App\AI\Service\AiFacade;
use App\Entity\RevectorizeRun;
use App\Repository\RevectorizeRunRepository;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingReindexService;
use App\Service\Memory\MemoryEmbeddingModelResolver;
use App\Service\Message\SynapseIndexer;
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
 * and `SCOPE_ALL` no longer routes memories through the reindex at all
 * while the temporary disable from the team consensus is in place.
 */
final class EmbeddingReindexServiceTest extends TestCase
{
    private QdrantClientInterface&MockObject $qdrantClient;
    private AiFacade&MockObject $aiFacade;
    private SynapseIndexer&MockObject $synapseIndexer;
    private EmbeddingMetadataService&MockObject $metadata;
    private MemoryEmbeddingModelResolver&MockObject $memoryResolver;
    private RevectorizeRunRepository&MockObject $runRepository;
    private Connection&MockObject $connection;
    private EmbeddingReindexService $service;

    protected function setUp(): void
    {
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->synapseIndexer = $this->createMock(SynapseIndexer::class);
        $this->metadata = $this->createMock(EmbeddingMetadataService::class);
        $this->memoryResolver = $this->createMock(MemoryEmbeddingModelResolver::class);
        $this->runRepository = $this->createMock(RevectorizeRunRepository::class);
        $this->connection = $this->createMock(Connection::class);

        $this->service = new EmbeddingReindexService(
            $this->qdrantClient,
            $this->aiFacade,
            $this->synapseIndexer,
            $this->metadata,
            $this->memoryResolver,
            $this->runRepository,
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
        $this->qdrantClient->expects($this->never())->method('scrollAllMemoriesForReindex');

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

        $this->qdrantClient
            ->expects($this->once())
            ->method('scrollAllMemoriesForReindex')
            ->willReturn([]);

        $this->qdrantClient
            ->expects($this->once())
            ->method('recreateMemoriesCollection')
            ->with(1536);

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

    public function testScopeAllSkipsMemoriesWhileTemporaryDisableIsActive(): void
    {
        $this->metadata->method('getCurrentModel')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'model_id' => 21,
            'vector_dim' => 1536,
        ]);

        // SCOPE_ALL must NOT trigger any memories work — the
        // controller already refuses scope=memories explicitly, and
        // we don't want the "switch everything" UX to re-introduce
        // the data-loss path through the back door.
        $this->aiFacade->expects($this->never())->method('embed');
        $this->qdrantClient->expects($this->never())->method('scrollAllMemoriesForReindex');
        $this->qdrantClient->expects($this->never())->method('recreateMemoriesCollection');

        // Synapse + documents are still invoked. Stub them to no-op.
        $this->synapseIndexer->method('getEmbeddingModelInfo')->willReturn([
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'model_id' => 21,
            'vector_dim' => 1536,
        ]);
        $this->synapseIndexer->method('indexAllTopics')->willReturn(['indexed' => 0, 'errors' => 0]);
        $this->qdrantClient->method('recreateSynapseCollection');
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
