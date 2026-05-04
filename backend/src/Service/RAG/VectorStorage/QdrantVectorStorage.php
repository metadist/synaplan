<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

final readonly class QdrantVectorStorage implements VectorStorageInterface
{
    public function __construct(
        private QdrantClientInterface $qdrantClient,
        private EmbeddingMetadataService $embeddingMetadata,
        private LoggerInterface $logger,
    ) {
    }

    public function storeChunk(VectorChunk $chunk): string
    {
        $pointId = $chunk->getPointId();

        $this->qdrantClient->upsertDocument($pointId, $chunk->vector, $this->buildPayload($chunk));

        return $pointId;
    }

    public function storeChunkBatch(array $chunks): int
    {
        if (empty($chunks)) {
            return 0;
        }

        $points = array_map(fn (VectorChunk $chunk) => [
            'point_id' => $chunk->getPointId(),
            'vector' => $chunk->vector,
            'payload' => $this->buildPayload($chunk),
        ], $chunks);

        $result = $this->qdrantClient->batchUpsertDocuments($points);

        return $result['success_count'] ?? count($chunks);
    }

    /**
     * Build the Qdrant payload for a chunk, including embedding-stack
     * metadata (model id/provider/name + vector_dim + indexed_at) when the
     * caller supplied it. Legacy chunks without metadata still work — the
     * extra keys are simply omitted.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(VectorChunk $chunk): array
    {
        $payload = [
            'user_id' => $chunk->userId,
            'file_id' => $chunk->fileId,
            'group_key' => $chunk->groupKey,
            'file_type' => $chunk->fileType,
            'chunk_index' => $chunk->chunkIndex,
            'start_line' => $chunk->startLine,
            'end_line' => $chunk->endLine,
            'text' => $chunk->text,
            'created' => $chunk->getCreatedTimestamp(),
        ];

        if (null !== $chunk->embeddingModelId) {
            $payload['embedding_model_id'] = $chunk->embeddingModelId;
        }
        if (null !== $chunk->embeddingProvider) {
            $payload['embedding_provider'] = $chunk->embeddingProvider;
        }
        if (null !== $chunk->embeddingModelName) {
            $payload['embedding_model'] = $chunk->embeddingModelName;
        }
        if (null !== $chunk->vectorDim) {
            $payload['vector_dim'] = $chunk->vectorDim;
        }
        if (null !== $chunk->embeddingModelId || null !== $chunk->embeddingProvider) {
            $payload['indexed_at'] = date(\DATE_ATOM);
        }

        return $payload;
    }

    public function deleteByFile(int $userId, int $fileId): int
    {
        return $this->qdrantClient->deleteDocumentsByFile($userId, $fileId);
    }

    public function deleteByGroupKey(int $userId, string $groupKey): int
    {
        return $this->qdrantClient->deleteDocumentsByGroupKey($userId, $groupKey);
    }

    public function deleteAllForUser(int $userId): int
    {
        return $this->qdrantClient->deleteAllDocumentsForUser($userId);
    }

    public function search(SearchQuery $query): array
    {
        // Ask Qdrant for 2× the requested limit so the post-search stale
        // filter doesn't short-change the caller right after a model swap.
        // The stale-filter ratio is bounded by how much of the index was
        // re-vectorized; in steady state, fresh ≫ stale and overhead is
        // negligible.
        $rawHits = $this->qdrantClient->searchDocuments(
            vector: $query->vector,
            userId: $query->userId,
            groupKey: $query->groupKey,
            limit: $query->limit * 2,
            minScore: $query->minScore,
        );

        $filtered = $this->embeddingMetadata->filterStaleHits($rawHits);
        if ($filtered['stale_count'] > 0) {
            $this->logger->info('QdrantVectorStorage: Filtered stale RAG hits', [
                'user_id' => $query->userId,
                'stale_count' => $filtered['stale_count'],
                'fresh_count' => count($filtered['fresh']),
                'current_model_id' => $this->embeddingMetadata->getCurrentModelId(),
            ]);
        }

        $hits = array_slice($filtered['fresh'], 0, $query->limit);

        return array_map(
            fn (array $result) => new SearchResult(
                chunkId: $result['id'],
                fileId: (int) $result['payload']['file_id'],
                groupKey: $result['payload']['group_key'],
                text: $result['payload']['text'],
                score: (float) $result['score'],
                startLine: (int) $result['payload']['start_line'],
                endLine: (int) $result['payload']['end_line'],
                fileName: $result['payload']['file_name'] ?? null,
                mimeType: $result['payload']['mime_type'] ?? null,
            ),
            $hits
        );
    }

    public function findSimilar(int $userId, int $sourceChunkId, int $limit = 10, float $minScore = 0.3): array
    {
        // Get source chunk first
        $source = $this->qdrantClient->getDocument((string) $sourceChunkId);
        if (!$source || empty($source['vector'])) {
            return [];
        }

        // Search with source vector
        $query = new SearchQuery(
            userId: $userId,
            vector: $source['vector'],
            groupKey: null,
            limit: $limit + 1,
            minScore: $minScore,
        );

        $results = $this->search($query);

        // Filter out source
        return array_filter(
            $results,
            fn (SearchResult $r) => (string) $r->chunkId !== (string) $sourceChunkId
        );
    }

    public function getStats(int $userId): StorageStats
    {
        $stats = $this->qdrantClient->getDocumentStats($userId);

        return new StorageStats(
            totalChunks: $stats['total_chunks'] ?? 0,
            totalFiles: $stats['total_files'] ?? 0,
            totalGroups: $stats['total_groups'] ?? 0,
            chunksByGroup: $stats['chunks_by_group'] ?? [],
        );
    }

    public function getGroupKeys(int $userId): array
    {
        return $this->qdrantClient->getDocumentGroupKeys($userId);
    }

    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        return $this->qdrantClient->updateDocumentGroupKey($userId, $fileId, $newGroupKey);
    }

    public function getFileChunkInfo(int $userId, int $fileId): array
    {
        $stats = $this->qdrantClient->getDocumentFileInfo($userId, $fileId);

        return [
            'chunks' => $stats['chunks'],
            'groupKey' => $stats['groupKey'],
        ];
    }

    public function getFileIdsByGroupKey(int $userId, string $groupKey): array
    {
        return $this->qdrantClient->getFileIdsByGroupKey($userId, $groupKey);
    }

    /**
     * Get files with chunk counts for a specific group key.
     * More efficient than getFilesWithChunks + filtering.
     *
     * @return array<int, array{chunks: int, groupKey: string}> Map of fileId => info
     */
    public function getFilesWithChunksByGroupKey(int $userId, string $groupKey): array
    {
        return $this->qdrantClient->getFilesWithChunksByGroupKey($userId, $groupKey);
    }

    public function getFilesWithChunks(int $userId): array
    {
        return $this->qdrantClient->getFilesWithChunks($userId);
    }

    public function isAvailable(): bool
    {
        return $this->qdrantClient->isAvailable();
    }

    public function getProviderName(): string
    {
        return 'qdrant';
    }
}
