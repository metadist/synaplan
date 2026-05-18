<?php

declare(strict_types=1);

namespace App\Service\Embedding;

use App\Repository\ConfigRepository;
use App\Service\Message\SynapseIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * VectorizeBindingService — single seam for persisting the active
 * embedding-model bindings (`DEFAULTMODEL.VECTORIZE` and
 * `DEFAULTMODEL.SYNAPSE_VECTORIZE`).
 *
 * Extracted from `AdminEmbeddingController` so the SafeModelChange
 * rollback path (live in `ReVectorizeMessageHandler`) can reuse the
 * exact same persistence semantics when a re-vectorize run fails. Issue
 * #948 — without rollback the BCONFIG row stayed on the broken target
 * model after a failed switch and every subsequent live write silently
 * failed (Qdrant HTTP 400, memory lost).
 *
 * Always invalidates `EmbeddingMetadataService` so in-process callers
 * pick up the new (or restored) binding immediately.
 */
final readonly class VectorizeBindingService
{
    public function __construct(
        private ConfigRepository $configRepository,
        private EmbeddingMetadataService $embeddingMetadata,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function setVectorizeModel(int $modelId): void
    {
        $config = $this->configRepository->setValue(0, 'DEFAULTMODEL', 'VECTORIZE', (string) $modelId);
        $this->em->persist($config);
        $this->em->flush();
        $this->embeddingMetadata->invalidate();

        $this->logger->info('VectorizeBindingService: VECTORIZE binding updated', [
            'model_id' => $modelId,
        ]);
    }

    public function setSynapseVectorizeModel(int $modelId): void
    {
        $config = $this->configRepository->setValue(
            0,
            'DEFAULTMODEL',
            SynapseIndexer::SYNAPSE_CAPABILITY,
            (string) $modelId,
        );
        $this->em->persist($config);
        $this->em->flush();
        $this->embeddingMetadata->invalidate();

        $this->logger->info('VectorizeBindingService: SYNAPSE_VECTORIZE binding updated', [
            'model_id' => $modelId,
        ]);
    }
}
