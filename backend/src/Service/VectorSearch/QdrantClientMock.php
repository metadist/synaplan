<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;

/**
 * Mock implementation of Qdrant Client (until Qdrant is deployed).
 *
 * This allows development and testing without Qdrant running.
 * Returns empty results and logs operations.
 */
final readonly class QdrantClientMock implements QdrantClientInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function upsertMemory(string $pointId, array $vector, array $payload): void
    {
        $this->logger->info('QdrantClientMock: upsertMemory called', [
            'point_id' => $pointId,
            'vector_dim' => count($vector),
            'payload' => $payload,
        ]);

        // TODO: Will be replaced with actual gRPC call to Qdrant
    }

    public function getMemory(string $pointId): ?array
    {
        $this->logger->info('QdrantClientMock: getMemory called', [
            'point_id' => $pointId,
        ]);

        // TODO: Will fetch from Qdrant
        return null;
    }

    public function searchMemories(
        array $queryVector,
        int $userId,
        ?string $category = null,
        int $limit = 5,
        float $minScore = 0.7,
    ): array {
        $this->logger->info('QdrantClientMock: searchMemories called', [
            'user_id' => $userId,
            'category' => $category,
            'limit' => $limit,
            'min_score' => $minScore,
        ]);

        // TODO: Will return actual search results from Qdrant
        return [];
    }

    public function scrollMemories(
        int $userId,
        ?string $category = null,
        int $limit = 1000,
    ): array {
        $this->logger->info('QdrantClientMock: scrollMemories called', [
            'user_id' => $userId,
            'category' => $category,
            'limit' => $limit,
        ]);

        // TODO: Will return all memories from Qdrant
        return [];
    }

    public function deleteMemory(string $pointId): void
    {
        $this->logger->info('QdrantClientMock: deleteMemory called', [
            'point_id' => $pointId,
        ]);

        // TODO: Will delete from Qdrant
    }

    public function healthCheck(): bool
    {
        $this->logger->debug('QdrantClientMock: healthCheck called');

        // TODO: Will ping Qdrant service
        return false; // Not available yet
    }

    public function getCollectionInfo(): array
    {
        $this->logger->debug('QdrantClientMock: getCollectionInfo called');

        // TODO: Will return collection stats from Qdrant
        return [
            'status' => 'mock',
            'points_count' => 0,
            'vectors_count' => 0,
        ];
    }
}
