<?php

namespace App\Service\RAG;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class VectorSearchService
{
    /**
     * Target dimension for query vectors handed to {@see VectorStorageFacade::search()}.
     *
     * Mirrors `App\Service\File\VectorizationService::VECTOR_DIMENSION` and
     * `App\Service\Embedding\EmbeddingReindexService::DOC_VECTOR_DIMENSION`.
     * The documents collection is created with this fixed dimension at
     * install time, so any provider whose native embedding width is wider
     * (e.g. OpenAI `text-embedding-3-small` at 1536 dims, `-large` at 3072)
     * or narrower must be coerced to this size on both ingest *and* query.
     *
     * Without this normalisation the query vector dimension does not match
     * the stored vector dimension, which is exactly what produced the
     * "RAG returns 0 results with OpenAI embeddings" symptom in #346 / #755:
     * MariaDB `VEC_DISTANCE_COSINE` returns NULL for mismatched widths and
     * Qdrant rejects the query outright. The previous query path was the
     * only one in the codebase that did *not* normalise.
     */
    private const QUERY_VECTOR_DIMENSION = 1024;

    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private VectorStorageFacade $vectorStorage,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Semantic search using a precomputed vector.
     *
     * Phase 1a: ChatHandler computes one embedding for the user query and
     * fans it out across memories + RAG + feedback searches. Skipping the
     * embedding round-trip here is the dominant TTFT win.
     *
     * @param int               $userId   User ID for filtering
     * @param array<int, float> $vector   Already-embedded query vector
     * @param string|null       $groupKey Optional group filter
     * @param int               $limit    Number of results (default: 10)
     * @param float             $minScore Minimum similarity score (0-1, default: 0.3)
     *
     * @return array Top-K similar documents
     */
    public function semanticSearchByVector(
        int $userId,
        array $vector,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3,
    ): array {
        if (empty($vector)) {
            return [];
        }

        try {
            $searchQuery = new SearchQuery(
                userId: $userId,
                vector: $this->normalizeQueryVector(array_map('floatval', $vector)),
                groupKey: $groupKey,
                limit: $limit,
                minScore: $minScore,
            );

            $results = $this->vectorStorage->search($searchQuery);

            return array_map(static function ($result): array {
                return [
                    'chunk_id' => $result->chunkId,
                    'file_id' => $result->fileId,
                    'message_id' => $result->fileId,
                    'chunk_text' => $result->text,
                    'start_line' => $result->startLine,
                    'end_line' => $result->endLine,
                    'group_key' => $result->groupKey,
                    'distance' => $result->score,
                    'score' => $result->score,
                    'file_name' => $result->fileName,
                    'mime_type' => $result->mimeType,
                ];
            }, $results);
        } catch (\Throwable $e) {
            $this->logger->warning('VectorSearchService::semanticSearchByVector failed', [
                'user_id' => $userId,
                'group_key' => $groupKey,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Semantic search using vector embeddings.
     *
     * Embeds the query string via the user's configured embedding model
     * and delegates to {@see semanticSearchByVector()}. Pair the latter
     * with {@see UserMemoryService::embedUserQuery()} when fanning the
     * same embedding out across multiple searches.
     *
     * @param string      $query    Search query (will be embedded)
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
        $embedResult = $this->aiFacade->embed($query, $userId, [
            'model' => $modelName,
            'provider' => $provider,
        ]);
        $queryEmbedding = $embedResult['embedding'];

        $user = $this->em->getRepository(User::class)->find($userId);
        if ($user) {
            $this->rateLimitService->recordUsage($user, 'EMBEDDINGS', [
                'usage' => $embedResult['usage'],
                'provider' => $provider,
                'model' => $modelName,
                'model_id' => $embeddingModelId,
                'input_text' => $query,
                'source' => 'RAG_SEARCH',
            ]);
        }

        if (empty($queryEmbedding)) {
            $this->logger->error('VectorSearchService: Failed to embed query');

            return [];
        }

        // 3. Search via Facade
        $searchQuery = new SearchQuery(
            userId: $userId,
            vector: $this->normalizeQueryVector(array_map('floatval', $queryEmbedding)),
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
     * Find similar documents based on a source chunk ID.
     *
     * @param int|string $sourceMessageId Source chunk to find similar documents for (int for MariaDB, string for Qdrant)
     * @param int        $userId          User ID for filtering
     * @param int        $limit           Number of results
     *
     * @return array Similar documents
     */
    public function findSimilar(
        int|string $sourceMessageId,
        int $userId,
        int $limit = 10,
        float $minScore = 0.3,
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

    /**
     * Coerce a query embedding to the documents collection's fixed width.
     *
     * Truncates wider vectors and zero-pads narrower ones, matching the same
     * stop-gap normalisation that `VectorizationService::vectorizeAndStore()`
     * applies on the ingest side. Both sides MUST agree on the width or the
     * vector store returns no matches at all (issues #346 and #755).
     *
     * Logs at DEBUG so production operators can opt in to seeing the
     * coercion (e.g. when investigating "why does my RAG suddenly retrieve
     * less specific chunks?") without paying the per-query log volume that
     * INFO would impose for OpenAI deployments — `text-embedding-3-small`
     * (1536) and `-large` (3072) trigger this path on every chat / RAG
     * request, including the hot `semanticSearchByVector()` fan-out from
     * `ChatHandler`. The already-existing per-chunk WARN in
     * `VectorizationService` still covers the ingest side at higher level.
     *
     * @param list<float> $vector
     *
     * @return list<float>
     */
    private function normalizeQueryVector(array $vector): array
    {
        $actual = count($vector);

        if (self::QUERY_VECTOR_DIMENSION === $actual) {
            return $vector;
        }

        $this->logger->debug('VectorSearchService: Coercing query embedding to collection dimension', [
            'expected' => self::QUERY_VECTOR_DIMENSION,
            'actual' => $actual,
            'storage_provider' => $this->vectorStorage->getProviderName(),
        ]);

        if ($actual > self::QUERY_VECTOR_DIMENSION) {
            return array_slice($vector, 0, self::QUERY_VECTOR_DIMENSION);
        }

        return array_pad($vector, self::QUERY_VECTOR_DIMENSION, 0.0);
    }
}
