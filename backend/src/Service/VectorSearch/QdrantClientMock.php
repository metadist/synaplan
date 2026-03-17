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

    public function upsertMemory(string $pointId, array $vector, array $payload, ?string $namespace = null): void
    {
        $this->logger->info('QdrantClientMock: upsertMemory called', [
            'point_id' => $pointId,
            'vector_dim' => count($vector),
            'payload' => $payload,
            'namespace' => $namespace,
        ]);

        // TODO: Will be replaced with actual gRPC call to Qdrant
    }

    public function getMemory(string $pointId, ?string $namespace = null): ?array
    {
        $this->logger->info('QdrantClientMock: getMemory called', [
            'point_id' => $pointId,
            'namespace' => $namespace,
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
        ?string $namespace = null,
    ): array {
        $this->logger->info('QdrantClientMock: searchMemories called', [
            'user_id' => $userId,
            'category' => $category,
            'limit' => $limit,
            'min_score' => $minScore,
            'namespace' => $namespace,
        ]);

        // TODO: Will return actual search results from Qdrant
        return [];
    }

    public function scrollMemories(
        int $userId,
        ?string $category = null,
        int $limit = 1000,
        ?string $namespace = null,
    ): array {
        $this->logger->info('QdrantClientMock: scrollMemories called', [
            'user_id' => $userId,
            'category' => $category,
            'limit' => $limit,
            'namespace' => $namespace,
        ]);

        // TODO: Will return all memories from Qdrant
        return [];
    }

    public function deleteMemory(string $pointId, ?string $namespace = null): void
    {
        $this->logger->info('QdrantClientMock: deleteMemory called', [
            'point_id' => $pointId,
            'namespace' => $namespace,
        ]);

        // TODO: Will delete from Qdrant
    }

    public function healthCheck(): bool
    {
        $this->logger->debug('QdrantClientMock: healthCheck called');

        // TODO: Will ping Qdrant service
        return false; // Not available yet
    }

    public function getHealthDetails(): array
    {
        $this->logger->debug('QdrantClientMock: getHealthDetails called');

        return [
            'status' => 'mock',
            'service' => 'synaplan-qdrant-service (mock)',
            'version' => '0.0.0-mock',
            'uptime_seconds' => 0,
            'qdrant' => [
                'status' => 'mock',
                'collection_status' => 'Green',
                'points_count' => 0,
                'vectors_count' => 0,
            ],
            'metrics' => [
                'requests_total' => 0,
                'requests_failed' => 0,
                'requests_success' => 0,
                'success_rate_percent' => '100.00',
            ],
        ];
    }

    public function isAvailable(): bool
    {
        $this->logger->debug('QdrantClientMock: isAvailable called');

        // Mock is never available (development placeholder)
        return false;
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

    public function getServiceInfo(): array
    {
        $this->logger->debug('QdrantClientMock: getServiceInfo called');

        // Mock service info
        return [
            'service' => 'synaplan-qdrant-service',
            'version' => '0.0.0-mock',
            'rust_version' => 'mock',
            'status' => 'mock',
            'collection' => [
                'status' => 'mock',
                'points_count' => 0,
                'vectors_count' => 0,
                'indexed_vectors_count' => 0,
            ],
        ];
    }
}
