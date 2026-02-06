<?php

namespace App\Service\RAG;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class VectorSearchService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private VectorStorageFacade $vectorStorage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Semantic search using vector embeddings.
     *
     * @param string      $query    Search query
     * @param int         $userId   User ID for filtering
     * @param string|null $groupKey Optional group filter
     * @param int         $limit    Number of results (default: 10)
     * @param float       $minScore Minimum similarity score (0-1, default: 0.3)
     *
     * @return array Top-K similar documents
     */
    public function semanticSearch(
        string $query,
        int $userId,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3,
    ): array {
        // 1. Get embedding model from DB
        $embeddingModelId = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);

        if (!$embeddingModelId) {
            $this->logger->error('VectorSearchService: No embedding model configured');

            return [];
        }

        // Get model details (name, provider)
        $model = $this->em->getRepository('App\Entity\Model')->find($embeddingModelId);
        if (!$model) {
            $this->logger->error('VectorSearchService: Model not found', ['model_id' => $embeddingModelId]);

            return [];
        }

        $modelName = $model->getProviderId(); // BPROVID contains the actual model name (e.g., 'bge-m3')
        $provider = strtolower($model->getService()); // BSERVICE contains provider name, normalize to lowercase (e.g., 'ollama')

        $this->logger->info('VectorSearchService: Starting semantic search', [
            'user_id' => $userId,
            'query_length' => strlen($query),
            'model_id' => $embeddingModelId,
            'model_name' => $modelName,
            'provider' => $provider,
            'storage_provider' => $this->vectorStorage->getProviderName(),
        ]);

        // 2. Embed the query with model details
        $queryEmbedding = $this->aiFacade->embed($query, $userId, [
            'model' => $modelName,
            'provider' => $provider,
        ]);

        if (empty($queryEmbedding)) {
            $this->logger->error('VectorSearchService: Failed to embed query');

            return [];
        }

        // 3. Search via Facade
        $searchQuery = new SearchQuery(
            userId: $userId,
            vector: array_map('floatval', $queryEmbedding),
            groupKey: $groupKey,
            limit: $limit,
            minScore: $minScore,
        );

        $results = $this->vectorStorage->search($searchQuery);

        // 4. Map DTOs to arrays for backward compatibility
        return array_map(function ($result) {
            return [
                'chunk_id' => $result->chunkId,
                'file_id' => $result->fileId, // Mapped from BMID/file_id
                'message_id' => $result->fileId, // Legacy key
                'chunk_text' => $result->text,
                'start_line' => $result->startLine,
                'end_line' => $result->endLine,
                'group_key' => $result->groupKey,
                'distance' => $result->score, // Legacy: 'distance' key contained similarity score (1.0 = identical)
                'score' => $result->score, // Add score explicitly
                'file_name' => $result->fileName,
                'mime_type' => $result->mimeType,
                // Add other fields if needed by consumers
            ];
        }, $results);
    }

    /**
     * Get user RAG statistics.
     */
    public function getUserStats(int $userId): array
    {
        try {
            $stats = $this->vectorStorage->getStats($userId);

            return [
                'total_documents' => $stats->totalFiles,
                'total_chunks' => $stats->totalChunks,
                'total_groups' => $stats->totalGroups,
                'avg_chunk_size' => 0, // Not supported by facade yet
                'chunks_by_group' => $stats->chunksByGroup,
            ];
        } catch (\Exception $e) {
            $this->logger->error('VectorSearchService: getUserStats failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            return [
                'total_documents' => 0,
                'total_chunks' => 0,
                'total_groups' => 0,
                'avg_chunk_size' => 0,
            ];
        }
    }

    /**
     * Find similar documents based on a source message ID.
     *
     * @param int $sourceMessageId Source message to find similar documents for
     * @param int $userId          User ID for filtering
     * @param int $limit           Number of results
     *
     * @return array Similar documents
     */
    public function findSimilar(
        int $sourceMessageId,
        int $userId,
        int $limit = 10,
        float $minScore = 0.3, // Added minScore parameter to match interface
    ): array {
        try {
            $results = $this->vectorStorage->findSimilar($userId, $sourceMessageId, $limit, $minScore);

            return array_map(function ($result) {
                return [
                    'chunk_id' => $result->chunkId,
                    'message_id' => $result->fileId,
                    'chunk_text' => $result->text,
                    'distance' => $result->score, // Legacy: 'distance' key contained similarity score
                    'score' => $result->score,
                ];
            }, $results);
        } catch (\Throwable $e) {
            $this->logger->error('VectorSearchService: Find similar failed', [
                'error' => $e->getMessage(),
                'source_mid' => $sourceMessageId,
                'user_id' => $userId,
            ]);

            return [];
        }
    }

    /**
     * Get distinct group keys for a user.
     */
    public function getGroupKeys(int $userId): array
    {
        return $this->vectorStorage->getGroupKeys($userId);
    }

    public function getProviderName(): string
    {
        return $this->vectorStorage->getProviderName();
    }
}
