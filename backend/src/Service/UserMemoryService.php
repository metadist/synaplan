<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\DTO\UserMemoryDTO;
use App\Entity\User;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing user memories.
 *
 * All memories stored in Qdrant microservice (no MariaDB).
 * Returns UserMemoryDTO for API responses.
 *
 * Note: Not final to allow mocking in tests
 */
readonly class UserMemoryService
{
    private const MAX_MEMORIES_PER_USER = 500;

    public function __construct(
        private EntityManagerInterface $em, // Only for Model entity (embedding config)
        private QdrantClientInterface $qdrantClient,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
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
     * Create a new memory in Qdrant.
     */
    public function createMemory(
        User $user,
        string $category,
        string $key,
        string $value,
        string $source = 'user_created',
        ?int $messageId = null,
    ): UserMemoryDTO {
        // Validate
        if (mb_strlen($key) < 3) {
            throw new \InvalidArgumentException('Memory key must be at least 3 characters');
        }
        if (mb_strlen($value) < 5) {
            throw new \InvalidArgumentException('Memory value must be at least 5 characters');
        }
        if (!in_array($source, ['auto_detected', 'user_created', 'user_edited', 'ai_edited'], true)) {
            throw new \InvalidArgumentException('Invalid source type');
        }

        // Check limit
        $existing = $this->getUserMemories($user->getId(), null);
        if (count($existing) >= self::MAX_MEMORIES_PER_USER) {
            throw new \InvalidArgumentException(sprintf('Memory limit reached (%d).', self::MAX_MEMORIES_PER_USER));
        }

        // Generate ID
        // Use a high-entropy numeric ID to avoid collisions under load.
        // JS frontend expects a number, so we keep it as int (fits into 64-bit).
        $timestampMs = (int) floor(microtime(true) * 1000);
        $memoryId = ($timestampMs * 1000) + random_int(0, 999);

        // Create DTO
        $dto = new UserMemoryDTO(
            id: $memoryId,
            userId: $user->getId(),
            category: $category,
            key: $key,
            value: $value,
            source: $source,
            messageId: $messageId,
        );

        // Store in Qdrant
        $pointId = $this->storeInQdrant($dto, $user, $memoryId);

        $this->logger->info('Memory created', [
            'memory_id' => $memoryId,
            'qdrant_id' => $pointId,
            'user_id' => $user->getId(),
        ]);

        return $dto;
    }

    /**
     * Update existing memory in Qdrant.
     */
    public function updateMemory(
        int $memoryId,
        User $user,
        string $value,
        string $source = 'user_edited',
        ?int $messageId = null,
    ): UserMemoryDTO {
        if (mb_strlen($value) < 5) {
            throw new \InvalidArgumentException('Memory value must be at least 5 characters');
        }
        if (!in_array($source, ['auto_detected', 'user_created', 'user_edited', 'ai_edited'], true)) {
            throw new \InvalidArgumentException('Invalid source type');
        }

        $pointId = "mem_{$user->getId()}_{$memoryId}";

        try {
            $existing = $this->qdrantClient->getMemory($pointId);
            if (!$existing) {
                throw new \InvalidArgumentException('Memory not found');
            }

            $dto = new UserMemoryDTO(
                id: $memoryId,
                userId: $user->getId(),
                category: $existing['category'] ?? 'personal',
                key: $existing['key'] ?? 'unknown',
                value: $value,
                source: $source,
                messageId: $messageId ?? ($existing['message_id'] ?? null),
                created: $existing['created'] ?? time(),
                updated: time(),
            );

            $this->storeInQdrant($dto, $user, $memoryId);

            $this->logger->info('Memory updated', ['memory_id' => $memoryId]);

            return $dto;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update memory', ['error' => $e->getMessage()]);
            throw new \InvalidArgumentException('Failed to update memory: '.$e->getMessage());
        }
    }

    /**
     * Delete memory from Qdrant.
     */
    public function deleteMemory(int $memoryId, User $user): void
    {
        if (!$this->qdrantClient->isAvailable()) {
            $this->logger->warning('Memory service unavailable - skipping delete', [
                'memory_id' => $memoryId,
                'user_id' => $user->getId(),
            ]);

            return;
        }

        $pointId = "mem_{$user->getId()}_{$memoryId}";

        try {
            $this->qdrantClient->deleteMemory($pointId);
            $this->logger->info('Memory deleted', ['memory_id' => $memoryId]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete memory', ['error' => $e->getMessage()]);
            throw new \InvalidArgumentException('Failed to delete memory: '.$e->getMessage());
        }
    }

    /**
     * Get all memories for user (from Qdrant).
     */
    public function getUserMemories(int $userId, ?string $category = null): array
    {
        try {
            // Use scroll API to retrieve all memories without vector search
            $results = $this->qdrantClient->scrollMemories($userId, $category, limit: 1000);

            $this->logger->debug('getUserMemories: scrollMemories returned', [
                'user_id' => $userId,
                'results_count' => count($results),
                'first_result' => $results[0] ?? null,
            ]);

            $memories = [];
            foreach ($results as $result) {
                $pointId = $result['id'] ?? null;
                $payload = $result['payload'] ?? null;

                if (!$pointId || !$payload) {
                    $this->logger->warning('getUserMemories: Skipping invalid result', [
                        'result' => $result,
                    ]);
                    continue;
                }

                // Extract memory ID from point ID (format: "mem_{userId}_{memoryId}")
                $parts = explode('_', $pointId);
                $memoryId = (int) ($parts[2] ?? 0);

                if (0 === $memoryId) {
                    $this->logger->warning('getUserMemories: Could not extract memoryId from pointId', [
                        'point_id' => $pointId,
                    ]);
                    continue;
                }

                $memories[] = new UserMemoryDTO(
                    id: $memoryId,
                    userId: $userId,
                    category: $payload['category'] ?? 'personal',
                    key: $payload['key'] ?? '',
                    value: $payload['value'] ?? '',
                    source: $payload['source'] ?? 'unknown',
                    messageId: $payload['message_id'] ?? null,
                    created: (int) ($payload['created'] ?? time()),
                    updated: (int) ($payload['updated'] ?? time()),
                );
            }

            $this->logger->info('getUserMemories: Processed memories', [
                'user_id' => $userId,
                'memories_count' => count($memories),
            ]);

            // Sort by updated date, newest first
            usort($memories, fn (UserMemoryDTO $a, UserMemoryDTO $b) => $b->updated <=> $a->updated);

            return $memories;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to retrieve user memories', [
                'userId' => $userId,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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
     * Search relevant memories by text similarity.
     */
    public function searchRelevantMemories(
        int $userId,
        string $queryText,
        ?string $category = null,
        int $limit = 5,
        float $minScore = 0.5,
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

            // Get embedding model (SAME as when storing!)
            $embeddingModelId = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);
            $modelName = null;
            $provider = null;

            if ($embeddingModelId) {
                $model = $this->em->getRepository('App\Entity\Model')->find($embeddingModelId);
                if ($model) {
                    $modelName = $model->getProviderId();
                    $provider = strtolower($model->getService());
                }
            }

            $this->logger->info('🎯 Using embedding model for search', [
                'userId' => $userId,
                'modelId' => $embeddingModelId,
                'modelName' => $modelName,
                'provider' => $provider,
            ]);

            // Create embedding for query (with EXPLICIT model!)
            $queryVector = $this->aiFacade->embed($queryText, $userId, array_filter([
                'model' => $modelName,
                'provider' => $provider,
            ]));

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

            // Search in Qdrant
            $results = $this->qdrantClient->searchMemories(
                $queryVector,
                $userId,
                $category,
                $limit,
                $minScore
            );

            $this->logger->info('🎯 Qdrant search results', [
                'userId' => $userId,
                'resultsCount' => count($results),
                'limit' => $limit,
                'minScore' => $minScore,
            ]);

            // Convert to arrays (format consumed by ChatHandler + frontend)
            $memories = [];
            foreach ($results as $result) {
                $memories[] = UserMemoryDTO::fromQdrantPayload(
                    $result['payload'],
                    $result['id']
                )->toArray();
            }

            return $memories;
        } catch (\Throwable $e) {
            $this->logger->error('Memory search failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Store memory in Qdrant with vectorization.
     */
    private function storeInQdrant(UserMemoryDTO $dto, User $user, int $memoryId): string
    {
        try {
            $textToEmbed = "{$dto->key}: {$dto->value}";

            // Get embedding model
            $embeddingModelId = $this->modelConfigService->getDefaultModel('VECTORIZE', $user->getId());
            $modelName = null;
            $provider = null;

            if ($embeddingModelId) {
                $model = $this->em->getRepository('App\Entity\Model')->find($embeddingModelId);
                if ($model) {
                    $modelName = $model->getProviderId();
                    $provider = strtolower($model->getService());
                }
            }

            // Create embedding
            $embedding = $this->aiFacade->embed($textToEmbed, $user->getId(), array_filter([
                'model' => $modelName,
                'provider' => $provider,
            ]));

            if (empty($embedding)) {
                throw new \RuntimeException('Failed to create embedding');
            }

            // Generate point ID
            $pointId = "mem_{$user->getId()}_{$memoryId}";

            // Store in Qdrant
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
                ]
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
}
