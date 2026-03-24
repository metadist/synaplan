<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Direct Qdrant REST API client — no intermediate microservice.
 *
 * Talks to Qdrant's native REST API on port 6333.
 * Handles collection creation, namespace separation, filtering, and point ID management.
 */
final class QdrantClientDirect implements QdrantClientInterface
{
    private const HEALTH_CACHE_TTL_OK = 30;
    private const HEALTH_CACHE_TTL_FAIL = 60;
    private const DEFAULT_VECTOR_DIM = 1024;
    private const DEFAULT_MEMORIES_COLLECTION = 'user_memories';
    private const DEFAULT_DOCUMENTS_COLLECTION = 'user_documents';
    private const BATCH_LIMIT = 100;

    /** @var array<string, bool> tracks which collections have been verified/created */
    private array $ensuredCollections = [];

    private readonly string $memoriesCollection;
    private readonly string $documentsCollection;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $qdrantUrl,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly ?CacheInterface $cache = null,
        ?string $memoriesCollection = null,
        ?string $documentsCollection = null,
        private readonly int $vectorDimension = self::DEFAULT_VECTOR_DIM,
    ) {
        $this->memoriesCollection = (null !== $memoriesCollection && '' !== $memoriesCollection)
            ? $memoriesCollection
            : self::DEFAULT_MEMORIES_COLLECTION;
        $this->documentsCollection = (null !== $documentsCollection && '' !== $documentsCollection)
            ? $documentsCollection
            : self::DEFAULT_DOCUMENTS_COLLECTION;
    }

    // ──────────────────────────────────────────────
    //  Memory Operations
    // ──────────────────────────────────────────────

    public function upsertMemory(string $pointId, array $vector, array $payload, ?string $namespace = null): void
    {
        $collection = $this->resolveMemoriesCollection($namespace);
        $this->ensureMemoriesCollection($collection);

        $payload['_point_id'] = $pointId;

        try {
            $this->upsertPoints($collection, [
                [
                    'id' => $this->generatePointUuid($pointId),
                    'vector' => $vector,
                    'payload' => $payload,
                ],
            ]);

            $this->logger->debug('Memory upserted to Qdrant', ['point_id' => $pointId]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to upsert memory to Qdrant', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to upsert memory: '.$e->getMessage(), 0, $e);
        }
    }

    public function getMemory(string $pointId, ?string $namespace = null): ?array
    {
        $collection = $this->resolveMemoriesCollection($namespace);

        try {
            $uuid = $this->generatePointUuid($pointId);
            $response = $this->qdrantRequest('POST', "/collections/{$collection}/points", [
                'ids' => [$uuid],
                'with_payload' => true,
                'with_vector' => false,
            ]);

            $points = $response['result'] ?? [];
            if (empty($points)) {
                return null;
            }

            return $points[0]['payload'] ?? null;
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
        ?string $namespace = null,
    ): array {
        $collection = $this->resolveMemoriesCollection($namespace);

        try {
            $must = [
                ['key' => 'user_id', 'match' => ['value' => $userId]],
                ['key' => 'active', 'match' => ['value' => true]],
            ];

            if (null !== $category) {
                $must[] = ['key' => 'category', 'match' => ['value' => $category]];
            }

            $response = $this->qdrantRequest('POST', "/collections/{$collection}/points/search", [
                'vector' => $queryVector,
                'filter' => ['must' => $must],
                'limit' => $limit,
                'score_threshold' => $minScore,
                'with_payload' => true,
            ]);

            $results = [];
            foreach ($response['result'] ?? [] as $hit) {
                $results[] = [
                    'id' => $hit['payload']['_point_id'] ?? (string) $hit['id'],
                    'score' => $hit['score'],
                    'payload' => $hit['payload'] ?? [],
                ];
            }

            return $results;
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
        ?string $namespace = null,
    ): array {
        $collection = $this->resolveMemoriesCollection($namespace);

        try {
            $must = [
                ['key' => 'user_id', 'match' => ['value' => $userId]],
                ['key' => 'active', 'match' => ['value' => true]],
            ];

            if (null !== $category) {
                $must[] = ['key' => 'category', 'match' => ['value' => $category]];
            }

            $response = $this->qdrantRequest('POST', "/collections/{$collection}/points/scroll", [
                'filter' => ['must' => $must],
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ]);

            $memories = [];
            foreach ($response['result']['points'] ?? [] as $point) {
                $memories[] = [
                    'id' => $point['payload']['_point_id'] ?? (string) $point['id'],
                    'payload' => $point['payload'] ?? [],
                ];
            }

            return $memories;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to scroll memories in Qdrant', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function deleteMemory(string $pointId, ?string $namespace = null): void
    {
        $collection = $this->resolveMemoriesCollection($namespace);

        try {
            $uuid = $this->generatePointUuid($pointId);
            $this->qdrantRequest('POST', "/collections/{$collection}/points/delete", [
                'points' => [$uuid],
            ]);

            $this->logger->debug('Memory deleted from Qdrant', ['point_id' => $pointId]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete memory from Qdrant', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to delete memory: '.$e->getMessage(), 0, $e);
        }
    }

    public function deleteAllMemoriesForUser(int $userId): int
    {
        try {
            $this->qdrantRequest('POST', "/collections/{$this->memoriesCollection}/points/delete", [
                'filter' => [
                    'must' => [
                        ['key' => 'user_id', 'match' => ['value' => $userId]],
                    ],
                ],
            ]);

            $this->logger->info('All memories deleted from Qdrant for user', ['user_id' => $userId]);

            return 1;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete all memories for user from Qdrant', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    // ──────────────────────────────────────────────
    //  Document Operations
    // ──────────────────────────────────────────────

    public function upsertDocument(string $pointId, array $vector, array $payload): void
    {
        $this->ensureDocumentsCollection();

        $payload['_point_id'] = $pointId;

        try {
            $this->upsertPoints($this->documentsCollection, [
                [
                    'id' => $this->generatePointUuid($pointId),
                    'vector' => $vector,
                    'payload' => $payload,
                ],
            ]);
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
        $this->ensureDocumentsCollection();

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach (array_chunk($documents, self::BATCH_LIMIT) as $batch) {
            $points = [];
            foreach ($batch as $doc) {
                $payload = $doc['payload'] ?? [];
                $payload['_point_id'] = $doc['point_id'];
                $points[] = [
                    'id' => $this->generatePointUuid($doc['point_id']),
                    'vector' => $doc['vector'],
                    'payload' => $payload,
                ];
            }

            try {
                $this->upsertPoints($this->documentsCollection, $points);
                $successCount += count($batch);
            } catch (\Throwable $e) {
                $failedCount += count($batch);
                $errors[] = $e->getMessage();
                $this->logger->error('Batch upsert failed', [
                    'count' => count($batch),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ];
    }

    public function searchDocuments(
        array $vector,
        int $userId,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3,
    ): array {
        try {
            $must = [
                ['key' => 'user_id', 'match' => ['value' => $userId]],
            ];

            if (null !== $groupKey) {
                $must[] = ['key' => 'group_key', 'match' => ['value' => $groupKey]];
            }

            $response = $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/search", [
                'vector' => $vector,
                'filter' => ['must' => $must],
                'limit' => $limit,
                'score_threshold' => $minScore,
                'with_payload' => true,
            ]);

            $results = [];
            foreach ($response['result'] ?? [] as $hit) {
                $results[] = [
                    'id' => $hit['payload']['_point_id'] ?? (string) $hit['id'],
                    'score' => $hit['score'],
                    'payload' => $hit['payload'] ?? [],
                ];
            }

            return $results;
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
            $uuid = $this->generatePointUuid($pointId);
            $response = $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points", [
                'ids' => [$uuid],
                'with_payload' => true,
                'with_vector' => true,
            ]);

            $points = $response['result'] ?? [];
            if (empty($points)) {
                return null;
            }

            $point = $points[0];

            return [
                'id' => $point['payload']['_point_id'] ?? (string) $point['id'],
                'payload' => $point['payload'] ?? [],
                'vector' => $point['vector'] ?? [],
            ];
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
            $uuid = $this->generatePointUuid($pointId);
            $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/delete", [
                'points' => [$uuid],
            ]);
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
        return $this->deleteDocumentsByFilter($userId, [
            ['key' => 'file_id', 'match' => ['value' => $fileId]],
        ]);
    }

    public function deleteDocumentsByGroupKey(int $userId, string $groupKey): int
    {
        return $this->deleteDocumentsByFilter($userId, [
            ['key' => 'group_key', 'match' => ['value' => $groupKey]],
        ]);
    }

    public function deleteAllDocumentsForUser(int $userId): int
    {
        return $this->deleteDocumentsByFilter($userId, []);
    }

    public function updateDocumentGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        try {
            $points = $this->scrollAllDocumentPoints($userId, [
                ['key' => 'file_id', 'match' => ['value' => $fileId]],
            ]);

            if (empty($points)) {
                return 0;
            }

            $pointIds = array_map(fn (array $p) => $p['id'], $points);

            $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/payload", [
                'payload' => ['group_key' => $newGroupKey],
                'points' => $pointIds,
            ]);

            return count($pointIds);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update document group key', [
                'user_id' => $userId,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function getDocumentStats(int $userId): array
    {
        try {
            $points = $this->scrollAllDocumentPoints($userId, []);

            $chunksByFile = [];
            $chunksByGroup = [];

            foreach ($points as $point) {
                /** @var array<string, mixed> $payload */
                $payload = $point['payload'];
                $fileId = (string) ($payload['file_id'] ?? 0);
                $groupKey = $payload['group_key'] ?? 'default';

                if (!isset($chunksByFile[$fileId])) {
                    $chunksByFile[$fileId] = ['chunks' => 0, 'group_key' => $groupKey];
                }
                ++$chunksByFile[$fileId]['chunks'];

                if (!isset($chunksByGroup[$groupKey])) {
                    $chunksByGroup[$groupKey] = 0;
                }
                ++$chunksByGroup[$groupKey];
            }

            return [
                'total_chunks' => count($points),
                'total_files' => count($chunksByFile),
                'total_groups' => count($chunksByGroup),
                'chunks_by_file' => $chunksByFile,
                'chunks_by_group' => $chunksByGroup,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get document stats', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'total_chunks' => 0,
                'total_files' => 0,
                'total_groups' => 0,
                'chunks_by_file' => [],
                'chunks_by_group' => [],
            ];
        }
    }

    public function getDocumentGroupKeys(int $userId): array
    {
        $stats = $this->getDocumentStats($userId);

        return array_keys($stats['chunks_by_group'] ?? []);
    }

    public function getDocumentFileInfo(int $userId, int $fileId): array
    {
        try {
            $stats = $this->getDocumentStats($userId);
            $chunksByFile = $stats['chunks_by_file'] ?? [];

            $fileIdStr = (string) $fileId;
            if (isset($chunksByFile[$fileIdStr])) {
                return [
                    'chunks' => (int) ($chunksByFile[$fileIdStr]['chunks'] ?? 0),
                    'groupKey' => $chunksByFile[$fileIdStr]['group_key'] ?? null,
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

    public function getFileIdsByGroupKey(int $userId, string $groupKey): array
    {
        $filesInfo = $this->getFilesWithChunksByGroupKey($userId, $groupKey);

        return array_keys($filesInfo);
    }

    public function getFilesWithChunksByGroupKey(int $userId, string $groupKey): array
    {
        try {
            $points = $this->scrollAllDocumentPoints($userId, [
                ['key' => 'group_key', 'match' => ['value' => $groupKey]],
            ]);

            $result = [];
            foreach ($points as $point) {
                $fileId = (int) ($point['payload']['file_id'] ?? 0);
                if (!isset($result[$fileId])) {
                    $result[$fileId] = ['chunks' => 0, 'groupKey' => $groupKey];
                }
                ++$result[$fileId]['chunks'];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get files by group key', [
                'user_id' => $userId,
                'group_key' => $groupKey,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

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

    // ──────────────────────────────────────────────
    //  Health & Info
    // ──────────────────────────────────────────────

    public function healthCheck(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        if (null === $this->cache) {
            return $this->doHealthCheck();
        }

        $cacheKey = 'qdrant.health.'.sha1($this->qdrantUrl);

        try {
            return (bool) $this->cache->get($cacheKey, function (ItemInterface $item): bool {
                $isHealthy = $this->doHealthCheck();

                $item->expiresAfter($isHealthy ? self::HEALTH_CACHE_TTL_OK : self::HEALTH_CACHE_TTL_FAIL);

                return $isHealthy;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Qdrant health check cache failed', ['error' => $e->getMessage()]);

            return $this->doHealthCheck();
        }
    }

    public function getHealthDetails(): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'unavailable', 'message' => 'Qdrant URL not configured'];
        }

        try {
            $response = $this->httpClient->request('GET', "{$this->qdrantUrl}/healthz", [
                'headers' => $this->getHeaders(),
                'timeout' => 3,
            ]);

            $healthy = 200 === $response->getStatusCode();

            $collectionsResponse = $this->qdrantRequest('GET', '/collections');
            $collections = $collectionsResponse['result']['collections'] ?? [];

            return [
                'status' => $healthy ? 'healthy' : 'error',
                'service' => 'qdrant-direct',
                'version' => $response->getHeaders(false)['x-qdrant-version'][0] ?? 'unknown',
                'qdrant' => [
                    'status' => $healthy ? 'connected' : 'error',
                    'collections_count' => count($collections),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Qdrant health check failed', ['error' => $e->getMessage()]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function isAvailable(): bool
    {
        if (!$this->isConfigured()) {
            $this->logger->debug('Qdrant not configured', ['url' => $this->qdrantUrl]);

            return false;
        }

        return $this->healthCheck();
    }

    public function getCollectionInfo(): array
    {
        try {
            $response = $this->qdrantRequest('GET', "/collections/{$this->memoriesCollection}");
            $info = $response['result'] ?? [];

            return [
                'status' => $info['status'] ?? 'unknown',
                'points_count' => $info['points_count'] ?? 0,
                'vectors_count' => $info['vectors_count'] ?? 0,
                'indexed_vectors_count' => $info['indexed_vectors_count'] ?? 0,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get Qdrant collection info', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getServiceInfo(): array
    {
        try {
            $telemetry = $this->qdrantRequest('GET', '/telemetry');
            $version = $telemetry['result']['app']['version'] ?? 'unknown';

            $collectionInfo = $this->getCollectionInfo();

            return [
                'service' => 'qdrant-direct',
                'version' => $version,
                'status' => 'ok',
                'collection' => $collectionInfo,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get Qdrant service info', ['error' => $e->getMessage()]);

            return [
                'service' => 'qdrant-direct',
                'version' => 'unknown',
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    // ──────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────

    /**
     * Deterministic UUID v5 from a string point ID for stable mapping.
     */
    private function generatePointUuid(string $pointId): string
    {
        return Uuid::v5(Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8'), $pointId)->toRfc4122();
    }

    private function resolveMemoriesCollection(?string $namespace): string
    {
        if (null === $namespace || '' === $namespace) {
            return $this->memoriesCollection;
        }

        $sanitized = preg_replace('/[^a-z0-9_-]/', '', strtolower($namespace));

        return "{$this->memoriesCollection}_{$sanitized}";
    }

    private function ensureMemoriesCollection(string $collection): void
    {
        if (isset($this->ensuredCollections[$collection])) {
            return;
        }

        try {
            $this->qdrantRequest('GET', "/collections/{$collection}");
            $this->ensuredCollections[$collection] = true;

            return;
        } catch (\Throwable) {
            // Collection doesn't exist, create it
        }

        try {
            $this->qdrantRequest('PUT', "/collections/{$collection}", [
                'vectors' => [
                    'size' => $this->vectorDimension,
                    'distance' => 'Cosine',
                ],
            ]);
            $this->ensuredCollections[$collection] = true;

            $this->logger->info('Created Qdrant memories collection', ['collection' => $collection]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create memories collection', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function ensureDocumentsCollection(): void
    {
        $collection = $this->documentsCollection;

        if (isset($this->ensuredCollections[$collection])) {
            return;
        }

        try {
            $this->qdrantRequest('GET', "/collections/{$collection}");
            $this->ensuredCollections[$collection] = true;

            return;
        } catch (\Throwable) {
            // Collection doesn't exist, create it
        }

        try {
            $this->qdrantRequest('PUT', "/collections/{$collection}", [
                'vectors' => [
                    'size' => $this->vectorDimension,
                    'distance' => 'Cosine',
                    'hnsw_config' => [
                        'm' => 16,
                        'ef_construct' => 100,
                    ],
                ],
            ]);

            $this->createPayloadIndex($collection, 'user_id', 'integer');
            $this->createPayloadIndex($collection, 'file_id', 'integer');
            $this->createPayloadIndex($collection, 'group_key', 'keyword');

            $this->ensuredCollections[$collection] = true;

            $this->logger->info('Created Qdrant documents collection with indices', [
                'collection' => $collection,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create documents collection', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function createPayloadIndex(string $collection, string $field, string $type): void
    {
        $this->qdrantRequest('PUT', "/collections/{$collection}/index", [
            'field_name' => $field,
            'field_schema' => $type,
        ]);
    }

    /**
     * @param array<array{id: string, vector: float[], payload: array}> $points
     */
    private function upsertPoints(string $collection, array $points): void
    {
        $this->qdrantRequest('PUT', "/collections/{$collection}/points?wait=true", [
            'points' => $points,
        ]);
    }

    private function deleteDocumentsByFilter(int $userId, array $extraConditions): int
    {
        try {
            $must = [
                ['key' => 'user_id', 'match' => ['value' => $userId]],
                ...$extraConditions,
            ];

            $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/delete", [
                'filter' => ['must' => $must],
            ]);

            return 1;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete documents by filter', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Scroll through all document points matching a user + extra filters.
     *
     * @return array<array{id: string, payload: array}>
     */
    private function scrollAllDocumentPoints(int $userId, array $extraConditions): array
    {
        $must = [
            ['key' => 'user_id', 'match' => ['value' => $userId]],
            ...$extraConditions,
        ];

        $allPoints = [];
        $offset = null;
        $scrollLimit = 100;

        do {
            $body = [
                'filter' => ['must' => $must],
                'limit' => $scrollLimit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if (null !== $offset) {
                $body['offset'] = $offset;
            }

            $response = $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/scroll", $body);

            $points = $response['result']['points'] ?? [];
            $allPoints = array_merge($allPoints, $points);

            $offset = $response['result']['next_page_offset'] ?? null;
        } while (null !== $offset && !empty($points));

        return $allPoints;
    }

    private function doHealthCheck(): bool
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->qdrantUrl}/healthz", [
                'headers' => $this->getHeaders(),
                'timeout' => 0.5,
                'max_duration' => 0.5,
            ]);

            return 200 === $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Qdrant health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function isConfigured(): bool
    {
        return !empty($this->qdrantUrl) && 'http://' !== $this->qdrantUrl && 'https://' !== $this->qdrantUrl;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    /**
     * Send a request to the Qdrant REST API and return the decoded response.
     *
     * @throws \RuntimeException on non-2xx responses or network errors
     */
    private function qdrantRequest(string $method, string $path, ?array $body = null): array
    {
        $options = ['headers' => $this->getHeaders()];

        if (null !== $body) {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, "{$this->qdrantUrl}{$path}", $options);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException("Qdrant request failed [{$method} {$path}]: HTTP {$statusCode} — {$response->getContent(false)}");
        }

        $content = $response->getContent();
        if ('' === $content) {
            return [];
        }

        return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
