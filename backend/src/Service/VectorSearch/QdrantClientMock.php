<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;

/**
 * Mock implementation of QdrantClientInterface for development/testing without Qdrant.
 */
final readonly class QdrantClientMock implements QdrantClientInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    // --- Memory Operations ---

    public function upsertMemory(string $pointId, array $vector, array $payload, ?string $namespace = null): void
    {
        $this->logger->info('QdrantClientMock: upsertMemory', ['point_id' => $pointId, 'namespace' => $namespace]);
    }

    public function getMemory(string $pointId, ?string $namespace = null): ?array
    {
        $this->logger->info('QdrantClientMock: getMemory', ['point_id' => $pointId]);

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
        $this->logger->info('QdrantClientMock: searchMemories', ['user_id' => $userId]);

        return [];
    }

    public function scrollMemories(
        int $userId,
        ?string $category = null,
        int $limit = 1000,
        ?string $namespace = null,
    ): array {
        $this->logger->info('QdrantClientMock: scrollMemories', ['user_id' => $userId]);

        return [];
    }

    public function deleteMemory(string $pointId, ?string $namespace = null): void
    {
        $this->logger->info('QdrantClientMock: deleteMemory', ['point_id' => $pointId]);
    }

    public function deleteAllMemoriesForUser(int $userId): int
    {
        $this->logger->info('QdrantClientMock: deleteAllMemoriesForUser', ['user_id' => $userId]);

        return 0;
    }

    // --- Document Operations ---

    public function upsertDocument(string $pointId, array $vector, array $payload): void
    {
        $this->logger->info('QdrantClientMock: upsertDocument', ['point_id' => $pointId]);
    }

    public function batchUpsertDocuments(array $documents): array
    {
        $this->logger->info('QdrantClientMock: batchUpsertDocuments', ['count' => count($documents)]);

        return ['success_count' => 0, 'failed_count' => 0, 'errors' => []];
    }

    public function searchDocuments(
        array $vector,
        int $userId,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3,
    ): array {
        $this->logger->info('QdrantClientMock: searchDocuments', ['user_id' => $userId]);

        return [];
    }

    public function getDocument(string $pointId): ?array
    {
        $this->logger->info('QdrantClientMock: getDocument', ['point_id' => $pointId]);

        return null;
    }

    public function deleteDocument(string $pointId): void
    {
        $this->logger->info('QdrantClientMock: deleteDocument', ['point_id' => $pointId]);
    }

    public function deleteDocumentsByFile(int $userId, int $fileId): int
    {
        $this->logger->info('QdrantClientMock: deleteDocumentsByFile', ['user_id' => $userId, 'file_id' => $fileId]);

        return 0;
    }

    public function deleteDocumentsByGroupKey(int $userId, string $groupKey): int
    {
        $this->logger->info('QdrantClientMock: deleteDocumentsByGroupKey', ['user_id' => $userId]);

        return 0;
    }

    public function deleteAllDocumentsForUser(int $userId): int
    {
        $this->logger->info('QdrantClientMock: deleteAllDocumentsForUser', ['user_id' => $userId]);

        return 0;
    }

    public function updateDocumentGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        $this->logger->info('QdrantClientMock: updateDocumentGroupKey', ['user_id' => $userId]);

        return 0;
    }

    public function getDocumentStats(int $userId): array
    {
        return ['total_chunks' => 0, 'total_files' => 0, 'total_groups' => 0, 'chunks_by_file' => [], 'chunks_by_group' => []];
    }

    public function getDocumentGroupKeys(int $userId): array
    {
        return [];
    }

    public function getDocumentFileInfo(int $userId, int $fileId): array
    {
        return ['chunks' => 0, 'groupKey' => null];
    }

    public function getFileIdsByGroupKey(int $userId, string $groupKey): array
    {
        return [];
    }

    public function getFilesWithChunksByGroupKey(int $userId, string $groupKey): array
    {
        return [];
    }

    public function getFilesWithChunks(int $userId): array
    {
        return [];
    }

    // --- Synapse Routing Operations ---

    public function upsertSynapseTopic(string $pointId, array $vector, array $payload): void
    {
        $this->logger->info('QdrantClientMock: upsertSynapseTopic', ['point_id' => $pointId]);
    }

    public function searchSynapseTopics(
        array $queryVector,
        int $userId,
        int $limit = 5,
        float $minScore = 0.3,
    ): array {
        $this->logger->info('QdrantClientMock: searchSynapseTopics', ['user_id' => $userId]);

        return [];
    }

    public function deleteSynapseTopicsByOwner(int $ownerId): int
    {
        $this->logger->info('QdrantClientMock: deleteSynapseTopicsByOwner', ['owner_id' => $ownerId]);

        return 0;
    }

    public function getSynapseCollection(): string
    {
        return 'synapse_topics';
    }

    // --- Health & Info ---

    public function healthCheck(): bool
    {
        return false;
    }

    public function getHealthDetails(): array
    {
        return [
            'status' => 'mock',
            'service' => 'qdrant-direct (mock)',
            'version' => '0.0.0-mock',
            'qdrant' => ['status' => 'mock', 'collections_count' => 0],
        ];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getCollectionInfo(): array
    {
        return ['status' => 'mock', 'points_count' => 0, 'vectors_count' => 0];
    }

    public function getServiceInfo(): array
    {
        return ['service' => 'qdrant-direct', 'version' => '0.0.0-mock', 'status' => 'mock', 'collection' => []];
    }
}
