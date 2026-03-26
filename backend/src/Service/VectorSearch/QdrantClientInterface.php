<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

/**
 * Interface for Qdrant Vector Database Client.
 *
 * Abstracts Qdrant operations for both user memories and RAG document vectors.
 * Implementations talk directly to the Qdrant REST API.
 */
interface QdrantClientInterface
{
    // --- Memory Operations ---

    /**
     * Add or update a memory point in Qdrant collection.
     *
     * @param string      $pointId   Point ID (e.g., "mem_{userId}_{memoryId}")
     * @param float[]     $vector    Embedding vector (1024 floats)
     * @param array       $payload   Metadata (user_id, category, key, value, created)
     * @param string|null $namespace Optional namespace for collection separation
     */
    public function upsertMemory(string $pointId, array $vector, array $payload, ?string $namespace = null): void;

    /**
     * Get a specific memory by point ID.
     *
     * @return array|null Payload data or null if not found
     */
    public function getMemory(string $pointId, ?string $namespace = null): ?array;

    /**
     * Search for similar memories using vector similarity.
     *
     * @param float[]     $queryVector Query embedding vector
     * @param int         $userId      User ID (for filtering)
     * @param string|null $category    Optional category filter
     * @param int         $limit       Max results (default: 5)
     * @param float       $minScore    Minimum similarity score (default: 0.7)
     *
     * @return array Array of results: [['id' => string, 'score' => float, 'payload' => array], ...]
     */
    public function searchMemories(
        array $queryVector,
        int $userId,
        ?string $category = null,
        int $limit = 5,
        float $minScore = 0.7,
        ?string $namespace = null,
    ): array;

    /**
     * List (scroll) all memories for a user without vector search.
     *
     * @return array Array of memories: [['id' => string, 'payload' => array], ...]
     */
    public function scrollMemories(
        int $userId,
        ?string $category = null,
        int $limit = 1000,
        ?string $namespace = null,
    ): array;

    /**
     * Delete a memory point from Qdrant collection.
     */
    public function deleteMemory(string $pointId, ?string $namespace = null): void;

    /**
     * Delete all memory points for a user.
     *
     * @return int Number of deleted points
     */
    public function deleteAllMemoriesForUser(int $userId): int;

    // --- Document Operations ---

    /**
     * Add or update a document chunk point in Qdrant.
     *
     * @param string  $pointId Point ID
     * @param float[] $vector  Embedding vector (1024 floats)
     * @param array   $payload Metadata (user_id, file_id, group_key, text, etc.)
     */
    public function upsertDocument(string $pointId, array $vector, array $payload): void;

    /**
     * Batch upsert document chunks.
     *
     * @param array $documents Array of ['point_id' => string, 'vector' => float[], 'payload' => array]
     *
     * @return array{success_count?: int, failed_count?: int, errors?: array}
     */
    public function batchUpsertDocuments(array $documents): array;

    /**
     * Search for similar document chunks using vector similarity.
     *
     * @param float[]     $vector   Query embedding vector
     * @param int         $userId   User ID (for filtering)
     * @param string|null $groupKey Optional group key filter
     * @param int         $limit    Max results
     * @param float       $minScore Minimum similarity score
     *
     * @return array Array of results: [['id' => string, 'score' => float, 'payload' => array], ...]
     */
    public function searchDocuments(
        array $vector,
        int $userId,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3,
    ): array;

    /**
     * Get a specific document chunk by point ID.
     *
     * @return array|null Document data including vector, or null if not found
     */
    public function getDocument(string $pointId): ?array;

    /**
     * Delete a document chunk by point ID.
     */
    public function deleteDocument(string $pointId): void;

    /**
     * Delete all document chunks for a specific file.
     *
     * @return int Number of deleted chunks
     */
    public function deleteDocumentsByFile(int $userId, int $fileId): int;

    /**
     * Delete all document chunks for a specific group key.
     *
     * @return int Number of deleted chunks
     */
    public function deleteDocumentsByGroupKey(int $userId, string $groupKey): int;

    /**
     * Delete all document chunks for a user.
     *
     * @return int Number of deleted chunks
     */
    public function deleteAllDocumentsForUser(int $userId): int;

    /**
     * Update group key for all chunks of a specific file.
     *
     * @return int Number of updated chunks
     */
    public function updateDocumentGroupKey(int $userId, int $fileId, string $newGroupKey): int;

    /**
     * Get document statistics for a user.
     *
     * @return array{total_chunks?: int, total_files?: int, total_groups?: int, chunks_by_file?: array, chunks_by_group?: array}
     */
    public function getDocumentStats(int $userId): array;

    /**
     * Get distinct group keys for a user's documents.
     *
     * @return string[]
     */
    public function getDocumentGroupKeys(int $userId): array;

    /**
     * Get chunk info for a specific file.
     *
     * @return array{chunks: int, groupKey: string|null}
     */
    public function getDocumentFileInfo(int $userId, int $fileId): array;

    /**
     * Get file IDs that have chunks in a specific group key.
     *
     * @return int[]
     */
    public function getFileIdsByGroupKey(int $userId, string $groupKey): array;

    /**
     * Get files with chunk counts for a specific group key.
     *
     * @return array<int, array{chunks: int, groupKey: string}>
     */
    public function getFilesWithChunksByGroupKey(int $userId, string $groupKey): array;

    /**
     * Get all files with chunks for a user.
     *
     * @return array<int, array{chunks: int, groupKey: string|null}>
     */
    public function getFilesWithChunks(int $userId): array;

    // --- Health & Info ---

    /**
     * Check if Qdrant is reachable.
     */
    public function healthCheck(): bool;

    /**
     * Get detailed health information.
     *
     * @return array{status: string, service?: string, version?: string, qdrant?: array, message?: string}
     */
    public function getHealthDetails(): array;

    /**
     * Check if Qdrant is configured and available.
     */
    public function isAvailable(): bool;

    /**
     * Get collection info (point count, status, etc.).
     */
    public function getCollectionInfo(): array;

    /**
     * Get service info (version, stats).
     */
    public function getServiceInfo(): array;
}
