<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\RevectorizeRun;
use App\Message\ReVectorizeMessage;
use App\Repository\RevectorizeRunRepository;
use App\Service\Embedding\EmbeddingReindexService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async handler for ReVectorizeMessage. Owns the run-row lifecycle —
 * status transitions, started/finished timestamps, error capture — and
 * delegates the actual re-embedding work to EmbeddingReindexService.
 *
 * Idempotent against double-deliveries: if a queued message is delivered
 * twice (e.g. after worker crash), only the first run will find the row
 * in QUEUED state and proceed; the second sees RUNNING/COMPLETED and
 * exits without doing anything.
 */
#[AsMessageHandler]
final readonly class ReVectorizeMessageHandler
{
    public function __construct(
        private RevectorizeRunRepository $runRepository,
        private EmbeddingReindexService $reindexService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReVectorizeMessage $message): void
    {
        $run = $this->runRepository->find($message->runId);
        if (null === $run) {
            $this->logger->warning('ReVectorize: run not found', ['run_id' => $message->runId]);

            return;
        }

        if (RevectorizeRun::STATUS_QUEUED !== $run->getStatus()) {
            $this->logger->info('ReVectorize: skipping already-handled run', [
                'run_id' => $run->getId(),
                'status' => $run->getStatus(),
            ]);

            return;
        }

        $run->setStatus(RevectorizeRun::STATUS_RUNNING);
        $run->setStartedAt(time());
        $this->em->flush();

        try {
            $this->reindexService->execute($run);

            $run->setStatus(RevectorizeRun::STATUS_COMPLETED);
            $run->setFinishedAt(time());
            $this->em->flush();

            $this->logger->info('ReVectorize: completed', [
                'run_id' => $run->getId(),
                'scope' => $run->getScope(),
                'chunks_processed' => $run->getChunksProcessed(),
                'chunks_failed' => $run->getChunksFailed(),
                'tokens_processed' => $run->getTokensProcessed(),
            ]);
        } catch (\Throwable $e) {
            $run->setStatus(RevectorizeRun::STATUS_FAILED);
            $run->setError($e->getMessage());
            $run->setFinishedAt(time());
            $this->em->flush();

            $this->logger->error('ReVectorize: failed', [
                'run_id' => $run->getId(),
                'scope' => $run->getScope(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
