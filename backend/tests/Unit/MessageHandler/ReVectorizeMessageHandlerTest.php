<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\RevectorizeRun;
use App\Message\ReVectorizeMessage;
use App\MessageHandler\ReVectorizeMessageHandler;
use App\Repository\RevectorizeRunRepository;
use App\Service\Embedding\EmbeddingReindexService;
use App\Service\Embedding\VectorizeBindingService;
use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ReVectorizeMessageHandler covering the post-#948 failure
 * semantics:
 *   - happy path → completed, no rollback
 *   - throw      → failed + binding rolled back
 *   - 0/N        → failed + binding rolled back (zero-success guard)
 *   - 9990/10    → completed, partial failures tolerated
 *   - non-vec scope (synapse) → rollback uses setSynapseVectorizeModel
 *   - fromModelId === 0 → skip rollback (no previous binding to restore).
 *
 * Plus #985 coverage:
 *   - memories-touching scopes recreate the Qdrant collection at the
 *     previous model's dim on failure (collection-level rollback).
 *   - the rollback is best-effort and never shadows the original error.
 */
final class ReVectorizeMessageHandlerTest extends TestCase
{
    private RevectorizeRunRepository&MockObject $runRepository;
    private EmbeddingReindexService&MockObject $reindexService;
    private VectorizeBindingService&MockObject $bindingService;
    private QdrantClientInterface&MockObject $qdrantClient;
    private ModelConfigService&MockObject $modelConfigService;
    private EntityManagerInterface&MockObject $em;
    private ReVectorizeMessageHandler $handler;

    protected function setUp(): void
    {
        $this->runRepository = $this->createMock(RevectorizeRunRepository::class);
        $this->reindexService = $this->createMock(EmbeddingReindexService::class);
        $this->bindingService = $this->createMock(VectorizeBindingService::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->handler = new ReVectorizeMessageHandler(
            $this->runRepository,
            $this->reindexService,
            $this->bindingService,
            $this->qdrantClient,
            $this->modelConfigService,
            $this->em,
            new NullLogger(),
        );
    }

    public function testReturnsEarlyWhenRunIsMissing(): void
    {
        $this->runRepository->method('find')->willReturn(null);
        $this->reindexService->expects($this->never())->method('execute');
        $this->bindingService->expects($this->never())->method('setVectorizeModel');

        $this->handler->__invoke(new ReVectorizeMessage(42));
    }

    public function testSkipsAlreadyHandledRun(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_ALL, RevectorizeRun::STATUS_RUNNING, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);
        $this->reindexService->expects($this->never())->method('execute');

        $this->handler->__invoke(new ReVectorizeMessage(1));

        $this->assertSame(RevectorizeRun::STATUS_RUNNING, $run->getStatus(), 'status must not be reset on a re-delivered message');
    }

    public function testHappyPathMarksCompletedWithoutRollback(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_ALL, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (RevectorizeRun $r): void {
                $r->incrementChunksProcessed(100);
            });

        $this->bindingService->expects($this->never())->method('setVectorizeModel');
        $this->bindingService->expects($this->never())->method('setSynapseVectorizeModel');

        $this->handler->__invoke(new ReVectorizeMessage(1));

