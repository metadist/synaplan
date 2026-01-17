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
final readonly class QdrantClientHttp implements QdrantClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private LoggerInterface $logger,
        private ?string $apiKey = null,
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
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/health", [
                'headers' => $this->getHeaders(),
                'timeout' => 3,
            ]);

            return 200 === $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Qdrant health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
