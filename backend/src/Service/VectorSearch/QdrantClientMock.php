<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;

/**
 * Mock implementation of QdrantClientInterface for development/testing without Qdrant.
 */
final class QdrantClientMock implements QdrantClientInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
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

    /** @var array<string, array{vector: array<int, float>, payload: array}> */
    private array $synapsePoints = [];

    public function upsertSynapseTopic(string $pointId, array $vector, array $payload): void
    {
        $this->logger->info('QdrantClientMock: upsertSynapseTopic', ['point_id' => $pointId]);
        $payload['_point_id'] = $pointId;
        $this->synapsePoints[$pointId] = ['vector' => $vector, 'payload' => $payload];
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

    public function deleteSynapseTopic(string $pointId): void
    {
        $this->logger->info('QdrantClientMock: deleteSynapseTopic', ['point_id' => $pointId]);
        unset($this->synapsePoints[$pointId]);
    }

    public function deleteSynapseTopicsByOwner(int $ownerId): int
    {
        $this->logger->info('QdrantClientMock: deleteSynapseTopicsByOwner', ['owner_id' => $ownerId]);

        $deleted = 0;
        foreach ($this->synapsePoints as $id => $point) {
            if (($point['payload']['owner_id'] ?? null) === $ownerId) {
                unset($this->synapsePoints[$id]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    public function getSynapseTopic(string $pointId): ?array
    {
        if (!isset($this->synapsePoints[$pointId])) {
            return null;
        }

        return [
            'id' => $pointId,
            'payload' => $this->synapsePoints[$pointId]['payload'],
        ];
    }

    public function scrollSynapseTopics(?int $ownerId = null, int $limit = 1000): array
    {
        $points = [];
        foreach ($this->synapsePoints as $id => $point) {
            if (null !== $ownerId && ($point['payload']['owner_id'] ?? null) !== $ownerId) {
                continue;
            }
            $points[] = ['id' => $id, 'payload' => $point['payload']];
            if (count($points) >= $limit) {
                break;
            }
        }

        return $points;
    }

    public function getSynapseCollectionInfo(): array
    {
        return [
            'exists' => !empty($this->synapsePoints),
            'vector_dim' => 1024,
            'points_count' => count($this->synapsePoints),
            'distance' => 'Cosine',
        ];
    }

    public function recreateSynapseCollection(int $vectorDimension): void
    {
        $this->logger->info('QdrantClientMock: recreateSynapseCollection', ['vector_dim' => $vectorDimension]);
        $this->synapsePoints = [];
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
