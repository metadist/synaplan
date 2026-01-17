<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

/**
 * Interface for Qdrant Vector Database Client.
 *
 * This interface abstracts the Qdrant gRPC/HTTP API for vector search operations.
 * Implementation will be added when Qdrant is deployed.
 */
interface QdrantClientInterface
{
    /**
     * Add or update a memory point in Qdrant collection.
     *
     * @param string $pointId Point ID (e.g., "mem_{userId}_{memoryId}")
     * @param array  $vector  Embedding vector (1024 floats)
     * @param array  $payload Metadata (user_id, category, key, value, created)
     */
    public function upsertMemory(string $pointId, array $vector, array $payload): void;

    /**
     * Get a specific memory by point ID.
     *
     * @param string $pointId Point ID
     *
     * @return array|null Payload data or null if not found
     */
    public function getMemory(string $pointId): ?array;

    /**
     * Search for similar memories using vector similarity.
     *
     * @param array       $queryVector Query embedding vector
     * @param int         $userId      User ID (for filtering)
     * @param string|null $category    Optional category filter
     * @param int         $limit       Max results (default: 5)
     * @param float       $minScore    Minimum similarity score (default: 0.7)
     *
     * @return array Array of results with structure: [['id' => string, 'score' => float, 'payload' => array], ...]
     */
    public function searchMemories(
        array $queryVector,
        int $userId,
        ?string $category = null,
        int $limit = 5,
        float $minScore = 0.7,
    ): array;

    /**
     * List (scroll) all memories for a user without vector search.
     * Useful for fetching all memories for display or counting.
     *
     * @param int         $userId   User ID (for filtering)
     * @param string|null $category Optional category filter
     * @param int         $limit    Max results (default: 1000)
     *
     * @return array Array of memories with structure: [['id' => string, 'payload' => array], ...]
     */
    public function scrollMemories(
        int $userId,
        ?string $category = null,
        int $limit = 1000,
    ): array;

    /**
     * Delete a memory point from Qdrant collection.
     *
     * @param string $pointId Point ID
     */
    public function deleteMemory(string $pointId): void;

    /**
     * Check if Qdrant service is available.
     */
    public function healthCheck(): bool;

    /**
     * Get collection info (point count, etc.).
     */
    public function getCollectionInfo(): array;
}
