<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP Client for Qdrant Microservice.
 *
 * Connects to Rust-based Qdrant service via REST API.
 */
final class QdrantClientHttp implements QdrantClientInterface
{
    private const HEALTH_CHECK_CACHE_TTL_SUCCESS = 30; // Cache successful health check for 30 seconds
    private const HEALTH_CHECK_CACHE_TTL_FAILURE = 60; // Cache failure for 1 minute (reasonable, not too long)

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly ?CacheInterface $cache = null,
        private readonly ?string $apiKey = null,
    ) {
    }

    public function upsertMemory(string $pointId, array $vector, array $payload): void
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/memories", [
                'json' => [
                    'point_id' => $pointId,
                    'vector' => $vector,
                    'payload' => $payload,
                ],
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant upsert failed: {$response->getContent(false)}");
            }

            $this->logger->debug('Memory upserted to Qdrant', [
                'point_id' => $pointId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to upsert memory to Qdrant', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to upsert memory: '.$e->getMessage(), 0, $e);
        }
    }

    public function getMemory(string $pointId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/memories/{$pointId}", [
                'headers' => $this->getHeaders(),
            ]);

            if (404 === $response->getStatusCode()) {
                return null;
            }

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant get failed: {$response->getContent(false)}");
            }

            $data = $response->toArray();

            return $data['payload'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get memory from Qdrant', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function searchMemories(
        array $queryVector,
        int $userId,
        ?string $category = null,
        int $limit = 5,
        float $minScore = 0.7,
    ): array {
        try {
            $payload = [
                'query_vector' => $queryVector,
                'user_id' => $userId,
                'limit' => $limit,
                'min_score' => $minScore,
            ];

            if (null !== $category) {
                $payload['category'] = $category;
            }

            $response = $this->httpClient->request('POST', "{$this->baseUrl}/memories/search", [
                'json' => $payload,
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant search failed: {$response->getContent(false)}");
            }

            $data = $response->toArray();

            return $data['results'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to search memories in Qdrant', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function scrollMemories(
        int $userId,
        ?string $category = null,
        int $limit = 1000,
    ): array {
        try {
            $payload = [
                'user_id' => $userId,
                'limit' => $limit,
            ];

            if (null !== $category) {
                $payload['category'] = $category;
            }

            $response = $this->httpClient->request('POST', "{$this->baseUrl}/memories/scroll", [
                'json' => $payload,
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant scroll failed: {$response->getContent(false)}");
            }

            $data = $response->toArray();

            $this->logger->debug('scrollMemories: Response from Qdrant service', [
                'user_id' => $userId,
                'data_keys' => array_keys($data),
                'memories_count' => count($data['memories'] ?? []),
                'results_count' => count($data['results'] ?? []),
            ]);

            return $data['memories'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to scroll memories in Qdrant', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function deleteMemory(string $pointId): void
    {
        try {
            $response = $this->httpClient->request('DELETE', "{$this->baseUrl}/memories/{$pointId}", [
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode() && 204 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant delete failed: {$response->getContent(false)}");
            }

            $this->logger->debug('Memory deleted from Qdrant', [
                'point_id' => $pointId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete memory from Qdrant', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to delete memory: '.$e->getMessage(), 0, $e);
        }
    }

    public function healthCheck(): bool
    {
        // If base URL is not configured, service is not available
        if (empty($this->baseUrl) || 'http://' === $this->baseUrl || 'https://' === $this->baseUrl) {
            return false;
        }

        if (null === $this->cache) {
            return $this->doHealthCheckRequest();
        }

        $cacheKey = 'qdrant.health.'.sha1($this->baseUrl.'|'.($this->apiKey ?? ''));

        try {
            return (bool) $this->cache->get($cacheKey, function (ItemInterface $item): bool {
                $isHealthy = $this->doHealthCheckRequest();

                $item->expiresAfter($isHealthy
                    ? self::HEALTH_CHECK_CACHE_TTL_SUCCESS
                    : self::HEALTH_CHECK_CACHE_TTL_FAILURE
                );

                return $isHealthy;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Qdrant health check cache failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->doHealthCheckRequest();
        }
    }

    private function doHealthCheckRequest(): bool
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/health", [
                'headers' => $this->getHeaders(),
                'timeout' => 0.5, // 500ms total timeout
                'max_duration' => 0.5, // Also limit total duration
            ]);

            if (200 !== $response->getStatusCode()) {
                return false;
            }

            $data = $response->toArray();

            return 'healthy' === ($data['status'] ?? 'unhealthy');
        } catch (\Throwable $e) {
            $this->logger->warning('Qdrant health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getHealthDetails(): array
    {
        // If base URL is not configured, service is not available
        if (empty($this->baseUrl) || 'http://' === $this->baseUrl || 'https://' === $this->baseUrl) {
            return [
                'status' => 'unavailable',
                'message' => 'Service URL not configured',
            ];
        }

        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/health", [
                'headers' => $this->getHeaders(),
                'timeout' => 3,
            ]);

            if (200 !== $response->getStatusCode()) {
                return [
                    'status' => 'error',
                    'message' => 'Health check returned non-200 status',
                    'http_code' => $response->getStatusCode(),
                ];
            }

            $data = $response->toArray();

            return [
                'status' => $data['status'] ?? 'unknown',
                'service' => $data['service'] ?? 'unknown',
                'version' => $data['version'] ?? 'unknown',
                'uptime_seconds' => $data['uptime_seconds'] ?? 0,
                'qdrant' => $data['qdrant'] ?? [],
                'metrics' => $data['metrics'] ?? [],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Qdrant health check failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function isAvailable(): bool
    {
        // Check if base URL is configured
        if (empty($this->baseUrl) || 'http://' === $this->baseUrl || 'https://' === $this->baseUrl) {
            $this->logger->debug('Qdrant service not configured', [
                'base_url' => $this->baseUrl,
            ]);

            return false;
        }

        // Check if service is healthy
        return $this->healthCheck();
    }

    public function getCollectionInfo(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/collection/info", [
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant collection info failed: {$response->getContent(false)}");
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get Qdrant collection info', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function getServiceInfo(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/service/info", [
                'headers' => $this->getHeaders(),
                'timeout' => 5,
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant service info failed: {$response->getContent(false)}");
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get Qdrant service info', [
                'error' => $e->getMessage(),
            ]);

            return [
                'service' => 'synaplan-qdrant-service',
                'version' => 'unknown',
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    // --- Document Methods ---

    public function upsertDocument(string $pointId, array $vector, array $payload): void
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/documents", [
                'json' => [
                    'point_id' => $pointId,
                    'vector' => $vector,
                    'payload' => $payload,
                ],
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant document upsert failed: {$response->getContent(false)}");
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to upsert document to Qdrant', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function batchUpsertDocuments(array $documents): array
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/documents/batch", [
                'json' => ['documents' => $documents],
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant batch upsert failed: {$response->getContent(false)}");
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to batch upsert documents', [
                'count' => count($documents),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function searchDocuments(
        array $vector,
        int $userId,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3,
    ): array {
        try {
            $payload = [
                'vector' => $vector,
                'user_id' => $userId,
                'limit' => $limit,
                'min_score' => $minScore,
            ];

            if (null !== $groupKey) {
                $payload['group_key'] = $groupKey;
            }

            $response = $this->httpClient->request('POST', "{$this->baseUrl}/documents/search", [
                'json' => $payload,
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant document search failed: {$response->getContent(false)}");
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to search documents', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function getDocument(string $pointId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/documents/{$pointId}", [
                'headers' => $this->getHeaders(),
                'timeout' => 5,
            ]);

            if (404 === $response->getStatusCode()) {
                return null;
            }

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant get document failed: {$response->getContent(false)}");
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get document', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function deleteDocument(string $pointId): void
    {
        try {
            $response = $this->httpClient->request('DELETE', "{$this->baseUrl}/documents/{$pointId}", [
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant delete document failed: {$response->getContent(false)}");
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete document', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deleteDocumentsByFile(int $userId, int $fileId): int
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/documents/delete-by-file", [
                'json' => ['user_id' => $userId, 'file_id' => $fileId],
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant delete by file failed: {$response->getContent(false)}");
            }

            // Response is Json<u64> - a bare JSON number
            return (int) json_decode($response->getContent(), true);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete documents by file', [
                'user_id' => $userId,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function deleteDocumentsByGroupKey(int $userId, string $groupKey): int
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/documents/delete-by-group", [
                'json' => ['user_id' => $userId, 'group_key' => $groupKey],
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant delete by group failed: {$response->getContent(false)}");
            }

            return (int) json_decode($response->getContent(), true);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete documents by group', [
                'user_id' => $userId,
                'group_key' => $groupKey,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function deleteAllDocumentsForUser(int $userId): int
    {
        try {
            $response = $this->httpClient->request('DELETE', "{$this->baseUrl}/documents/user/{$userId}", [
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant delete all for user failed: {$response->getContent(false)}");
            }

            return (int) json_decode($response->getContent(), true);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete all documents for user', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function updateDocumentGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/documents/update-group-key", [
                'json' => [
                    'user_id' => $userId,
                    'file_id' => $fileId,
                    'new_group_key' => $newGroupKey,
                ],
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant update group key failed: {$response->getContent(false)}");
            }

            return (int) json_decode($response->getContent(), true);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update document group key', [
                'user_id' => $userId,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Get chunk info for a specific file from Qdrant.
     *
     * Uses the stats endpoint which includes per-file breakdown (chunks_by_file).
     *
     * @return array{chunks: int, groupKey: string|null}
     */
    public function getDocumentFileInfo(int $userId, int $fileId): array
    {
        try {
            $stats = $this->getDocumentStats($userId);
            $chunksByFile = $stats['chunks_by_file'] ?? [];

            $fileIdStr = (string) $fileId;
            if (isset($chunksByFile[$fileIdStr])) {
                $fileInfo = $chunksByFile[$fileIdStr];

                return [
                    'chunks' => (int) ($fileInfo['chunks'] ?? 0),
                    'groupKey' => $fileInfo['group_key'] ?? null,
                ];
            }

            return ['chunks' => 0, 'groupKey' => null];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get document file info', [
                'user_id' => $userId,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return ['chunks' => 0, 'groupKey' => null];
        }
    }

    /**
     * Get document stats for a user from Qdrant.
     *
     * @throws \RuntimeException On HTTP or network failure
     */
    public function getDocumentStats(int $userId): array
    {
        $response = $this->httpClient->request('GET', "{$this->baseUrl}/documents/stats/{$userId}", [
            'headers' => $this->getHeaders(),
            'timeout' => 5,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException("Qdrant stats failed: {$response->getContent(false)}");
        }

        return $response->toArray();
    }

    public function getDocumentGroupKeys(int $userId): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/documents/groups/{$userId}", [
                'headers' => $this->getHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException("Qdrant group keys failed: {$response->getContent(false)}");
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get document group keys', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get file IDs that have chunks in a specific group key.
     *
     * Uses the stats endpoint (which already scrolls all docs) and derives per-file info.
     *
     * @return int[] Array of file IDs
     */
    public function getFileIdsByGroupKey(int $userId, string $groupKey): array
    {
        $filesInfo = $this->getFilesWithChunks($userId);
        $fileIds = [];
        foreach ($filesInfo as $fileId => $info) {
            if ($info['groupKey'] === $groupKey) {
                $fileIds[] = $fileId;
            }
        }

        return $fileIds;
    }

    /**
     * Get all files with chunks for a user, with per-file chunk count and group key.
     *
     * Uses the stats endpoint which includes per-file breakdown (chunks_by_file).
     *
     * @return array<int, array{chunks: int, groupKey: string|null}> Map of fileId => info
     *
     * @throws \RuntimeException On HTTP or network failure (callers must handle errors)
     */
    public function getFilesWithChunks(int $userId): array
    {
        $stats = $this->getDocumentStats($userId);
        $chunksByFile = $stats['chunks_by_file'] ?? [];

        $result = [];
        foreach ($chunksByFile as $fileId => $info) {
            $result[(int) $fileId] = [
                'chunks' => (int) ($info['chunks'] ?? 0),
                'groupKey' => $info['group_key'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $headers['X-API-Key'] = $this->apiKey;
        }

        return $headers;
    }
}
