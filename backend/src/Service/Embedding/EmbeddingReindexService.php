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
     * Synapse re-index is a single SynapseIndexer call — it already
     * handles the per-topic dimension/model bookkeeping.
     */
    private function reindexSynapse(RevectorizeRun $run): void
    {
        $modelInfo = $this->embeddingMetadata->getCurrentModel();
        $this->qdrantClient->recreateSynapseCollection($modelInfo['vector_dim']);
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
            $rows = $this->connection->fetchAllAssociative(
                'SELECT BID, BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BTEXT FROM BRAG ORDER BY BID LIMIT :limit OFFSET :offset',
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

                $pointId = sprintf('doc_%d_%d_0', (int) $row['BUID'], (int) $row['BMID']);
                $this->qdrantClient->upsertDocument(
                    $pointId,
                    array_map('floatval', $vector),
                    [
                        'user_id' => (int) $row['BUID'],
                        'file_id' => (int) $row['BMID'],
                        'group_key' => (string) $row['BGROUPKEY'],
                        'file_type' => (int) $row['BTYPE'],
                        'chunk_index' => 0,
                        'start_line' => (int) $row['BSTART'],
                        'end_line' => (int) $row['BEND'],
                        'text' => (string) $row['BTEXT'],
                        'created' => time(),
                        'embedding_model_id' => $modelId,
                        'embedding_provider' => $provider,
                        'embedding_model' => $modelName,
                        'vector_dim' => count($vector),
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
                $payload['vector_dim'] = count($vector);
                $payload['indexed_at'] = date(\DATE_ATOM);

                $this->qdrantClient->upsertMemory($pointId, array_map('floatval', $vector), $payload);
                $run->incrementChunksProcessed();
            }

            $this->runRepository->save($run);
        }
    }
}
