<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\RevectorizeRun;
use App\Message\ReVectorizeMessage;
use App\Repository\RevectorizeRunRepository;
use App\Service\Embedding\EmbeddingReindexService;
use App\Service\Embedding\VectorizeBindingService;
use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
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
 *
 * Failure semantics (#948):
 *   - Uncaught exception   → status `failed`, BCONFIG rolled back.
 *   - Zero-success outcome → status `failed`, BCONFIG rolled back even
 *                            though no exception was thrown. The old
 *                            behaviour ("returned without throwing →
 *                            completed") happily reported green badges
 *                            for runs that did 0 work because the target
 *                            provider had no API key configured.
 *   - Partial success      → status `completed`, BCONFIG kept. Some
 *                            chunks failed but the rest were re-embedded
 *                            successfully against the new model.
 */
#[AsMessageHandler]
final readonly class ReVectorizeMessageHandler
{
    /**
     * Scopes whose BCONFIG binding should be rolled back when the run fails.
     */
    private const VECTORIZE_BOUND_SCOPES = [
        RevectorizeRun::SCOPE_DOCUMENTS,
        RevectorizeRun::SCOPE_MEMORIES,
        RevectorizeRun::SCOPE_ALL,
    ];

    public function __construct(
        private RevectorizeRunRepository $runRepository,
        private EmbeddingReindexService $reindexService,
        private VectorizeBindingService $bindingService,
        private QdrantClientInterface $qdrantClient,
        private ModelConfigService $modelConfigService,
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
        } catch (\Throwable $e) {
            $run->setStatus(RevectorizeRun::STATUS_FAILED);
            $run->setError($e->getMessage());
            $run->setFinishedAt(time());
            $this->em->flush();

            $this->rollbackBinding($run, $e->getMessage());
            $this->rollbackMemoriesCollection($run, $e->getMessage());

            $this->logger->error('ReVectorize: failed', [
                'run_id' => $run->getId(),
                'scope' => $run->getScope(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Zero-success guard (#948).
        //
        // The reindex service swallows per-batch and per-chunk errors so
        // a single bad chunk doesn't kill the whole run. That's the right
        // behaviour for "9990/10 successful" but it used to also mark
        // "0/10000 successful" as `completed` (green badge in UI). Treat
        // any run that processed nothing AND failed at least once as a
        // failed run so the admin gets accurate feedback AND the BCONFIG
        // binding gets rolled back.
        if (0 === $run->getChunksProcessed() && $run->getChunksFailed() > 0) {
            $message = sprintf(
                'Re-vectorize completed without indexing a single chunk (%d failures). Check provider credentials and re-run.',
                $run->getChunksFailed(),
            );

            $run->setStatus(RevectorizeRun::STATUS_FAILED);
            $run->setError($message);
            $run->setFinishedAt(time());
            $this->em->flush();

            $this->rollbackBinding($run, $message);
            $this->rollbackMemoriesCollection($run, $message);

            $this->logger->error('ReVectorize: all chunks failed — rolled back', [
                'run_id' => $run->getId(),
                'scope' => $run->getScope(),
                'chunks_failed' => $run->getChunksFailed(),
            ]);

            return;
        }

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
    }

    /**
     * Restore the previous BCONFIG binding for runs that wrote to it.
     *
     * Without this, every subsequent live-path embedding call uses the
     * broken target model and silently fails (Qdrant 400, memory lost).
     * Best-effort: any exception during rollback is logged but doesn't
     * shadow the original failure.
     */
    private function rollbackBinding(RevectorizeRun $run, string $reason): void
    {
        $fromModelId = $run->getModelFromId();
        if ($fromModelId <= 0) {
            $this->logger->info('ReVectorize: no previous model recorded — rollback skipped', [
                'run_id' => $run->getId(),
            ]);

            return;
        }

        $scope = $run->getScope();

        try {
            if (in_array($scope, self::VECTORIZE_BOUND_SCOPES, true)) {
                $this->bindingService->setVectorizeModel($fromModelId);
            } else {
                return;
            }

            $this->logger->warning('ReVectorize: rolled back BCONFIG binding after failure', [
                'run_id' => $run->getId(),
                'scope' => $scope,
                'restored_model_id' => $fromModelId,
                'reason' => $reason,
            ]);
        } catch (\Throwable $rollbackError) {
            $this->logger->critical('ReVectorize: rollback FAILED — operator must restore BCONFIG manually', [
                'run_id' => $run->getId(),
                'scope' => $scope,
                'target_model_id' => $fromModelId,
                'reason' => $reason,
                'rollback_error' => $rollbackError->getMessage(),
            ]);
        }
    }

    /**
     * Restore the memories collection to the previous model's vector
     * dimension when a memories-touching run fails (#985).
     *
     * Why this exists: `EmbeddingReindexService::reindexMemories()`
     * drops the existing collection and recreates it at the new model's
     * dimension before upserting. If a later step throws — provider
     * outage, dim mismatch slipping past the probe check, Qdrant
     * connectivity blip — the BCONFIG rollback restores the old model,
     * but the Qdrant collection stays at the broken dim, and every
     * subsequent live-path write fails with "dimension mismatch:
     * collection expects X but model produced Y". Best-effort restore:
     * we recreate the collection with the rollback model's dim so the
     * memory service is usable again immediately after a failure.
     *
     * Skipped for SCOPE_DOCUMENTS (memories untouched).
     */
    private function rollbackMemoriesCollection(RevectorizeRun $run, string $reason): void
    {
        $scope = $run->getScope();
        if (!in_array($scope, [RevectorizeRun::SCOPE_MEMORIES, RevectorizeRun::SCOPE_ALL], true)) {
            return;
        }

        $fromModelId = $run->getModelFromId();
        if ($fromModelId <= 0) {
            $this->logger->info('ReVectorize: no previous model — memories collection rollback skipped', [
                'run_id' => $run->getId(),
            ]);

            return;
        }

        try {
            $fromDim = $this->modelConfigService->getVectorDimForModel($fromModelId);
            if (null === $fromDim || $fromDim <= 0) {
                $this->logger->warning('ReVectorize: cannot rollback memories collection — no dimension for rollback model', [
                    'run_id' => $run->getId(),
                    'model_id' => $fromModelId,
                ]);

                return;
            }

            $info = $this->qdrantClient->getMemoriesCollectionInfo();
            if ($info['exists'] && $info['vector_dim'] === $fromDim) {
                // Collection already matches the rollback dim. This is
                // the happy "failure happened before the drop" branch —
                // the probe check refused to recreate, so the old
                // collection is untouched. Nothing to do.
                return;
            }

            $this->qdrantClient->recreateMemoriesCollection($fromDim);

            $this->logger->warning('ReVectorize: rolled back memories collection after failure', [
                'run_id' => $run->getId(),
                'scope' => $scope,
                'restored_dim' => $fromDim,
                'reason' => $reason,
            ]);
        } catch (\Throwable $rollbackError) {
            $this->logger->critical('ReVectorize: memories collection rollback FAILED — operator must recreate manually', [
                'run_id' => $run->getId(),
                'scope' => $scope,
                'target_model_id' => $fromModelId,
                'reason' => $reason,
                'rollback_error' => $rollbackError->getMessage(),
            ]);
        }
    }
}
