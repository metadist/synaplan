<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;
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

    private static ?bool $healthCheckCache = null;
    private static ?int $healthCheckCacheTime = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly LoggerInterface $logger,
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

        // Check cache first (different TTL for success/failure)
        $now = time();
        if (null !== self::$healthCheckCache && null !== self::$healthCheckCacheTime) {
            // Use longer cache for failures (1 min), shorter for success (30s)
            $cacheTTL = self::$healthCheckCache
                ? self::HEALTH_CHECK_CACHE_TTL_SUCCESS
                : self::HEALTH_CHECK_CACHE_TTL_FAILURE;

            if (($now - self::$healthCheckCacheTime) < $cacheTTL) {
                return self::$healthCheckCache;
            }
        }

        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/health", [
                'headers' => $this->getHeaders(),
                'timeout' => 0.5, // 500ms total timeout
                'max_duration' => 0.5, // Also limit total duration
            ]);

            if (200 !== $response->getStatusCode()) {
                self::$healthCheckCache = false;
                self::$healthCheckCacheTime = $now;

                return false;
            }

            // Parse response to check status
            $data = $response->toArray();
            $isHealthy = 'healthy' === ($data['status'] ?? 'unhealthy');

            self::$healthCheckCache = $isHealthy;
            self::$healthCheckCacheTime = $now;

            return $isHealthy;
        } catch (\Throwable $e) {
            // Service is down - cache this for 1 minute
            $this->logger->warning('Qdrant health check failed - caching failure', [
                'error' => $e->getMessage(),
            ]);

            self::$healthCheckCache = false;
            self::$healthCheckCacheTime = $now;

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
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/info", [
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