        $this->assertSame(RevectorizeRun::STATUS_COMPLETED, $run->getStatus());
        $this->assertNotNull($run->getFinishedAt());
        $this->assertNull($run->getError());
    }

    public function testPartialFailureStillMarksCompleted(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_ALL, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService->method('execute')->willReturnCallback(function (RevectorizeRun $r): void {
            $r->incrementChunksProcessed(95);
            $r->incrementChunksFailed(5);
        });

        // Partial-success runs MUST NOT roll back — the user has 95% of
        // their corpus re-embedded with the new model and rolling back
        // would silently degrade the next 95 search queries.
        $this->bindingService->expects($this->never())->method('setVectorizeModel');

        $this->handler->__invoke(new ReVectorizeMessage(1));

        $this->assertSame(RevectorizeRun::STATUS_COMPLETED, $run->getStatus());
        $this->assertSame(95, $run->getChunksProcessed());
        $this->assertSame(5, $run->getChunksFailed());
    }

    public function testZeroSuccessMarksFailedAndRollsBackVectorizeBinding(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_ALL, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService->method('execute')->willReturnCallback(function (RevectorizeRun $r): void {
            $r->incrementChunksFailed(30); // exact scenario from issue #948: 0/30 with no API key
        });

        // The rollback MUST restore the previous model — without it,
        // every subsequent live write fails 400 (Qdrant dim mismatch).
        $this->bindingService
            ->expects($this->once())
            ->method('setVectorizeModel')
            ->with(10);

        $this->handler->__invoke(new ReVectorizeMessage(1));

        $this->assertSame(RevectorizeRun::STATUS_FAILED, $run->getStatus());
        $this->assertNotNull($run->getError());
        $this->assertStringContainsString('30 failures', (string) $run->getError());
    }

    public function testZeroSuccessOnSynapseScopeRollsBackSynapseBinding(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_SYNAPSE, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService->method('execute')->willReturnCallback(function (RevectorizeRun $r): void {
            $r->incrementChunksFailed(5);
        });

        $this->bindingService->expects($this->never())->method('setVectorizeModel');
        $this->bindingService
            ->expects($this->once())
            ->method('setSynapseVectorizeModel')
            ->with(10);

        $this->handler->__invoke(new ReVectorizeMessage(1));

        $this->assertSame(RevectorizeRun::STATUS_FAILED, $run->getStatus());
    }

    public function testRollbackSkippedWhenNoPreviousModelKnown(): void
    {
        // Fresh installs may switch with fromModelId=0 (no prior binding).
        // There's nothing to roll back to, so don't try.
        $run = $this->makeRun(RevectorizeRun::SCOPE_ALL, RevectorizeRun::STATUS_QUEUED, fromId: 0, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService->method('execute')->willReturnCallback(function (RevectorizeRun $r): void {
            $r->incrementChunksFailed(1);
        });

        $this->bindingService->expects($this->never())->method('setVectorizeModel');

        $this->handler->__invoke(new ReVectorizeMessage(1));

        $this->assertSame(RevectorizeRun::STATUS_FAILED, $run->getStatus());
    }

    public function testUncaughtExceptionMarksFailedRollsBackAndRethrows(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_ALL, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService->method('execute')->willThrowException(new \RuntimeException('provider blew up'));

        // Rollback must run BEFORE the exception escapes, otherwise a
        // crashed worker leaves BCONFIG pointed at a broken model.
        $this->bindingService
            ->expects($this->once())
            ->method('setVectorizeModel')
            ->with(10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider blew up');

        try {
            $this->handler->__invoke(new ReVectorizeMessage(1));
        } finally {
            $this->assertSame(RevectorizeRun::STATUS_FAILED, $run->getStatus());
            $this->assertSame('provider blew up', $run->getError());
        }
    }

    public function testRollbackErrorIsSwallowedAndDoesNotShadowOriginalFailure(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_ALL, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService->method('execute')->willReturnCallback(function (RevectorizeRun $r): void {
            $r->incrementChunksFailed(1);
        });

        // Simulate a DB blip during rollback. The handler must log the
        // critical issue but still complete (the run row is already
        // marked failed; throwing again would lose that update).
        $this->bindingService
            ->method('setVectorizeModel')
            ->willThrowException(new \RuntimeException('db down'));

        $this->handler->__invoke(new ReVectorizeMessage(1));

        $this->assertSame(RevectorizeRun::STATUS_FAILED, $run->getStatus());
    }

    /**
     * Regression for #985 — memory-scope failures used to leave the
     * Qdrant collection at the new (broken) dim while BCONFIG rolled
     * back to the previous model. Every subsequent live memory write
     * then threw a dim mismatch until an operator manually recreated
     * the collection. The handler now recreates it with the rollback
     * model's dim as part of the failure path.
     */
    public function testMemoryScopeFailureRecreatesCollectionAtPreviousDim(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_MEMORIES, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService->method('execute')->willThrowException(new \RuntimeException('probe dim mismatch'));

        $this->modelConfigService
            ->expects($this->once())
            ->method('getVectorDimForModel')
            ->with(10)
            ->willReturn(1536);

        $this->qdrantClient
            ->expects($this->once())
            ->method('getMemoriesCollectionInfo')
            ->willReturn(['exists' => true, 'vector_dim' => 3072, 'points_count' => 0, 'distance' => 'Cosine']);

        $this->qdrantClient
            ->expects($this->once())
            ->method('recreateMemoriesCollection')
            ->with(1536);

        $this->expectException(\RuntimeException::class);
        try {
            $this->handler->__invoke(new ReVectorizeMessage(1));
        } finally {
            $this->assertSame(RevectorizeRun::STATUS_FAILED, $run->getStatus());
        }
    }

    /**
     * If the collection's current dim already matches the rollback
     * model's dim, the failure happened BEFORE the drop (probe check
     * refused it). No-op rollback so we don't churn the collection
     * for nothing.
     */
    public function testMemoryScopeFailureSkipsRecreateWhenCollectionAlreadyMatches(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_MEMORIES, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);

        $this->reindexService->method('execute')->willThrowException(new \RuntimeException('probe refused'));

        $this->modelConfigService->method('getVectorDimForModel')->with(10)->willReturn(1536);
        $this->qdrantClient
            ->method('getMemoriesCollectionInfo')
            ->willReturn(['exists' => true, 'vector_dim' => 1536, 'points_count' => 12, 'distance' => 'Cosine']);

        $this->qdrantClient->expects($this->never())->method('recreateMemoriesCollection');

        $this->expectException(\RuntimeException::class);
        $this->handler->__invoke(new ReVectorizeMessage(1));
    }

    /**
     * Synapse runs use a different collection and recovery path — the
     * memories rollback must not fire on synapse failures or we'd
     * needlessly recreate an unrelated collection.
     */
    public function testSynapseScopeFailureDoesNotTouchMemoriesCollection(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_SYNAPSE, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);
        $this->reindexService->method('execute')->willThrowException(new \RuntimeException('boom'));

        $this->qdrantClient->expects($this->never())->method('getMemoriesCollectionInfo');
        $this->qdrantClient->expects($this->never())->method('recreateMemoriesCollection');

        $this->expectException(\RuntimeException::class);
        $this->handler->__invoke(new ReVectorizeMessage(1));
    }

    /**
     * If the collection rollback itself blows up (Qdrant outage during
     * the failure window) we must still finish marking the run failed
     * and rolling the BCONFIG binding back. Throwing here would hide
     * the original error and leave the run in RUNNING forever.
     */
    public function testMemoryCollectionRollbackErrorIsSwallowed(): void
    {
        $run = $this->makeRun(RevectorizeRun::SCOPE_MEMORIES, RevectorizeRun::STATUS_QUEUED, fromId: 10, toId: 20);
        $this->runRepository->method('find')->willReturn($run);
        $this->reindexService->method('execute')->willThrowException(new \RuntimeException('original failure'));

        $this->modelConfigService->method('getVectorDimForModel')->with(10)->willReturn(1536);
        $this->qdrantClient
            ->method('getMemoriesCollectionInfo')
            ->willReturn(['exists' => true, 'vector_dim' => 3072, 'points_count' => 0, 'distance' => 'Cosine']);
        $this->qdrantClient
            ->method('recreateMemoriesCollection')
            ->willThrowException(new \RuntimeException('qdrant down'));

        // Original exception must still escape — it's the actionable
        // failure for the operator. Rollback failure is only logged.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('original failure');

        try {
            $this->handler->__invoke(new ReVectorizeMessage(1));
        } finally {
            $this->assertSame(RevectorizeRun::STATUS_FAILED, $run->getStatus());
            $this->assertSame('original failure', $run->getError());
        }
    }

    private function makeRun(string $scope, string $status, int $fromId, int $toId): RevectorizeRun
    {
        return (new RevectorizeRun())
            ->setUserId(1)
            ->setScope($scope)
            ->setModelFromId($fromId)
            ->setModelToId($toId)
            ->setStatus($status)
            ->setSeverity('info')
            ->setChunksTotal(0);
    }
}
