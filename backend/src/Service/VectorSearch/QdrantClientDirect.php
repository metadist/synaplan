<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    private const DEFAULT_SYNAPSE_COLLECTION = 'synapse_topics';
    private const BATCH_LIMIT = 100;

    /** @var array<string, bool> tracks which collections have been verified/created */
    private array $ensuredCollections = [];

    private readonly string $memoriesCollection;
    private readonly string $documentsCollection;
    private readonly string $synapseCollection;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $qdrantUrl,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly ?CacheInterface $cache = null,
        ?string $memoriesCollection = null,
        ?string $documentsCollection = null,
        private readonly int $vectorDimension = self::DEFAULT_VECTOR_DIM,
        ?string $synapseCollection = null,
    ) {
        $this->memoriesCollection = (null !== $memoriesCollection && '' !== $memoriesCollection)
            ? $memoriesCollection
            : self::DEFAULT_MEMORIES_COLLECTION;
        $this->documentsCollection = (null !== $documentsCollection && '' !== $documentsCollection)
            ? $documentsCollection
            : self::DEFAULT_DOCUMENTS_COLLECTION;
        $this->synapseCollection = (null !== $synapseCollection && '' !== $synapseCollection)
            ? $synapseCollection
            : self::DEFAULT_SYNAPSE_COLLECTION;
    }

    public function getMemoriesCollection(): string
    {
        return $this->memoriesCollection;
    }

    public function getDocumentsCollection(): string
    {
        return $this->documentsCollection;
    }

    public function getQdrantUrl(): string
    {
        return $this->qdrantUrl;
    }

    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
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
            // Atomic delete-by-payload + upsert in a single request. The pre-
            // delete is needed because production clusters still contain
            // legacy integer-keyed points from the pre-v2.4.0 Rust
            // microservice that share `_point_id` with the new UUID-keyed
            // points. Without this step, the legacy ghost and the new point
            // would coexist and scrolls would surface duplicates.
            //
            // Using /points/batch rather than two sequential calls: keeps
            // latency the same as a plain upsert, orders the ops server-side
            // (delete is validated and applied before upsert), and avoids
            // the "crash between delete and upsert leaves no point" window
            // that two separate wait=true requests would open.
            $this->pointsBatch($collection, [
                ['delete' => ['filter' => QdrantPointId::payloadFilterFor($pointId)]],
                ['upsert' => ['points' => [
                    [
                        'id' => QdrantPointId::uuidFor($pointId),
                        'vector' => $vector,
                        'payload' => $payload,
                    ],
                ]]],
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

    /**
     * Fetch a single memory point's payload, if present.
     *
     * `with_vector: false` is deliberate — memory callers only need the
     * payload (key/value/category/…) and skipping 1024 floats per request
     * keeps this on the hot path of chat responses. Returns the payload
     * only; memories do not expose a vector-returning read path because no
     * current caller needs it. If one ever does, add a dedicated method
     * rather than toggling `with_vector` here (the default empty-payload
     * savings matter for latency).
     */
    public function getMemory(string $pointId, ?string $namespace = null): ?array
    {
        $collection = $this->resolveMemoriesCollection($namespace);

        try {
            // Scroll by payload filter on `_point_id` — works regardless of
            // whether the underlying Qdrant primary ID is the derived UUIDv5
            // (current scheme) or a legacy integer from the pre-v2.4.0 Rust
            // microservice. HTTP 404 from this endpoint means the *collection*
            // is missing (verified against Qdrant 1.x), which is an
            // infrastructure problem rather than a missing memory, so we let
            // the underlying \RuntimeException propagate for 503 mapping.
            $response = $this->qdrantRequest('POST', "/collections/{$collection}/points/scroll", [
                'filter' => QdrantPointId::payloadFilterFor($pointId),
                'limit' => 1,
                'with_payload' => true,
                'with_vector' => false,
            ]);

            $points = $response['result']['points'] ?? [];
            if (empty($points)) {
                return null;
            }

            return $points[0]['payload'] ?? null;
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to get memory from Qdrant', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
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

            // Ask for up to 2x the requested limit so dedup below doesn't
            // short-change the caller when legacy+UUID pairs sit above the
            // score threshold for the same logical point.
            $response = $this->qdrantRequest('POST', "/collections/{$collection}/points/search", [
                'vector' => $queryVector,
                'filter' => ['must' => $must],
                'limit' => $limit * 2,
                'score_threshold' => $minScore,
                'with_payload' => true,
            ]);

            // Dedup by `_point_id` — keep the best-scoring copy of each
            // logical point. Until `app:qdrant:migrate-legacy-point-ids`
            // runs, production collections may still have two rows per
            // logical point (one integer-keyed, one UUID-keyed), so the
            // same `_point_id` can appear twice in one response.
            $bestByLogical = [];
            foreach ($response['result'] ?? [] as $hit) {
                $logical = $hit['payload']['_point_id'] ?? (string) $hit['id'];
                if (!isset($bestByLogical[$logical]) || $hit['score'] > $bestByLogical[$logical]['score']) {
                    $bestByLogical[$logical] = [
                        'id' => $logical,
                        'score' => $hit['score'],
                        'payload' => $hit['payload'] ?? [],
                    ];
                }
            }

            $results = array_values($bestByLogical);
            usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            return array_slice($results, 0, $limit);
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

            // Dedup by `_point_id` — see searchMemories() for why this is
            // needed until the legacy-point migration has run. First-seen
            // wins; scrolls don't return a score to discriminate on.
            $memoriesByLogical = [];
            foreach ($response['result']['points'] ?? [] as $point) {
                $logical = $point['payload']['_point_id'] ?? (string) $point['id'];
                if (!isset($memoriesByLogical[$logical])) {
                    $memoriesByLogical[$logical] = [
                        'id' => $logical,
                        'payload' => $point['payload'] ?? [],
                    ];
                }
            }

            return array_values($memoriesByLogical);
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
            // Delete by payload filter on `_point_id` rather than by the derived
            // UUIDv5 primary key. This is the only scheme that works uniformly
            // for BOTH legacy points (keyed by the Rust microservice's integer
            // hash, pre-v2.4.0) and current points (keyed by UUIDv5). Deleting
            // by a mismatched primary ID returns HTTP 200 with no effect — the
            // bug this fix addresses.
            $this->qdrantRequest('POST', "/collections/{$collection}/points/delete?wait=true", [
                'filter' => QdrantPointId::payloadFilterFor($pointId),
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
            $filter = [
                'must' => [
                    ['key' => 'user_id', 'match' => ['value' => $userId]],
                ],
            ];

            $countResponse = $this->qdrantRequest('POST', "/collections/{$this->memoriesCollection}/points/count", [
                'filter' => $filter,
            ]);
            $deletedCount = (int) ($countResponse['result']['count'] ?? 0);

            if (0 === $deletedCount) {
                return 0;
            }

            $this->qdrantRequest('POST', "/collections/{$this->memoriesCollection}/points/delete?wait=true", [
                'filter' => $filter,
            ]);

            $this->logger->info('All memories deleted from Qdrant for user', [
                'user_id' => $userId,
                'deleted_count' => $deletedCount,
            ]);

            return $deletedCount;
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
            // See upsertMemory() — atomic delete-by-payload + upsert via
            // /points/batch removes any legacy integer-keyed ghost and
            // writes the new UUID-keyed point in a single request.
            $this->pointsBatch($this->documentsCollection, [
                ['delete' => ['filter' => QdrantPointId::payloadFilterFor($pointId)]],
                ['upsert' => ['points' => [
                    [
                        'id' => QdrantPointId::uuidFor($pointId),
                        'vector' => $vector,
                        'payload' => $payload,
                    ],
                ]]],
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
                    'id' => QdrantPointId::uuidFor($doc['point_id']),
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

    /**
     * Fetch a single document chunk, including its vector.
     *
     * `with_vector: true` (unlike getMemory()) because callers of this
     * endpoint are generally about to reuse the vector — e.g. re-indexing
     * or rebuilding a RAG group. It is NOT on any hot request path, so
     * paying the 1024-float payload cost is fine.
     */
    public function getDocument(string $pointId): ?array
    {
        try {
            // See getMemory() — scroll by `_point_id` payload so legacy
            // integer-keyed points are still findable.
            $response = $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/scroll", [
                'filter' => QdrantPointId::payloadFilterFor($pointId),
                'limit' => 1,
                'with_payload' => true,
                'with_vector' => true,
            ]);

            $points = $response['result']['points'] ?? [];
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
            // See deleteMemory() for why payload-filter delete is the only
            // correct scheme for a collection that may still contain legacy
            // integer-keyed points alongside current UUID-keyed ones.
            $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/delete?wait=true", [
                'filter' => QdrantPointId::payloadFilterFor($pointId),
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
            $filter = [
                'must' => [
                    ['key' => 'user_id', 'match' => ['value' => $userId]],
                    ['key' => 'file_id', 'match' => ['value' => $fileId]],
                ],
            ];

            $countResponse = $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/count", [
                'filter' => $filter,
            ]);
            $count = (int) ($countResponse['result']['count'] ?? 0);

            if (0 === $count) {
                return 0;
            }

            $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/payload?wait=true", [
                'payload' => ['group_key' => $newGroupKey],
                'filter' => $filter,
            ]);

            return $count;
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
    //  Synapse Routing Operations
    // ──────────────────────────────────────────────

    public function getSynapseCollection(): string
    {
        return $this->synapseCollection;
    }

    public function upsertSynapseTopic(string $pointId, array $vector, array $payload): void
    {
        $this->ensureSynapseCollection();
        $payload['_point_id'] = $pointId;

        try {
            $this->pointsBatch($this->synapseCollection, [
                ['delete' => ['filter' => QdrantPointId::payloadFilterFor($pointId)]],
                ['upsert' => ['points' => [
                    [
                        'id' => QdrantPointId::uuidFor($pointId),
                        'vector' => $vector,
                        'payload' => $payload,
                    ],
                ]]],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to upsert synapse topic', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to upsert synapse topic: '.$e->getMessage(), 0, $e);
        }
    }

    public function searchSynapseTopics(
        array $queryVector,
        int $userId,
        int $limit = 5,
        float $minScore = 0.3,
    ): array {
        try {
            $response = $this->qdrantRequest('POST', "/collections/{$this->synapseCollection}/points/search", [
                'vector' => $queryVector,
                'filter' => [
                    'should' => [
                        ['key' => 'owner_id', 'match' => ['value' => 0]],
                        ['key' => 'owner_id', 'match' => ['value' => $userId]],
                    ],
                ],
                'limit' => $limit,
                'score_threshold' => $minScore,
                'with_payload' => true,
            ]);

            $rawResults = $response['result'] ?? [];

            if (empty($rawResults)) {
                $this->logger->warning('Synapse search returned empty from Qdrant', [
                    'vector_length' => count($queryVector),
                    'user_id' => $userId,
                    'response_status' => $response['status'] ?? 'unknown',
                ]);
            }

            $results = [];
            foreach ($rawResults as $hit) {
                $results[] = [
                    'id' => $hit['payload']['_point_id'] ?? (string) $hit['id'],
                    'score' => $hit['score'],
                    'payload' => $hit['payload'] ?? [],
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to search synapse topics', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function deleteSynapseTopic(string $pointId): void
    {
        try {
            $this->qdrantRequest('POST', "/collections/{$this->synapseCollection}/points/delete?wait=true", [
                'filter' => QdrantPointId::payloadFilterFor($pointId),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete synapse topic', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleteSynapseTopicsByOwner(int $ownerId): int
    {
        $this->ensureSynapseCollection();

        try {
            $filter = ['must' => [
                ['key' => 'owner_id', 'match' => ['value' => $ownerId]],
            ]];

            $countResponse = $this->qdrantRequest('POST', "/collections/{$this->synapseCollection}/points/count", [
                'filter' => $filter,
            ]);
            $count = (int) ($countResponse['result']['count'] ?? 0);

            if (0 === $count) {
                return 0;
            }

            $this->qdrantRequest('POST', "/collections/{$this->synapseCollection}/points/delete?wait=true", [
                'filter' => $filter,
            ]);

            return $count;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete synapse topics by owner', [
                'owner_id' => $ownerId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function getSynapseTopic(string $pointId): ?array
    {
        try {
            $response = $this->qdrantRequest('POST', "/collections/{$this->synapseCollection}/points/scroll", [
                'filter' => QdrantPointId::payloadFilterFor($pointId),
                'limit' => 1,
                'with_payload' => true,
                'with_vector' => false,
            ]);

            $points = $response['result']['points'] ?? [];
            if (empty($points)) {
                return null;
            }

            $point = $points[0];

            return [
                'id' => $point['payload']['_point_id'] ?? (string) ($point['id'] ?? $pointId),
                'payload' => $point['payload'] ?? [],
            ];
        } catch (\Throwable $e) {
            // Missing collection is an expected first-run state, not an error
            $this->logger->debug('getSynapseTopic: lookup failed (likely missing collection)', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function scrollSynapseTopics(?int $ownerId = null, int $limit = 1000): array
    {
        try {
            $body = [
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if (null !== $ownerId) {
                $body['filter'] = ['must' => [
                    ['key' => 'owner_id', 'match' => ['value' => $ownerId]],
                ]];
            }

            $points = [];
            $offset = null;

            do {
                if (null !== $offset) {
                    $body['offset'] = $offset;
                }

                $response = $this->qdrantRequest(
                    'POST',
                    "/collections/{$this->synapseCollection}/points/scroll",
                    $body,
                );

                $batch = $response['result']['points'] ?? [];
                foreach ($batch as $point) {
                    $points[] = [
                        'id' => $point['payload']['_point_id'] ?? (string) ($point['id'] ?? ''),
                        'payload' => $point['payload'] ?? [],
                    ];
                }

                $offset = $response['result']['next_page_offset'] ?? null;
            } while (null !== $offset && count($points) < $limit);

            return $points;
        } catch (\Throwable $e) {
            $this->logger->debug('scrollSynapseTopics: scroll failed (likely missing collection)', [
                'owner_id' => $ownerId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function getSynapseCollectionInfo(): array
    {
        $defaults = [
            'exists' => false,
            'vector_dim' => null,
            'points_count' => null,
            'distance' => null,
        ];

        try {
            $response = $this->qdrantRequest('GET', "/collections/{$this->synapseCollection}");
            $result = $response['result'] ?? [];

            $vectorsConfig = $result['config']['params']['vectors'] ?? [];

            return [
                'exists' => true,
                'vector_dim' => isset($vectorsConfig['size']) ? (int) $vectorsConfig['size'] : null,
                'points_count' => isset($result['points_count']) ? (int) $result['points_count'] : null,
                'distance' => $vectorsConfig['distance'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('getSynapseCollectionInfo: collection missing or unreachable', [
                'error' => $e->getMessage(),
            ]);

            return $defaults;
        }
    }

    public function recreateSynapseCollection(int $vectorDimension): void
    {
        $collection = $this->synapseCollection;

        try {
            $this->qdrantRequest('DELETE', "/collections/{$collection}");
            unset($this->ensuredCollections[$collection]);
            $this->logger->info('Dropped existing synapse collection', [
                'collection' => $collection,
            ]);
        } catch (\Throwable $e) {
            // Missing collection is fine — we're about to (re)create it
            $this->logger->debug('recreateSynapseCollection: drop skipped', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->qdrantRequest('PUT', "/collections/{$collection}", [
                'vectors' => [
                    'size' => $vectorDimension,
                    'distance' => 'Cosine',
                ],
            ]);

            $this->createPayloadIndex($collection, 'owner_id', 'integer');
            $this->createPayloadIndex($collection, 'topic', 'keyword');
            $this->createPayloadIndex($collection, '_point_id', 'keyword');
            $this->createPayloadIndex($collection, 'embedding_model_id', 'integer');

            $this->ensuredCollections[$collection] = true;

            $this->logger->info('Recreated Qdrant synapse collection', [
                'collection' => $collection,
                'vector_dim' => $vectorDimension,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to recreate synapse collection', [
                'collection' => $collection,
                'vector_dim' => $vectorDimension,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function ensureSynapseCollection(): void
    {
        $collection = $this->synapseCollection;

        if (isset($this->ensuredCollections[$collection])) {
            return;
        }

        try {
            $this->qdrantRequest('GET', "/collections/{$collection}");
            $this->ensurePayloadIndexes($collection, [
                'owner_id' => 'integer',
                'topic' => 'keyword',
                '_point_id' => 'keyword',
                'embedding_model_id' => 'integer',
            ]);
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

            $this->createPayloadIndex($collection, 'owner_id', 'integer');
            $this->createPayloadIndex($collection, 'topic', 'keyword');
            $this->createPayloadIndex($collection, '_point_id', 'keyword');
            $this->createPayloadIndex($collection, 'embedding_model_id', 'integer');

            $this->ensuredCollections[$collection] = true;

            $this->logger->info('Created Qdrant synapse collection', ['collection' => $collection]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create synapse collection', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ──────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────

    // Canonical point-ID helpers (UUID derivation, `_point_id` payload filter)
    // live in {@see QdrantPointId} so the maintenance CLI and any other
    // consumer derive identical UUIDs without duplicating the namespace
    // constant.

    private function resolveMemoriesCollection(?string $namespace): string
    {
        if (null === $namespace || '' === $namespace) {
            return $this->memoriesCollection;
        }

        $sanitized = preg_replace('/[^a-z0-9_-]/', '', strtolower($namespace));

        if ('' === $sanitized) {
            return $this->memoriesCollection;
        }

        return "{$this->memoriesCollection}_{$sanitized}";
    }

    private function ensureMemoriesCollection(string $collection): void
    {
        if (isset($this->ensuredCollections[$collection])) {
            return;
        }

        try {
            $this->qdrantRequest('GET', "/collections/{$collection}");
            // Idempotently ensure payload indexes exist even on a pre-existing
            // collection. Qdrant's PUT /collections/{c}/index is a no-op when
            // the index already exists. This is what upgrades long-lived prod
            // collections to have `_point_id` indexed without a schema migration.
            $this->ensurePayloadIndexes($collection, [
                'user_id' => 'integer',
                'category' => 'keyword',
                'active' => 'bool',
                '_point_id' => 'keyword',
            ]);
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

            $this->createPayloadIndex($collection, 'user_id', 'integer');
            $this->createPayloadIndex($collection, 'category', 'keyword');
            $this->createPayloadIndex($collection, 'active', 'bool');
            $this->createPayloadIndex($collection, '_point_id', 'keyword');

            $this->ensuredCollections[$collection] = true;

            $this->logger->info('Created Qdrant memories collection with indices', ['collection' => $collection]);
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
            // See ensureMemoriesCollection() — idempotent payload-index
            // backfill for long-lived pre-existing collections.
            $this->ensurePayloadIndexes($collection, [
                'user_id' => 'integer',
                'file_id' => 'integer',
                'group_key' => 'keyword',
                '_point_id' => 'keyword',
            ]);
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
            $this->createPayloadIndex($collection, '_point_id', 'keyword');

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
     * Idempotently ensure a set of payload indexes exist on a collection.
     *
     * Qdrant's PUT /collections/{c}/index succeeds even when the index
     * already exists, so this is safe to call on every startup. Individual
     * failures are logged but do not abort the loop — one missing index
     * should not block the others from being created.
     *
     * @param array<string, string> $fields Map of field name => Qdrant schema type
     */
    private function ensurePayloadIndexes(string $collection, array $fields): void
    {
        foreach ($fields as $field => $type) {
            try {
                $this->createPayloadIndex($collection, $field, $type);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to ensure payload index', [
                    'collection' => $collection,
                    'field' => $field,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param list<array{id: string, vector: list<float>, payload: array<string, mixed>}> $points
     */
    private function upsertPoints(string $collection, array $points): void
    {
        $this->qdrantRequest('PUT', "/collections/{$collection}/points?wait=true", [
            'points' => $points,
        ]);
    }

    /**
     * Execute a list of operations in a single batch request.
     *
     * Used by upsertMemory/upsertDocument to sequence
     * `delete-by-payload-filter` and `upsert` inside one HTTP round-trip,
     * which closes the "crash between two wait=true calls leaves no point"
     * window that two separate calls would open.
     *
     * @param list<array<string, mixed>> $operations Qdrant batch operation descriptors
     */
    private function pointsBatch(string $collection, array $operations): void
    {
        $this->qdrantRequest('POST', "/collections/{$collection}/points/batch?wait=true", [
            'operations' => $operations,
        ]);
    }

    private function deleteDocumentsByFilter(int $userId, array $extraConditions): int
    {
        try {
            $must = [
                ['key' => 'user_id', 'match' => ['value' => $userId]],
                ...$extraConditions,
            ];
            $filter = ['must' => $must];

            $countResponse = $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/count", [
                'filter' => $filter,
            ]);
            $count = (int) ($countResponse['result']['count'] ?? 0);

            if (0 === $count) {
                return 0;
            }

            $this->qdrantRequest('POST', "/collections/{$this->documentsCollection}/points/delete?wait=true", [
                'filter' => $filter,
            ]);

            return $count;
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
            foreach ($points as $point) {
                $allPoints[] = $point;
            }

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
     * All failure modes (transport errors, non-2xx responses, non-JSON bodies)
     * are normalised to \RuntimeException so callers can use a single catch
     * clause to surface outages as 503 instead of leaking mixed exception
     * types (e.g. \JsonException, which extends \Exception directly and would
     * otherwise bypass `catch (\RuntimeException $e)` blocks upstream).
     *
     * @throws \RuntimeException on network errors, non-2xx responses, or
     *                           malformed JSON in the response body
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

        try {
            return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Qdrant returned a non-JSON response body [{$method} {$path}]: {$e->getMessage()}", 0, $e);
        }
    }
}
