<?php

declare(strict_types=1);

namespace App\Service\Embedding;

use App\AI\Service\AiFacade;
use App\Entity\RevectorizeRun;
use App\Repository\RevectorizeRunRepository;
use App\Service\Message\SynapseIndexer;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * EmbeddingReindexService — does the heavy lifting of re-vectorizing
 * stored documents and memories under a new VECTORIZE model.
 *
 * Why this lives in its own service (not inside the message handler):
 *   - Keeps the message handler thin and focused on lifecycle (status
 *     transitions + error capture).
 *   - Lets us trigger the same flow from a CLI command in the future
 *     (e.g. `app:embedding:reindex --scope=documents`).
 *   - Makes the per-scope batches independently unit-testable.
 *
 * Operates on the currently active VECTORIZE model — the controller is
 * responsible for switching the active model BEFORE dispatching the
 * job. That ordering means: even if the worker crashes, search results
 * stay consistent (active model always matches the most-recently
 * indexed vectors, modulo stale hits which the metadata service
 * already filters out).
 */
final readonly class EmbeddingReindexService
{
    private const DOCUMENTS_BATCH = 50;
    private const MEMORIES_BATCH = 25;

    /**
     * Target vector dimension for the documents collection. Mirrors
     * `App\Service\File\VectorizationService::VECTOR_DIMENSION` —
     * Qdrant collections are created with a fixed dimension at install
     * time and cannot accept points of any other size, so a re-index
     * that pulls embeddings from a model with a different native
     * width must slice/zero-pad to this value.
     */
    private const DOC_VECTOR_DIMENSION = 1024;

    public function __construct(
        private QdrantClientInterface $qdrantClient,
        private AiFacade $aiFacade,
        private SynapseIndexer $synapseIndexer,
        private EmbeddingMetadataService $embeddingMetadata,
        private RevectorizeRunRepository $runRepository,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run the full re-index pipeline for a single run. The caller
     * (typically ReVectorizeMessageHandler) wraps this in
     * try/catch+status updates.
     */
    public function execute(RevectorizeRun $run): void
    {
        $this->embeddingMetadata->invalidate();
        $modelInfo = $this->embeddingMetadata->getCurrentModel();

        $scope = $run->getScope();
        $this->logger->info('EmbeddingReindex: starting', [
            'run_id' => $run->getId(),
            'scope' => $scope,
            'model_id' => $modelInfo['model_id'],
            'provider' => $modelInfo['provider'],
            'model' => $modelInfo['model'],
        ]);

        if (RevectorizeRun::SCOPE_SYNAPSE === $scope || RevectorizeRun::SCOPE_ALL === $scope) {
            $this->reindexSynapse($run);
        }

        if (RevectorizeRun::SCOPE_DOCUMENTS === $scope || RevectorizeRun::SCOPE_ALL === $scope) {
            $this->reindexDocuments($run, $modelInfo);
        }

        if (RevectorizeRun::SCOPE_MEMORIES === $scope || RevectorizeRun::SCOPE_ALL === $scope) {
            $this->reindexMemories($run, $modelInfo);
        }
    }

    /**
     * Synapse re-index uses the dedicated SYNAPSE_VECTORIZE binding
     * (read via SynapseIndexer) — *not* the global VECTORIZE default
     * which only governs documents/memories. Without this distinction
     * the collection would be recreated with the wrong vector dim
     * whenever Routing and RAG are pinned to different models.
     */
    private function reindexSynapse(RevectorizeRun $run): void
    {
        $synapseInfo = $this->synapseIndexer->getEmbeddingModelInfo();
        $this->qdrantClient->recreateSynapseCollection($synapseInfo['vector_dim']);
        $result = $this->synapseIndexer->indexAllTopics(null, true);
        $run->incrementChunksProcessed($result['indexed']);
        $run->incrementChunksFailed($result['errors']);
        $this->runRepository->save($run);
    }

    /**
     * @param array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int} $modelInfo
     */
    private function reindexDocuments(RevectorizeRun $run, array $modelInfo): void
    {
        $offset = 0;
        $modelId = $modelInfo['model_id'];
        $modelName = $modelInfo['model'];
        $provider = $modelInfo['provider'];

        if (null === $modelId || null === $modelName || null === $provider) {
            $this->logger->warning('EmbeddingReindex: documents skipped — no model configured');

            return;
        }

        while (true) {
            // Derive a stable per-(BUID,BMID) chunk index in the same way
            // `VectorMigrationService` does (ORDER BY BSTART ASC). Without
            // this every BRAG row collapsed to point id `doc_{u}_{f}_0`,
            // which silently overwrites all but the last chunk of every
            // multi-chunk file (raised by Copilot review on PR #853). The
            // tiebreaker on BID makes the ordering deterministic for rows
            // that happen to share BSTART.
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT BID, BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BTEXT,
                        (ROW_NUMBER() OVER (PARTITION BY BUID, BMID ORDER BY BSTART ASC, BID ASC) - 1) AS chunk_idx
                    FROM BRAG
                    ORDER BY BID
                    LIMIT :limit OFFSET :offset
                    SQL,
                ['limit' => self::DOCUMENTS_BATCH, 'offset' => $offset],
                ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
            );

            if (empty($rows)) {
                break;
            }

            $texts = array_map(static fn (array $r): string => (string) $r['BTEXT'], $rows);
            try {
                $batch = $this->aiFacade->embedBatch($texts, 0, $provider, [
                    'model' => $modelName,
                    'provider' => $provider,
                ]);
                $embeddings = $batch['embeddings'];
                $tokens = $batch['usage']['total_tokens']
                    ?: (int) (array_sum(array_map('strlen', $texts)) / 4);
                $run->incrementTokensProcessed($tokens);
            } catch (\Throwable $e) {
                $this->logger->error('EmbeddingReindex: documents batch failed', [
                    'offset' => $offset,
                    'error' => $e->getMessage(),
                ]);
                $run->incrementChunksFailed(count($rows));
                $this->runRepository->save($run);
                $offset += self::DOCUMENTS_BATCH;
                continue;
            }

            foreach ($rows as $i => $row) {
                $vector = $embeddings[$i] ?? [];
                if (empty($vector)) {
                    $run->incrementChunksFailed();
                    continue;
                }

                // Mirror `VectorizationService::VECTOR_DIMENSION` handling:
                // the synapse_documents collection is created with a fixed
                // dimension at install time, so any new model whose native
                // output is wider/narrower must be sliced or zero-padded
                // before upsert — otherwise Qdrant rejects the point and
                // we end up with chunks-failed climbing instead of a clean
                // re-index (raised by Copilot review on PR #853).
                $vector = $this->normalizeVectorDimension(
                    array_map('floatval', $vector),
                    self::DOC_VECTOR_DIMENSION,
                );

                $chunkIndex = (int) $row['chunk_idx'];
                $pointId = sprintf('doc_%d_%d_%d', (int) $row['BUID'], (int) $row['BMID'], $chunkIndex);
                $this->qdrantClient->upsertDocument(
                    $pointId,
                    $vector,
                    [
                        'user_id' => (int) $row['BUID'],
                        'file_id' => (int) $row['BMID'],
                        'group_key' => (string) $row['BGROUPKEY'],
                        'file_type' => (int) $row['BTYPE'],
                        'chunk_index' => $chunkIndex,
                        'start_line' => (int) $row['BSTART'],
                        'end_line' => (int) $row['BEND'],
                        'text' => (string) $row['BTEXT'],
                        'created' => time(),
                        'embedding_model_id' => $modelId,
                        'embedding_provider' => $provider,
                        'embedding_model' => $modelName,
                        'vector_dim' => self::DOC_VECTOR_DIMENSION,
                        'indexed_at' => date(\DATE_ATOM),
                    ],
                );
                $run->incrementChunksProcessed();
            }

            $this->runRepository->save($run);
            $offset += self::DOCUMENTS_BATCH;
        }
    }

    /**
     * Slice or zero-pad an embedding to the target dimension.
     *
     * Kept intentionally small and identical in behaviour to
     * `VectorizationService::vectorize()` so re-indexed points end up
     * byte-for-byte compatible with newly vectorized ones.
     *
     * @param list<float> $vector
     *
     * @return list<float>
     */
    private function normalizeVectorDimension(array $vector, int $targetDim): array
    {
        $actual = count($vector);
        if ($actual === $targetDim) {
            return $vector;
        }

        $this->logger->warning('EmbeddingReindex: embedding dimension mismatch — coercing', [
            'expected' => $targetDim,
            'actual' => $actual,
        ]);

        if ($actual > $targetDim) {
            return array_slice($vector, 0, $targetDim);
        }

        return array_pad($vector, $targetDim, 0.0);
    }

    /**
     * @param array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int} $modelInfo
     */
    private function reindexMemories(RevectorizeRun $run, array $modelInfo): void
    {
        $modelId = $modelInfo['model_id'];
        $modelName = $modelInfo['model'];
        $provider = $modelInfo['provider'];

        if (null === $modelId || null === $modelName || null === $provider) {
            $this->logger->warning('EmbeddingReindex: memories skipped — no model configured');

            return;
        }

        try {
            $points = $this->qdrantClient->scrollMemories(0, null, 50000);
        } catch (\Throwable $e) {
            $this->logger->error('EmbeddingReindex: memories scroll failed', ['error' => $e->getMessage()]);

            return;
        }

        foreach (array_chunk($points, self::MEMORIES_BATCH) as $batchPoints) {
            $texts = [];
            $payloads = [];
            foreach ($batchPoints as $point) {
                $payload = $point['payload'] ?? [];
                $key = (string) ($payload['key'] ?? '');
                $value = (string) ($payload['value'] ?? '');
                if ('' === $key && '' === $value) {
                    continue;
                }
                $texts[] = "{$key}: {$value}";
                $payloads[] = $payload + ['_id' => $point['id'] ?? ''];
            }

            if (empty($texts)) {
                continue;
            }

            try {
                $batch = $this->aiFacade->embedBatch($texts, 0, $provider, [
                    'model' => $modelName,
                    'provider' => $provider,
                ]);
                $embeddings = $batch['embeddings'];
                $tokens = $batch['usage']['total_tokens']
                    ?: (int) (array_sum(array_map('strlen', $texts)) / 4);
                $run->incrementTokensProcessed($tokens);
            } catch (\Throwable $e) {
                $this->logger->error('EmbeddingReindex: memories batch failed', [
                    'count' => count($texts),
                    'error' => $e->getMessage(),
                ]);
                $run->incrementChunksFailed(count($texts));
                $this->runRepository->save($run);
                continue;
            }

            foreach ($payloads as $i => $payload) {
                $vector = $embeddings[$i] ?? [];
                if (empty($vector)) {
                    $run->incrementChunksFailed();
                    continue;
                }

                // Same dimension-coercion as the documents path —
                // synapse_memories also has a fixed collection dim.
                $vector = $this->normalizeVectorDimension(
                    array_map('floatval', $vector),
                    self::DOC_VECTOR_DIMENSION,
                );

                $pointId = (string) ($payload['_id'] ?? '');
                if ('' === $pointId) {
                    $userId = (int) ($payload['user_id'] ?? 0);
                    $messageId = (int) ($payload['message_id'] ?? 0);
                    $pointId = sprintf('mem_%d_%d', $userId, $messageId);
                }

                unset($payload['_id']);
                $payload['embedding_model_id'] = $modelId;
                $payload['embedding_provider'] = $provider;
                $payload['embedding_model'] = $modelName;
                $payload['vector_dim'] = self::DOC_VECTOR_DIMENSION;
                $payload['indexed_at'] = date(\DATE_ATOM);

                $this->qdrantClient->upsertMemory($pointId, $vector, $payload);
                $run->incrementChunksProcessed();
            }

            $this->runRepository->save($run);
        }
    }
}
