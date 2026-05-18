<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\RevectorizeRun;
use App\Message\ReVectorizeMessage;
use App\MessageHandler\ReVectorizeMessageHandler;
use App\Repository\RevectorizeRunRepository;
use App\Service\Embedding\EmbeddingReindexService;
use App\Service\Embedding\VectorizeBindingService;
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
 */
final class ReVectorizeMessageHandlerTest extends TestCase
{
    private RevectorizeRunRepository&MockObject $runRepository;
    private EmbeddingReindexService&MockObject $reindexService;
    private VectorizeBindingService&MockObject $bindingService;
    private EntityManagerInterface&MockObject $em;
    private ReVectorizeMessageHandler $handler;

    protected function setUp(): void
    {
        $this->runRepository = $this->createMock(RevectorizeRunRepository::class);
        $this->reindexService = $this->createMock(EmbeddingReindexService::class);
        $this->bindingService = $this->createMock(VectorizeBindingService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->handler = new ReVectorizeMessageHandler(
            $this->runRepository,
            $this->reindexService,
            $this->bindingService,
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
