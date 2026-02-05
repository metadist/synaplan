<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use App\Service\VectorSearch\QdrantClientHttp;
use Psr\Log\LoggerInterface;

final readonly class QdrantVectorStorage implements VectorStorageInterface
{
    public function __construct(
        private QdrantClientHttp $qdrantClient,
        private VectorStorageConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    private function getCollection(): string
    {
        return $this->config->getQdrantDocumentsCollection();
    }

    public function storeChunk(VectorChunk $chunk): string
    {
        $pointId = $chunk->getPointId();

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

        $this->qdrantClient->upsertDocument($pointId, $chunk->vector, $payload);

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
            'payload' => [
                'user_id' => $chunk->userId,
                'file_id' => $chunk->fileId,
                'group_key' => $chunk->groupKey,
                'file_type' => $chunk->fileType,
                'chunk_index' => $chunk->chunkIndex,
                'start_line' => $chunk->startLine,
                'end_line' => $chunk->endLine,
                'text' => $chunk->text,
                'created' => $chunk->getCreatedTimestamp(),
            ],
        ], $chunks);

        $result = $this->qdrantClient->batchUpsertDocuments($points);

        return $result['success_count'] ?? count($chunks);
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
        $results = $this->qdrantClient->searchDocuments(
            vector: $query->vector,
            userId: $query->userId,
            groupKey: $query->groupKey,
            limit: $query->limit,
            minScore: $query->minScore,
        );

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
            $results
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

    public function isAvailable(): bool
    {
        return $this->qdrantClient->isAvailable();
    }

    public function getProviderName(): string
    {
        return 'qdrant';
    }
}
