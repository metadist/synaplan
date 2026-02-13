<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use App\Service\RAG\VectorStorage\Exception\ProviderUnavailableException;
use Psr\Log\LoggerInterface;

final readonly class VectorStorageFacade implements VectorStorageInterface
{
    public function __construct(
        private MariaDBVectorStorage $mariaDbStorage,
        private QdrantVectorStorage $qdrantStorage,
        private VectorStorageConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    private function getActiveStorage(): VectorStorageInterface
    {
        $provider = $this->config->getProvider();

        $storage = match ($provider) {
            'qdrant' => $this->qdrantStorage,
            default => $this->mariaDbStorage,
        };

        // Verify the selected storage is available
        if (!$storage->isAvailable()) {
            $this->logger->warning(
                'Selected vector storage provider {provider} is unavailable, falling back to MariaDB',
                ['provider' => $provider]
            );

            if ('mariadb' !== $provider && $this->mariaDbStorage->isAvailable()) {
                return $this->mariaDbStorage;
            }

            throw new ProviderUnavailableException(sprintf('Vector storage provider "%s" is not available', $provider));
        }

        return $storage;
    }

    public function storeChunk(VectorChunk $chunk): string
    {
        return $this->getActiveStorage()->storeChunk($chunk);
    }

    public function storeChunkBatch(array $chunks): int
    {
        return $this->getActiveStorage()->storeChunkBatch($chunks);
    }

    public function deleteByFile(int $userId, int $fileId): int
    {
        return $this->getActiveStorage()->deleteByFile($userId, $fileId);
    }

    public function deleteByGroupKey(int $userId, string $groupKey): int
    {
        return $this->getActiveStorage()->deleteByGroupKey($userId, $groupKey);
    }

    public function deleteAllForUser(int $userId): int
    {
        return $this->getActiveStorage()->deleteAllForUser($userId);
    }

    public function search(SearchQuery $query): array
    {
        $storage = $this->getActiveStorage();

        $this->logger->debug('Vector search via {provider}', [
            'provider' => $storage->getProviderName(),
            'userId' => $query->userId,
            'groupKey' => $query->groupKey,
            'limit' => $query->limit,
        ]);

        return $storage->search($query);
    }

    public function findSimilar(int $userId, int $sourceChunkId, int $limit = 10, float $minScore = 0.3): array
    {
        return $this->getActiveStorage()->findSimilar($userId, $sourceChunkId, $limit, $minScore);
    }

    public function getStats(int $userId): StorageStats
    {
        return $this->getActiveStorage()->getStats($userId);
    }

    public function getGroupKeys(int $userId): array
    {
        return $this->getActiveStorage()->getGroupKeys($userId);
    }

    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        return $this->getActiveStorage()->updateGroupKey($userId, $fileId, $newGroupKey);
    }

    public function getFileChunkInfo(int $userId, int $fileId): array
    {
        return $this->getActiveStorage()->getFileChunkInfo($userId, $fileId);
    }

    public function getFileIdsByGroupKey(int $userId, string $groupKey): array
    {
        return $this->getActiveStorage()->getFileIdsByGroupKey($userId, $groupKey);
    }

    public function getFilesWithChunksByGroupKey(int $userId, string $groupKey): array
    {
        return $this->getActiveStorage()->getFilesWithChunksByGroupKey($userId, $groupKey);
    }

    public function getFilesWithChunks(int $userId): array
    {
        return $this->getActiveStorage()->getFilesWithChunks($userId);
    }

    public function isAvailable(): bool
    {
        $provider = $this->config->getProvider();

        $storage = match ($provider) {
            'qdrant' => $this->qdrantStorage,
            default => $this->mariaDbStorage,
        };

        return $storage->isAvailable();
    }

    public function getProviderName(): string
    {
        try {
            return $this->getActiveStorage()->getProviderName();
        } catch (ProviderUnavailableException) {
            return $this->config->getProvider().' (unavailable)';
        }
    }

    /**
     * Get the currently configured provider name (for status checks).
     */
    public function getConfiguredProvider(): string
    {
        return $this->config->getProvider();
    }
}
