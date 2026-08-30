<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\DTO\UserMemoryDTO;
use App\Entity\User;
use App\Entity\UserMemory;
use App\Repository\UserMemoryRepository;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Memory\MemoryEmbeddingModelResolver;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing user memories.
 *
 * MariaDB is the authoritative store; Qdrant is a rebuildable vector index.
 * Returns UserMemoryDTO for API responses.
 *
 * Note: Not final to allow mocking in tests
 */
final readonly class UserMemoryService
{
    private const MAX_MEMORIES_PER_USER = 500;
    private const HIDDEN_CATEGORIES = [
        'feedback_negative',
        'feedback_positive',
        'feedback_false_positive',
    ];

    public function __construct(
        private EntityManagerInterface $em, // Only for Model entity (embedding config)
        private UserMemoryRepository $memoryRepository,
        private QdrantClientInterface $qdrantClient,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private EmbeddingMetadataService $embeddingMetadata,
        private MemoryEmbeddingModelResolver $memoryEmbeddingResolver,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the embedding model that owns the user-memories collection.
     *
     * Memories are intentionally pinned to their own embedding model,
     * independent of the active VECTORIZE default. Why: a VECTORIZE
     * switch with different dimensions does NOT migrate the memories
     * collection (PR #985 — preventing accidental data loss), so write
     * and read paths must keep using the model that created the
     * collection, otherwise:
     *   - storeInQdrant would emit a dimension mismatch and reject
     *     every new memory ("can't save anything after a switch")
     *   - searchMemories would query Qdrant with the wrong dim and
     *     either error or return zero hits ("memories lookup broken")
     *
     * The resolver caches lazily and self-heals if the operator
     * manually drops/recreates the collection.
     *
     * @return array{model_id: ?int, model_name: ?string, provider: ?string, vector_dim: ?int}
     */
    private function getMemoryEmbeddingConfig(): array
    {
        $info = $this->memoryEmbeddingResolver->resolve();

        return [
            'model_id' => $info['model_id'],
            'model_name' => $info['model'],
            'provider' => $info['provider'],
            'vector_dim' => $info['vector_dim'],
        ];
    }

    /**
     * Check if memory service is available.
     * Returns false if Qdrant is not configured or not reachable.
     */
    public function isAvailable(): bool
    {
        return $this->qdrantClient->isAvailable();
    }

    /**
     * Get the Qdrant client instance.
     * Used by ConfigController to fetch service info.
     */
    public function getQdrantClient(): QdrantClientInterface
    {
        return $this->qdrantClient;
    }

    /**
     * Create a memory in SQL, then update the derived Qdrant index.
     */
    public function createMemory(
        User $user,
        string $category,
        string $key,
        string $value,
        string $source = 'user_created',
        ?int $messageId = null,
        ?string $namespace = null,
    ): UserMemoryDTO {
        if (mb_strlen($key) < 3) {
            throw new \InvalidArgumentException('Memory key must be at least 3 characters');
        }
        if (mb_strlen($value) < 1) {
            throw new \InvalidArgumentException('Memory value must be at least 1 character');
        }
        if (!in_array($source, UserMemory::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid source type');
        }

        if ($this->memoryRepository->countActiveForUser($user->getId()) >= self::MAX_MEMORIES_PER_USER) {
            throw new \InvalidArgumentException(sprintf('Memory limit reached (%d).', self::MAX_MEMORIES_PER_USER));
        }

        $timestampMs = (int) floor(microtime(true) * 1000);
        $memoryId = ($timestampMs * 1000) + random_int(0, 999);
        $memory = new UserMemory(
            id: $memoryId,
            userId: $user->getId(),
            category: $category,
            key: $key,
            value: $value,
            source: $source,
            messageId: $messageId,
            namespace: $namespace,
        );
        $this->memoryRepository->save($memory);

        $this->indexMemoryBestEffort($memory, $user);

        $this->logger->info('Memory created', [
            'memory_id' => $memoryId,
            'user_id' => $user->getId(),
        ]);

        return $memory->toDTO();
    }

    /**
     * Update a memory in SQL, then update the derived Qdrant index.
     */
    public function updateMemory(
        int $memoryId,
        User $user,
        string $value,
        string $source = 'user_edited',
        ?int $messageId = null,
        ?string $key = null,
        ?string $category = null,
        ?string $namespace = null,
    ): UserMemoryDTO {
        if (mb_strlen($value) < 1) {
            throw new \InvalidArgumentException('Memory value must be at least 1 character');
        }
        if (!in_array($source, UserMemory::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid source type');
        }

        $memory = $this->memoryRepository->findForUser($memoryId, $user->getId());
        if (!$memory) {
            throw new \InvalidArgumentException('Memory not found');
        }

        $finalKey = $key ?? $memory->getKey();
        if (mb_strlen($finalKey) < 3) {
            throw new \InvalidArgumentException('Key must be at least 3 characters');
        }

        $memory->update(
            category: $category ?? $memory->getCategory(),
            key: $finalKey,
            value: $value,
            source: $source,
            messageId: $messageId ?? $memory->getMessageId(),
            namespace: $namespace ?? $memory->getNamespace(),
        );
        $this->memoryRepository->save($memory);
        $this->indexMemoryBestEffort($memory, $user);

        $this->logger->info('Memory updated', ['memory_id' => $memoryId]);

        return $memory->toDTO();
    }

    /**
     * Delete a memory from SQL and best-effort purge its vector index entry.
     */
    public function deleteMemory(int $memoryId, User $user, ?string $namespace = null): void
    {
        $memory = $this->memoryRepository->findForUser($memoryId, $user->getId());
        if (!$memory) {
            throw new \InvalidArgumentException('Memory not found');
        }

        $this->memoryRepository->remove($memory);
        $this->deleteIndexEntryBestEffort(
            "mem_{$user->getId()}_{$memoryId}",
            $namespace ?? $memory->getNamespace(),
        );
        $this->logger->info('Memory deleted', ['memory_id' => $memoryId]);
    }

    /**
     * Delete all memories for a given user (used during account deletion).
     */
    public function deleteAllForUser(int $userId): void
    {
        $deleted = $this->deleteAllFromSqlForUser($userId);
        $this->purgeIndexForUser($userId);

        $this->logger->info('All memories deleted for user', [
            'user_id' => $userId,
            'count' => $deleted,
        ]);
    }

    public function deleteAllFromSqlForUser(int $userId): int
    {
        return $this->memoryRepository->deleteAllForUser($userId);
    }

    public function purgeIndexForUser(int $userId): void
    {
        try {
            $this->qdrantClient->deleteAllMemoriesForUser($userId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete all memories for user', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Scroll through all memories for a user with optional category and namespace filters.
     *
     * @return array<array{id: int, key: string, value: string, category: string, messageId: ?int, created: int, updated: int}>
     */
    public function scrollMemories(
        int $userId,
        ?string $category = null,
        int $limit = 1000,
        ?string $namespace = null,
    ): array {
        return array_map(
            static fn (UserMemory $memory): array => $memory->toDTO()->toArray(),
            $this->memoryRepository->findActiveForUser($userId, $category, $namespace, $limit),
        );
    }

    /**
     * Fetch a single memory by id for a user.
     */
    public function getMemoryById(int $memoryId, User $user): ?UserMemoryDTO
    {
        return $this->memoryRepository->findForUser($memoryId, $user->getId())?->toDTO();
    }

    /**
     * Get all memories for a user from the authoritative SQL store.
     */
    public function getUserMemories(int $userId, ?string $category = null): array
    {
        $memories = $this->memoryRepository->findActiveForUser($userId, $category);

        return array_values(array_map(
            static fn (UserMemory $memory): UserMemoryDTO => $memory->toDTO(),
            array_filter(
                $memories,
                fn (UserMemory $memory): bool => !$this->isHiddenCategory($memory->getCategory()),
            ),
        ));
    }

    /**
     * Return every active SQL memory, including internal feedback categories,
     * for GDPR data portability.
     *
     * @return list<array<string, mixed>>
     */
    public function exportUserMemories(int $userId): array
    {
        return array_map(
            static fn (UserMemory $memory): array => $memory->toDTO()->toArray() + [
                'namespace' => $memory->getNamespace(),
            ],
            $this->memoryRepository->findActiveForUser($userId, limit: self::MAX_MEMORIES_PER_USER),
        );
    }

    /**
     * Search memories for API usage.
     *
     * Returns the same memory array format as used by ChatHandler (so frontend can consume it directly).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchMemories(User $user, string $query, ?string $category = null, int $limit = 5): array
    {
        // Reuse existing semantic search logic and keep response format stable (arrays).
        return $this->searchRelevantMemories(
            $user->getId(),
            $query,
            $category,
            $limit,
            0.5
        );
    }

    /**
     * Embed a query string for the user's default VECTORIZE model.
     *
     * Phase 1a: callers that want to fan out multiple searches against the
     * same user query (RAG + memories + 2x feedback in ChatHandler) can call
     * this once and pass the resulting `embedding` array to
     * {@see searchMemoriesByVector()} so the embedding HTTP round-trip is
     * paid exactly once instead of N times.
     *
     * Returns null if no VECTORIZE model is configured or embedding fails —
     * callers should fall back to skipping vector-based search rather than
     * crashing the request.
     *
     * @return array{embedding: array<int, float>, model_id: ?int, model_name: ?string, provider: ?string}|null
     */
    public function embedUserQuery(int $userId, string $queryText): ?array
    {
        return $this->embedQueryInternal(
            $userId,
            $queryText,
            $this->modelConfigService->getDefaultModel('VECTORIZE', $userId),
            'CHAT_PIPELINE_SHARED',
        );
    }

    /**
     * Embed `$queryText` against the *memory-pinned* embedding model
     * (sticky pointer, see {@see MemoryEmbeddingModelResolver}). Use
     * this — NOT {@see embedUserQuery()} — whenever the resulting
     * vector will be sent to Qdrant's memories collection: that
     * collection's dimension is decoupled from VECTORIZE so an active-
     * model query embedding would either be rejected or filtered out
     * by the stale-hit guard.
     *
     * Returns null on the same conditions as `embedUserQuery()`
     * (empty text, missing model, embed failure). Callers fall back to
     * skipping the vector search rather than crashing.
     *
     * @return array{embedding: array<int, float>, model_id: ?int, model_name: ?string, provider: ?string}|null
     */
    public function embedQueryForMemorySearch(int $userId, string $queryText): ?array
    {
        return $this->embedQueryInternal(
            $userId,
            $queryText,
            $this->memoryEmbeddingResolver->getModelId(),
            'CHAT_PIPELINE_MEMORY',
        );
    }

    /**
     * Returns the model id currently pinned to the user-memories
     * collection. Exposed publicly so callers in the request pipeline
     * (ChatHandler) can decide whether they may reuse an existing
     * VECTORIZE-embedded vector for memory search instead of paying
     * for a second embed call.
     */
    public function getMemoryEmbeddingModelId(): ?int
    {
        return $this->memoryEmbeddingResolver->getModelId();
    }

    /**
     * @return array{embedding: array<int, float>, model_id: ?int, model_name: ?string, provider: ?string}|null
     */
    private function embedQueryInternal(
        int $userId,
        string $queryText,
        ?int $embeddingModelId,
        string $usageSource,
    ): ?array {
        if ('' === trim($queryText)) {
            return null;
        }

        $modelName = null;
        $provider = null;

        if ($embeddingModelId) {
            $model = $this->em->getRepository('App\Entity\Model')->find($embeddingModelId);
            if ($model) {
                $modelName = $model->getProviderId();
                $provider = strtolower($model->getService());
            }
        }

        try {
            $embedResult = $this->aiFacade->embed($queryText, $userId, array_filter([
                'model' => $modelName,
                'provider' => $provider,
            ]));
        } catch (\Throwable $e) {
            $this->logger->warning('embedQueryInternal failed, falling back to no embedding', [
                'user_id' => $userId,
                'source' => $usageSource,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $embedding = $embedResult['embedding'];
        if (empty($embedding)) {
            return null;
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if ($user) {
            $this->rateLimitService->recordUsage($user, 'EMBEDDINGS', [
                'usage' => $embedResult['usage'],
                'provider' => $provider ?? 'unknown',
                'model' => $modelName ?? 'unknown',
                'model_id' => $embeddingModelId,
                'input_text' => $queryText,
                'source' => $usageSource,
            ]);
        }

        return [
            'embedding' => array_map('floatval', $embedding),
            'model_id' => $embeddingModelId,
            'model_name' => $modelName,
            'provider' => $provider,
        ];
    }

    /**
     * Search memories using a precomputed vector.
     *
     * Skips the embedding step — pair with {@see embedUserQuery()} to fan out
     * multiple searches off a single embedding.
     *
     * @param array<int, float> $queryVector
     */
    public function searchMemoriesByVector(
        int $userId,
        array $queryVector,
        ?string $category = null,
        int $limit = 5,
        float $minScore = 0.5,
        ?string $namespace = null,
        bool $includeHidden = false,
    ): array {
        if (empty($queryVector)) {
            return [];
        }

        try {
            $results = $this->qdrantClient->searchMemories(
                $queryVector,
                $userId,
                $category,
                $limit * 2,
                $minScore,
                $namespace
            );

            // Compare against the memory-pinned model id, not VECTORIZE.
            // See `searchRelevantMemories()` for the full rationale.
            $filtered = $this->embeddingMetadata->filterStaleHits(
                $results,
                'payload',
                $this->memoryEmbeddingResolver->getModelId()
            );
            $results = array_slice($filtered['fresh'], 0, $limit);

            $memories = [];
            foreach ($results as $result) {
                $payload = $result['payload'] ?? [];
                $resultCategory = $payload['category'] ?? null;
                if (!$includeHidden && $resultCategory && $this->isHiddenCategory($resultCategory)) {
                    continue;
                }

                $memory = UserMemoryDTO::fromQdrantPayload($payload, $result['id'])->toArray();
                $memory['score'] = (float) ($result['score'] ?? 0.0);
                $memories[] = $memory;
            }

            return $includeHidden ? $memories : $this->reconcileWithSqlCatalog($userId, $memories);
        } catch (\Throwable $e) {
            $this->logger->error('searchMemoriesByVector failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Search relevant memories by text similarity.
     *
     * @param string|null $namespace Optional namespace filter (e.g., 'feedback_false_positive', 'feedback_positive')
     */
    public function searchRelevantMemories(
        int $userId,
        string $queryText,
        ?string $category = null,
        int $limit = 5,
        float $minScore = 0.5,
        ?string $namespace = null,
        bool $includeHidden = false,
    ): array {
        // If no query text provided, we can't do semantic search
        // This happens when getUserMemories is called
        if (empty($queryText)) {
            $this->logger->warning('Empty query text provided for memory search', [
                'userId' => $userId,
            ]);

            // Return empty for now - proper implementation would need Qdrant scroll API
            return [];
        }

        try {
            $this->logger->info('🔍 searchRelevantMemories called', [
                'userId' => $userId,
                'queryText' => substr($queryText, 0, 100),
                'limit' => $limit,
                'minScore' => $minScore,
            ]);

            // Resolve the memory-pinned embedding model (SAME as when
            // storing — must match the dimension of the memories
            // collection, not the active VECTORIZE default).
            $memoryConfig = $this->getMemoryEmbeddingConfig();
            $embeddingModelId = $memoryConfig['model_id'];
            $modelName = $memoryConfig['model_name'];
            $provider = $memoryConfig['provider'];

            $this->logger->info('🎯 Using embedding model for search', [
                'userId' => $userId,
                'modelId' => $embeddingModelId,
                'modelName' => $modelName,
                'provider' => $provider,
            ]);

            $embedResult = $this->aiFacade->embed($queryText, $userId, array_filter([
                'model' => $modelName,
                'provider' => $provider,
            ]));
            $queryVector = $embedResult['embedding'];

            $user = $this->em->getRepository(User::class)->find($userId);
            if ($user) {
                $this->rateLimitService->recordUsage($user, 'EMBEDDINGS', [
                    'usage' => $embedResult['usage'],
                    'provider' => $provider ?? 'unknown',
                    'model' => $modelName ?? 'unknown',
                    'model_id' => $embeddingModelId,
                    'input_text' => $queryText,
                    'source' => 'MEMORY_SEARCH',
                ]);
            }

            $this->logger->info('📊 Embedding created', [
                'userId' => $userId,
                'vectorLength' => count($queryVector),
                'isEmpty' => empty($queryVector),
            ]);

            if (empty($queryVector)) {
                $this->logger->warning('Empty vector returned from embedding', [
                    'userId' => $userId,
                    'queryText' => substr($queryText, 0, 100),
                ]);

                return [];
            }

            // Search in Qdrant. Ask for 2x to leave room for the stale-
            // filter pass below — see QdrantVectorStorage::search() for
            // the rationale.
            $results = $this->qdrantClient->searchMemories(
                $queryVector,
                $userId,
                $category,
                $limit * 2,
                $minScore,
                $namespace
            );

            // Filter out hits embedded with a different model than the
            // memory-pinned one. Cross-model cosine scores are
            // physically meaningless — including them would silently
            // corrupt the assistant's answers right after a model swap.
            //
            // Important: we compare against the *memory-pinned* model
            // (sticky pointer), NOT VECTORIZE. After a VECTORIZE swap
            // that left memories untouched, every memory still looks
            // "fresh" relative to its own collection — using VECTORIZE
            // here would mark them all stale and return zero hits.
            $filtered = $this->embeddingMetadata->filterStaleHits(
                $results,
                'payload',
                $embeddingModelId
            );
            if ($filtered['stale_count'] > 0) {
                $this->logger->info('🧪 Filtered stale memory hits', [
                    'userId' => $userId,
                    'stale_count' => $filtered['stale_count'],
                    'fresh_count' => count($filtered['fresh']),
                    'memory_model_id' => $embeddingModelId,
                ]);
            }
            $results = array_slice($filtered['fresh'], 0, $limit);

            $this->logger->info('🎯 Qdrant search results', [
                'userId' => $userId,
                'resultsCount' => count($results),
                'limit' => $limit,
                'minScore' => $minScore,
            ]);

            // Convert to arrays (format consumed by ChatHandler + frontend)
            $memories = [];
            foreach ($results as $result) {
                $payload = $result['payload'] ?? [];
                $category = $payload['category'] ?? null;
                // Skip hidden categories unless explicitly included (e.g., for feedback search)
                if (!$includeHidden && $category && $this->isHiddenCategory($category)) {
                    continue;
                }

                $memory = UserMemoryDTO::fromQdrantPayload($payload, $result['id'])->toArray();
                $memory['score'] = (float) ($result['score'] ?? 0.0);
                $memories[] = $memory;
            }

            return $includeHidden ? $memories : $this->reconcileWithSqlCatalog($userId, $memories);
        } catch (\Throwable $e) {
            $this->logger->error('Memory search failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Drop retrieval hits that have no active SQL row (#1570).
     *
     * The Qdrant `user_memories` index and the BUSERMEMORIES catalog can
     * diverge: a point can outlive its SQL row when rows are removed/reset
     * without a successful Qdrant purge. Such orphans were still returned and
     * used in chat replies, yet the Memories list/dialog — which reads SQL —
     * never showed them, so they were invisible and unmanageable. SQL is the
     * source of truth for what the user can see and manage, so a hit that is
     * not an active SQL row must not be used in a reply either. This keeps the
     * "X memories used" line consistent with the Memories UI.
     *
     * Only applied to the user-facing memory load; hidden feedback namespaces
     * (searched with includeHidden=true) are internal and intentionally not
     * surfaced in the list, so they are left untouched.
     *
     * @param list<array<string, mixed>> $memories
     *
     * @return list<array<string, mixed>>
     */
    private function reconcileWithSqlCatalog(int $userId, array $memories): array
    {
        if ([] === $memories) {
            return $memories;
        }

        $ids = [];
        foreach ($memories as $memory) {
            $id = $memory['id'] ?? null;
            if (is_int($id)) {
                $ids[] = $id;
            }
        }

        $activeIds = array_flip($this->memoryRepository->filterActiveIds($userId, $ids));

        $reconciled = [];
        foreach ($memories as $memory) {
            $id = $memory['id'] ?? null;
            if (is_int($id) && isset($activeIds[$id])) {
                $reconciled[] = $memory;
            }
        }

        $dropped = count($memories) - count($reconciled);
        if ($dropped > 0) {
            $this->logger->info('Dropped orphaned Qdrant memory hits without an active SQL row', [
                'user_id' => $userId,
                'dropped' => $dropped,
                'kept' => count($reconciled),
            ]);
        }

        return $reconciled;
    }

    /**
     * Store memory in Qdrant with vectorization.
     */
    private function indexMemoryBestEffort(UserMemory $memory, User $user): void
    {
        if (!$this->qdrantClient->isAvailable()) {
            $this->logger->warning('Memory saved in SQL but Qdrant index is unavailable', [
                'memory_id' => $memory->getId(),
                'user_id' => $user->getId(),
            ]);

            return;
        }

        try {
            $this->storeInQdrant(
                $memory->toDTO(),
                $user,
                $memory->getId(),
                $memory->getNamespace(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Memory saved in SQL but Qdrant indexing failed', [
                'memory_id' => $memory->getId(),
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteIndexEntryBestEffort(string $pointId, ?string $namespace): void
    {
        if (!$this->qdrantClient->isAvailable()) {
            $this->logger->warning('Memory deleted from SQL but Qdrant index is unavailable', [
                'point_id' => $pointId,
            ]);

            return;
        }

        try {
            $this->qdrantClient->deleteMemory($pointId, $namespace);
        } catch (\Throwable $e) {
            $this->logger->error('Memory deleted from SQL but Qdrant index cleanup failed', [
                'point_id' => $pointId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function storeInQdrant(UserMemoryDTO $dto, User $user, int $memoryId, ?string $namespace = null): string
    {
        try {
            $textToEmbed = "{$dto->key}: {$dto->value}";

            // Resolve the *memory-pinned* embedding model — NOT the
            // active VECTORIZE default. Why this distinction matters:
            // PR #985 froze the memories collection across VECTORIZE
            // switches to avoid data loss, so if we embedded with
            // VECTORIZE here the resulting vector would have the wrong
            // dimension and the safety net below would reject every
            // new memory. The resolver returns the model that owns
            // the current memories collection (sticky pointer in
            // BCONFIG, payload inference, or VECTORIZE fallback for
            // a brand-new install).
            $memoryConfig = $this->getMemoryEmbeddingConfig();
            $embeddingModelId = $memoryConfig['model_id'];
            $modelName = $memoryConfig['model_name'];
            $provider = $memoryConfig['provider'];

            $embedResult = $this->aiFacade->embed($textToEmbed, $user->getId(), array_filter([
                'model' => $modelName,
                'provider' => $provider,
            ]));
            $embedding = $embedResult['embedding'];

            if (empty($embedding)) {
                throw new \RuntimeException('Failed to create embedding');
            }

            // Dimension safety net (#948, #959).
            //
            // Compare the embedding's actual dimension against the Qdrant
            // collection's configured vector size — NOT the model's
            // self-reported dim, which always matches its own output and
            // therefore never detects the real mismatch (model vs collection).
            $collectionInfo = $this->qdrantClient->getMemoriesCollectionInfo();
            $collectionDim = $collectionInfo['vector_dim'];
            $actualDim = count($embedding);
            if (null !== $collectionDim && $actualDim !== $collectionDim) {
                $this->logger->error('Memory storage rejected — embedding dimension mismatches collection', [
                    'user_id' => $user->getId(),
                    'memory_id' => $memoryId,
                    'collection_dim' => $collectionDim,
                    'actual_dim' => $actualDim,
                    'model_id' => $embeddingModelId,
                    'provider' => $provider,
                    'model' => $modelName,
                ]);

                throw new \RuntimeException(sprintf('Embedding dimension mismatch: model produced %d-dim vectors but the collection expects %d. The administrator must re-run the vector re-index for the active model.', $actualDim, $collectionDim));
            }

            $this->rateLimitService->recordUsage($user, 'EMBEDDINGS', [
                'usage' => $embedResult['usage'],
                'provider' => $provider ?? 'unknown',
                'model' => $modelName ?? 'unknown',
                'model_id' => $embeddingModelId,
                'input_text' => $textToEmbed,
                'source' => 'MEMORY_STORE',
            ]);

            // Generate point ID
            $pointId = "mem_{$user->getId()}_{$memoryId}";

            // Store in Qdrant. Embedding-stack metadata (model id/provider/
            // name + vector dim + indexed_at) is written alongside the
            // semantic payload so SafeModelChange can:
            //   1. detect stale memories when the active VECTORIZE model
            //      changes (different model_id or vector_dim → trigger
            //      re-vectorize);
            //   2. surface in the admin UI which model produced which
            //      memories.
            $this->qdrantClient->upsertMemory(
                $pointId,
                $embedding,
                [
                    'user_id' => $dto->userId,
                    'category' => $dto->category,
                    'key' => $dto->key,
                    'value' => $dto->value,
                    'source' => $dto->source,
                    'message_id' => $dto->messageId,
                    'created' => $dto->created,
                    'updated' => $dto->updated,
                    'active' => $dto->active,
                    'embedding_model_id' => $embeddingModelId,
                    'embedding_provider' => $provider,
                    'embedding_model' => $modelName,
                    'vector_dim' => count($embedding),
                    'indexed_at' => date(\DATE_ATOM),
                ],
                $namespace
            );

            return $pointId;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store in Qdrant', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get categories with memory counts for a user.
     * Returns array of ['category' => string, 'count' => int].
     */
    public function getCategoriesWithCounts(User $user): array
    {
        try {
            // Get all memories for the user
            $memories = $this->getUserMemories($user->getId());

            // Count by category
            $categories = [];
            foreach ($memories as $memory) {
                $category = $memory->category;
                if (!isset($categories[$category])) {
                    $categories[$category] = 0;
                }
                ++$categories[$category];
            }

            // Convert to array format
            $result = [];
            foreach ($categories as $category => $count) {
                $result[] = [
                    'category' => $category,
                    'count' => $count,
                ];
            }

            // Sort by count descending
            usort($result, fn ($a, $b) => $b['count'] <=> $a['count']);

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get categories with counts', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Replace [Memory:ID] tags in text with their resolved values.
     *
     * Used before forwarding AI responses to external channels (WhatsApp, Email)
     * where the frontend badge renderer is not available.
     * Unresolvable tags are silently removed so external users never see raw IDs.
     *
     * Unique IDs are resolved once and cached within the call to avoid N+1 lookups.
     */
    public function resolveMemoryTags(string $text, User $user): string
    {
        if (!str_contains($text, '[Memory:')) {
            return $text;
        }

        // Extract all unique memory IDs to avoid repeated Qdrant lookups
        preg_match_all('/\[Memory\s*:\s*(\d+)\.{0,3}\]/i', $text, $allMatches);
        $uniqueIds = array_unique(array_map('intval', $allMatches[1]));

        /** @var array<int, string> */
        $resolved = [];
        foreach ($uniqueIds as $memoryId) {
            $memory = $this->getMemoryById($memoryId, $user);
            if ($memory) {
                $resolved[$memoryId] = $memory->value;
            } else {
                $this->logger->debug('Memory tag could not be resolved, removing', [
                    'memory_id' => $memoryId,
                    'user_id' => $user->getId(),
                ]);
                $resolved[$memoryId] = '';
            }
        }

        return (string) preg_replace_callback(
            '/\[Memory\s*:\s*(\d+)\.{0,3}\]/i',
            fn (array $matches): string => $resolved[(int) $matches[1]] ?? '',
            $text
        );
    }

    private function isHiddenCategory(string $category): bool
    {
        return in_array($category, self::HIDDEN_CATEGORIES, true);
    }
}
