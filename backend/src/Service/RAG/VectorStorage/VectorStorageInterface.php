<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;

interface VectorStorageInterface
{
    /**
     * Store a single chunk with its vector embedding.
     *
     * @param VectorChunk $chunk The chunk data including vector
     *
     * @return string The stored chunk ID
     */
    public function storeChunk(VectorChunk $chunk): string;

    /**
     * Store multiple chunks in a single batch operation.
     *
     * @param VectorChunk[] $chunks Array of chunks to store
     *
     * @return int Number of chunks successfully stored
     */
    public function storeChunkBatch(array $chunks): int;

    /**
     * Delete all chunks for a specific file.
     *
     * @param int $userId User ID (required for isolation)
     * @param int $fileId File/Message ID
     *
     * @return int Number of chunks deleted
     */
    public function deleteByFile(int $userId, int $fileId): int;

    /**
     * Delete all chunks for a specific group key.
     *
     * @param int    $userId   User ID (required for isolation)
     * @param string $groupKey Group key (e.g., "WIDGET:xxx")
     *
     * @return int Number of chunks deleted
     */
    public function deleteByGroupKey(int $userId, string $groupKey): int;

    /**
     * Delete all chunks for a user (for account deletion).
     *
     * @param int $userId User ID
     *
     * @return int Number of chunks deleted
     */
    public function deleteAllForUser(int $userId): int;

    /**
     * Perform semantic search.
     *
     * @param SearchQuery $query Search parameters including vector
     *
     * @return SearchResult[] Array of matching results
     */
    public function search(SearchQuery $query): array;

    /**
     * Find chunks similar to a source chunk.
     *
     * @param int   $userId        User ID
     * @param int   $sourceChunkId ID of the source chunk
     * @param int   $limit         Max results
     * @param float $minScore      Minimum similarity score
     *
     * @return SearchResult[]
     */
    public function findSimilar(int $userId, int $sourceChunkId, int $limit = 10, float $minScore = 0.3): array;

    /**
     * Get storage statistics for a user.
     *
     * @param int $userId User ID
     */
    public function getStats(int $userId): StorageStats;

    /**
     * Get distinct group keys for a user.
     *
     * @param int $userId User ID
     *
     * @return string[] Array of group keys
     */
    public function getGroupKeys(int $userId): array;

    /**
     * Update group key for all chunks of a file.
     *
     * @param int    $userId      User ID
     * @param int    $fileId      File ID
     * @param string $newGroupKey New group key
     *
     * @return int Number of chunks updated
     */
    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int;

    /**
     * Get chunk info for a specific file (count + group key).
     *
     * @param int $userId User ID (required for isolation)
     * @param int $fileId File ID
     *
     * @return array{chunks: int, groupKey: string|null} Chunk count and group key
     */
    public function getFileChunkInfo(int $userId, int $fileId): array;

    /**
     * Get file IDs that have chunks in a specific group.
     *
     * @param int    $userId   User ID
     * @param string $groupKey Group key to filter by
     *
     * @return int[] Array of file IDs
     */
    public function getFileIdsByGroupKey(int $userId, string $groupKey): array;

    /**
     * Get all file IDs that have vectorized chunks.
     *
     * @param int $userId User ID
     *
     * @return array<int, array{chunks: int, groupKey: string|null}> Map of fileId => info
     */
    public function getFilesWithChunks(int $userId): array;

    /**
     * Check if the storage backend is available.
     *
     * @return bool True if backend is operational
     */
    public function isAvailable(): bool;

    /**
     * Get the provider name (for logging/debugging).
     *
     * @return string Provider identifier (e.g., 'mariadb', 'qdrant')
     */
    public function getProviderName(): string;
}
