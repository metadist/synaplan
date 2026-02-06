# 02 - Backend Abstraction Layer

## Overview

Create a clean abstraction layer using the Facade pattern to support multiple vector storage backends.

---

## Directory Structure

```
backend/src/Service/RAG/VectorStorage/
├── VectorStorageInterface.php      # Contract for all implementations
├── VectorStorageFacade.php         # Facade that delegates to active provider
├── VectorStorageConfig.php         # Configuration (from 01-CONFIGURATION.md)
├── MariaDBVectorStorage.php        # Existing MariaDB implementation
├── QdrantVectorStorage.php         # New Qdrant implementation
├── DTO/
│   ├── VectorChunk.php             # Input DTO for storing chunks
│   ├── SearchQuery.php             # Input DTO for search
│   ├── SearchResult.php            # Output DTO for search results
│   └── StorageStats.php            # Stats DTO
└── Exception/
    ├── VectorStorageException.php  # Base exception
    └── ProviderUnavailableException.php
```

---

## Interface Definition

```php
<?php
// backend/src/Service/RAG/VectorStorage/VectorStorageInterface.php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;

interface VectorStorageInterface
{
    /**
     * Store a single chunk with its vector embedding.
     *
     * @param VectorChunk $chunk The chunk data including vector
     * @return string The stored chunk ID
     */
    public function storeChunk(VectorChunk $chunk): string;

    /**
     * Store multiple chunks in a single batch operation.
     *
     * @param VectorChunk[] $chunks Array of chunks to store
     * @return int Number of chunks successfully stored
     */
    public function storeChunkBatch(array $chunks): int;

    /**
     * Delete all chunks for a specific file.
     *
     * @param int $userId User ID (required for isolation)
     * @param int $fileId File/Message ID
     * @return int Number of chunks deleted
     */
    public function deleteByFile(int $userId, int $fileId): int;

    /**
     * Delete all chunks for a specific group key.
     *
     * @param int $userId User ID (required for isolation)
     * @param string $groupKey Group key (e.g., "WIDGET:xxx")
     * @return int Number of chunks deleted
     */
    public function deleteByGroupKey(int $userId, string $groupKey): int;

    /**
     * Delete all chunks for a user (for account deletion).
     *
     * @param int $userId User ID
     * @return int Number of chunks deleted
     */
    public function deleteAllForUser(int $userId): int;

    /**
     * Perform semantic search.
     *
     * @param SearchQuery $query Search parameters including vector
     * @return SearchResult[] Array of matching results
     */
    public function search(SearchQuery $query): array;

    /**
     * Find chunks similar to a source chunk.
     *
     * @param int $userId User ID
     * @param int $sourceChunkId ID of the source chunk
     * @param int $limit Max results
     * @param float $minScore Minimum similarity score
     * @return SearchResult[]
     */
    public function findSimilar(int $userId, int $sourceChunkId, int $limit = 10, float $minScore = 0.3): array;

    /**
     * Get storage statistics for a user.
     *
     * @param int $userId User ID
     * @return StorageStats
     */
    public function getStats(int $userId): StorageStats;

    /**
     * Get distinct group keys for a user.
     *
     * @param int $userId User ID
     * @return string[] Array of group keys
     */
    public function getGroupKeys(int $userId): array;

    /**
     * Update group key for all chunks of a file.
     *
     * @param int $userId User ID
     * @param int $fileId File ID
     * @param string $newGroupKey New group key
     * @return int Number of chunks updated
     */
    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int;

    /**
     * Check if the storage backend is available.
     *
     * @return bool True if backend is operational
     */
    public function isAvailable(): bool;

    /**
     * Get the provider name (for logging/debugging).
     *
     * @return string Provider identifier (e.g., 'mariadb', 'qdrant')
     */
    public function getProviderName(): string;
}
```

---

## Data Transfer Objects (DTOs)

```php
<?php
// backend/src/Service/RAG/VectorStorage/DTO/VectorChunk.php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

final readonly class VectorChunk
{
    public function __construct(
        public int $userId,
        public int $fileId,
        public string $groupKey,
        public int $fileType,
        public int $chunkIndex,
        public int $startLine,
        public int $endLine,
        public string $text,
        /** @var float[] */
        public array $vector,
        public ?int $createdAt = null,
    ) {
        $this->createdAt ??= time();
    }

    /**
     * Generate a unique point ID for Qdrant.
     */
    public function getPointId(): string
    {
        return sprintf('doc_%d_%d_%d', $this->userId, $this->fileId, $this->chunkIndex);
    }
}
```

```php
<?php
// backend/src/Service/RAG/VectorStorage/DTO/SearchQuery.php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

final readonly class SearchQuery
{
    public function __construct(
        public int $userId,
        /** @var float[] */
        public array $vector,
        public ?string $groupKey = null,
        public int $limit = 10,
        public float $minScore = 0.3,
    ) {}
}
```

```php
<?php
// backend/src/Service/RAG/VectorStorage/DTO/SearchResult.php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

final readonly class SearchResult
{
    public function __construct(
        public int|string $chunkId,
        public int $fileId,
        public string $groupKey,
        public string $text,
        public float $score,
        public int $startLine,
        public int $endLine,
        public ?string $fileName = null,
        public ?string $mimeType = null,
    ) {}
}
```

```php
<?php
// backend/src/Service/RAG/VectorStorage/DTO/StorageStats.php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

final readonly class StorageStats
{
    public function __construct(
        public int $totalChunks,
        public int $totalFiles,
        public int $totalGroups,
        /** @var array<string, int> */
        public array $chunksByGroup = [],
    ) {}
}
```

---

## Facade Implementation

```php
<?php
// backend/src/Service/RAG/VectorStorage/VectorStorageFacade.php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
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
    ) {}

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

            if ($provider !== 'mariadb' && $this->mariaDbStorage->isAvailable()) {
                return $this->mariaDbStorage;
            }

            throw new ProviderUnavailableException(
                sprintf('Vector storage provider "%s" is not available', $provider)
            );
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

    public function isAvailable(): bool
    {
        return $this->getActiveStorage()->isAvailable();
    }

    public function getProviderName(): string
    {
        return $this->getActiveStorage()->getProviderName();
    }

    /**
     * Get the currently configured provider name (for status checks).
     */
    public function getConfiguredProvider(): string
    {
        return $this->config->getProvider();
    }
}
```

---

## MariaDB Implementation

Extract existing logic from `VectorizationService` and `VectorSearchService`:

```php
<?php
// backend/src/Service/RAG/VectorStorage/MariaDBVectorStorage.php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Entity\RagDocument;
use App\Repository\RagDocumentRepository;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MariaDBVectorStorage implements VectorStorageInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private RagDocumentRepository $ragRepository,
        private Connection $connection,
    ) {}

    public function storeChunk(VectorChunk $chunk): string
    {
        // Use native SQL for vector storage (Doctrine doesn't handle VECTOR type well)
        $sql = <<<'SQL'
            INSERT INTO BRAG (BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BTEXT, BEMBED, BCREATED)
            VALUES (:userId, :fileId, :groupKey, :fileType, :startLine, :endLine, :text, VEC_FromText(:vector), :created)
        SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $chunk->userId);
        $stmt->bindValue('fileId', $chunk->fileId);
        $stmt->bindValue('groupKey', $chunk->groupKey);
        $stmt->bindValue('fileType', $chunk->fileType);
        $stmt->bindValue('startLine', $chunk->startLine);
        $stmt->bindValue('endLine', $chunk->endLine);
        $stmt->bindValue('text', $chunk->text);
        $stmt->bindValue('vector', '['.implode(',', $chunk->vector).']');
        $stmt->bindValue('created', $chunk->createdAt);
        $stmt->executeStatement();

        return (string) $this->connection->lastInsertId();
    }

    public function storeChunkBatch(array $chunks): int
    {
        $stored = 0;
        foreach ($chunks as $chunk) {
            $this->storeChunk($chunk);
            $stored++;
        }
        return $stored;
    }

    public function deleteByFile(int $userId, int $fileId): int
    {
        return $this->ragRepository->deleteByMessageId($fileId);
    }

    public function deleteByGroupKey(int $userId, string $groupKey): int
    {
        return $this->ragRepository->deleteByGroupKey($groupKey);
    }

    public function deleteAllForUser(int $userId): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->delete(RagDocument::class, 'r')
            ->where('r.userId = :userId')
            ->setParameter('userId', $userId);

        return $qb->getQuery()->execute();
    }

    public function search(SearchQuery $query): array
    {
        $vectorStr = '['.implode(',', $query->vector).']';
        $maxDistance = 1.0 - $query->minScore;

        $sql = <<<'SQL'
            SELECT
                r.BID as chunk_id,
                r.BMID as file_id,
                r.BGROUPKEY as group_key,
                r.BTEXT as text,
                r.BSTART as start_line,
                r.BEND as end_line,
                VEC_DISTANCE_COSINE(r.BEMBED, VEC_FromText(:vector)) as distance,
                f.BFILENAME as file_name,
                f.BMIMETYPE as mime_type
            FROM BRAG r
            LEFT JOIN BFILES f ON r.BMID = f.BID
            WHERE r.BUID = :userId
        SQL;

        if ($query->groupKey !== null) {
            $sql .= ' AND r.BGROUPKEY = :groupKey';
        }

        $sql .= <<<'SQL'
            HAVING distance <= :maxDistance
            ORDER BY distance ASC
            LIMIT :limit
        SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('vector', $vectorStr);
        $stmt->bindValue('userId', $query->userId);
        $stmt->bindValue('maxDistance', $maxDistance);
        $stmt->bindValue('limit', $query->limit, \PDO::PARAM_INT);

        if ($query->groupKey !== null) {
            $stmt->bindValue('groupKey', $query->groupKey);
        }

        $results = $stmt->executeQuery()->fetchAllAssociative();

        return array_map(
            fn (array $row) => new SearchResult(
                chunkId: (int) $row['chunk_id'],
                fileId: (int) $row['file_id'],
                groupKey: $row['group_key'],
                text: $row['text'],
                score: 1.0 - (float) $row['distance'],
                startLine: (int) $row['start_line'],
                endLine: (int) $row['end_line'],
                fileName: $row['file_name'],
                mimeType: $row['mime_type'],
            ),
            $results
        );
    }

    public function findSimilar(int $userId, int $sourceChunkId, int $limit = 10, float $minScore = 0.3): array
    {
        // Get source chunk's vector first
        $sourceDoc = $this->ragRepository->find($sourceChunkId);
        if (!$sourceDoc) {
            return [];
        }

        // Use existing search with source vector
        $query = new SearchQuery(
            userId: $userId,
            vector: $sourceDoc->getEmbed(),
            groupKey: null, // Search across all groups
            limit: $limit + 1, // +1 to exclude self
            minScore: $minScore,
        );

        $results = $this->search($query);

        // Filter out the source chunk itself
        return array_filter(
            $results,
            fn (SearchResult $r) => $r->chunkId !== $sourceChunkId
        );
    }

    public function getStats(int $userId): StorageStats
    {
        $sql = <<<'SQL'
            SELECT
                COUNT(*) as total_chunks,
                COUNT(DISTINCT BMID) as total_files,
                COUNT(DISTINCT BGROUPKEY) as total_groups
            FROM BRAG
            WHERE BUID = :userId
        SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $userId);
        $result = $stmt->executeQuery()->fetchAssociative();

        // Get chunks by group
        $groupSql = <<<'SQL'
            SELECT BGROUPKEY, COUNT(*) as count
            FROM BRAG
            WHERE BUID = :userId
            GROUP BY BGROUPKEY
        SQL;

        $groupStmt = $this->connection->prepare($groupSql);
        $groupStmt->bindValue('userId', $userId);
        $groupResults = $groupStmt->executeQuery()->fetchAllAssociative();

        $chunksByGroup = [];
        foreach ($groupResults as $row) {
            $chunksByGroup[$row['BGROUPKEY']] = (int) $row['count'];
        }

        return new StorageStats(
            totalChunks: (int) $result['total_chunks'],
            totalFiles: (int) $result['total_files'],
            totalGroups: (int) $result['total_groups'],
            chunksByGroup: $chunksByGroup,
        );
    }

    public function getGroupKeys(int $userId): array
    {
        return $this->ragRepository->findDistinctGroupKeysByUser($userId);
    }

    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        $sql = <<<'SQL'
            UPDATE BRAG
            SET BGROUPKEY = :groupKey
            WHERE BUID = :userId AND BMID = :fileId
        SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('groupKey', $newGroupKey);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('fileId', $fileId);

        return $stmt->executeStatement();
    }

    public function isAvailable(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'mariadb';
    }
}
```

---

## Qdrant Implementation

```php
<?php
// backend/src/Service/RAG/VectorStorage/QdrantVectorStorage.php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\QdrantClientHttp;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use Psr\Log\LoggerInterface;

final readonly class QdrantVectorStorage implements VectorStorageInterface
{
    public function __construct(
        private QdrantClientHttp $qdrantClient,
        private VectorStorageConfig $config,
        private LoggerInterface $logger,
    ) {}

    private function getCollection(): string
    {
        return $this->config->getQdrantDocumentsCollection();
    }

    public function storeChunk(VectorChunk $chunk): string
    {
        $pointId = $chunk->getPointId();

        $payload = [
            'user_id' => $chunk->userId,
            'file_id' => $chunk->fileId,
            'group_key' => $chunk->groupKey,
            'file_type' => $chunk->fileType,
            'chunk_index' => $chunk->chunkIndex,
            'start_line' => $chunk->startLine,
            'end_line' => $chunk->endLine,
            'text' => $chunk->text,
            'created' => $chunk->createdAt,
        ];

        $this->qdrantClient->upsertDocument($pointId, $chunk->vector, $payload);

        return $pointId;
    }

    public function storeChunkBatch(array $chunks): int
    {
        if (empty($chunks)) {
            return 0;
        }

        $points = array_map(fn (VectorChunk $chunk) => [
            'point_id' => $chunk->getPointId(),
            'vector' => $chunk->vector,
            'payload' => [
                'user_id' => $chunk->userId,
                'file_id' => $chunk->fileId,
                'group_key' => $chunk->groupKey,
                'file_type' => $chunk->fileType,
                'chunk_index' => $chunk->chunkIndex,
                'start_line' => $chunk->startLine,
                'end_line' => $chunk->endLine,
                'text' => $chunk->text,
                'created' => $chunk->createdAt,
            ],
        ], $chunks);

        $result = $this->qdrantClient->batchUpsertDocuments($points);

        return $result['success_count'] ?? count($chunks);
    }

    public function deleteByFile(int $userId, int $fileId): int
    {
        return $this->qdrantClient->deleteDocumentsByFile($userId, $fileId);
    }

    public function deleteByGroupKey(int $userId, string $groupKey): int
    {
        return $this->qdrantClient->deleteDocumentsByGroupKey($userId, $groupKey);
    }

    public function deleteAllForUser(int $userId): int
    {
        return $this->qdrantClient->deleteAllDocumentsForUser($userId);
    }

    public function search(SearchQuery $query): array
    {
        $results = $this->qdrantClient->searchDocuments(
            vector: $query->vector,
            userId: $query->userId,
            groupKey: $query->groupKey,
            limit: $query->limit,
            minScore: $query->minScore,
        );

        return array_map(
            fn (array $result) => new SearchResult(
                chunkId: $result['id'],
                fileId: (int) $result['payload']['file_id'],
                groupKey: $result['payload']['group_key'],
                text: $result['payload']['text'],
                score: (float) $result['score'],
                startLine: (int) $result['payload']['start_line'],
                endLine: (int) $result['payload']['end_line'],
                fileName: $result['payload']['file_name'] ?? null,
                mimeType: $result['payload']['mime_type'] ?? null,
            ),
            $results
        );
    }

    public function findSimilar(int $userId, int $sourceChunkId, int $limit = 10, float $minScore = 0.3): array
    {
        // Get source chunk first
        $source = $this->qdrantClient->getDocument($sourceChunkId);
        if (!$source) {
            return [];
        }

        // Search with source vector
        $query = new SearchQuery(
            userId: $userId,
            vector: $source['vector'],
            groupKey: null,
            limit: $limit + 1,
            minScore: $minScore,
        );

        $results = $this->search($query);

        // Filter out source
        return array_filter(
            $results,
            fn (SearchResult $r) => $r->chunkId !== $sourceChunkId
        );
    }

    public function getStats(int $userId): StorageStats
    {
        $stats = $this->qdrantClient->getDocumentStats($userId);

        return new StorageStats(
            totalChunks: $stats['total_chunks'] ?? 0,
            totalFiles: $stats['total_files'] ?? 0,
            totalGroups: $stats['total_groups'] ?? 0,
            chunksByGroup: $stats['chunks_by_group'] ?? [],
        );
    }

    public function getGroupKeys(int $userId): array
    {
        return $this->qdrantClient->getDocumentGroupKeys($userId);
    }

    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        return $this->qdrantClient->updateDocumentGroupKey($userId, $fileId, $newGroupKey);
    }

    public function isAvailable(): bool
    {
        return $this->qdrantClient->isAvailable();
    }

    public function getProviderName(): string
    {
        return 'qdrant';
    }
}
```

---

## Refactoring VectorizationService

The existing `VectorizationService` should be refactored to use `VectorStorageFacade`:

```php
<?php
// backend/src/Service/File/VectorizationService.php (REFACTORED)

declare(strict_types=1);

namespace App\Service\File;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Psr\Log\LoggerInterface;

final readonly class VectorizationService
{
    public const VECTOR_DIMENSION = 1024;

    public function __construct(
        private AiFacade $aiFacade,
        private TextChunker $textChunker,
        private VectorStorageFacade $vectorStorage, // NEW: Use facade instead of direct DB
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Vectorize text and store chunks in the configured vector storage.
     *
     * @return array{chunks: int, provider: string}
     */
    public function vectorizeAndStore(
        string $text,
        int $userId,
        int $fileId,
        string $groupKey,
        int $fileType = 0,
    ): array {
        if (empty(trim($text))) {
            return ['chunks' => 0, 'provider' => $this->vectorStorage->getProviderName()];
        }

        // Get embedding model for user
        $model = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);
        if (!$model) {
            throw new \RuntimeException('No embedding model configured for user');
        }

        // Chunk the text
        $chunks = $this->textChunker->chunkify($text);

        if (empty($chunks)) {
            return ['chunks' => 0, 'provider' => $this->vectorStorage->getProviderName()];
        }

        // Generate embeddings and store
        $vectorChunks = [];
        foreach ($chunks as $index => $chunk) {
            // Generate embedding
            $embedding = $this->aiFacade->embed($chunk['content'], $model->getProvider());

            // Ensure correct dimension
            $embedding = $this->normalizeEmbedding($embedding);

            $vectorChunks[] = new VectorChunk(
                userId: $userId,
                fileId: $fileId,
                groupKey: $groupKey,
                fileType: $fileType,
                chunkIndex: $index,
                startLine: $chunk['start_line'],
                endLine: $chunk['end_line'],
                text: $chunk['content'],
                vector: $embedding,
            );
        }

        // Batch store
        $stored = $this->vectorStorage->storeChunkBatch($vectorChunks);

        $this->logger->info('Vectorized {chunks} chunks via {provider}', [
            'chunks' => $stored,
            'provider' => $this->vectorStorage->getProviderName(),
            'userId' => $userId,
            'fileId' => $fileId,
            'groupKey' => $groupKey,
        ]);

        return [
            'chunks' => $stored,
            'provider' => $this->vectorStorage->getProviderName(),
        ];
    }

    /**
     * Normalize embedding to expected dimension.
     */
    private function normalizeEmbedding(array $embedding): array
    {
        $current = count($embedding);

        if ($current === self::VECTOR_DIMENSION) {
            return $embedding;
        }

        if ($current > self::VECTOR_DIMENSION) {
            // Truncate
            return array_slice($embedding, 0, self::VECTOR_DIMENSION);
        }

        // Pad with zeros
        return array_pad($embedding, self::VECTOR_DIMENSION, 0.0);
    }
}
```

---

## Refactoring VectorSearchService

```php
<?php
// backend/src/Service/RAG/VectorSearchService.php (REFACTORED)

declare(strict_types=1);

namespace App\Service\RAG;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\VectorStorageFacade;

final readonly class VectorSearchService
{
    public function __construct(
        private AiFacade $aiFacade,
        private VectorStorageFacade $vectorStorage, // NEW: Use facade
        private ModelConfigService $modelConfigService,
    ) {}

    /**
     * Perform semantic search using query text.
     *
     * @return SearchResult[]
     */
    public function semanticSearch(
        int $userId,
        string $queryText,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3,
    ): array {
        // Get embedding model
        $model = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);
        if (!$model) {
            return [];
        }

        // Embed query
        $queryVector = $this->aiFacade->embed($queryText, $model->getProvider());

        // Search via facade
        $query = new SearchQuery(
            userId: $userId,
            vector: $queryVector,
            groupKey: $groupKey,
            limit: $limit,
            minScore: $minScore,
        );

        return $this->vectorStorage->search($query);
    }

    /**
     * Find similar chunks to a source.
     *
     * @return SearchResult[]
     */
    public function findSimilar(
        int $userId,
        int $sourceChunkId,
        int $limit = 10,
        float $minScore = 0.3,
    ): array {
        return $this->vectorStorage->findSimilar($userId, $sourceChunkId, $limit, $minScore);
    }

    /**
     * Get RAG statistics for a user.
     */
    public function getUserStats(int $userId): StorageStats
    {
        return $this->vectorStorage->getStats($userId);
    }

    /**
     * Get distinct group keys for a user.
     *
     * @return string[]
     */
    public function getGroupKeys(int $userId): array
    {
        return $this->vectorStorage->getGroupKeys($userId);
    }
}
```

---

## Summary of Changes

| File | Change Type | Description |
|------|-------------|-------------|
| `VectorStorageInterface.php` | NEW | Contract for all storage implementations |
| `VectorStorageFacade.php` | NEW | Facade that delegates to active provider |
| `VectorStorageConfig.php` | NEW | Configuration management |
| `MariaDBVectorStorage.php` | NEW | Extract existing logic |
| `QdrantVectorStorage.php` | NEW | New Qdrant implementation |
| `DTO/*.php` | NEW | Data transfer objects |
| `VectorizationService.php` | REFACTOR | Use facade instead of direct DB |
| `VectorSearchService.php` | REFACTOR | Use facade instead of direct queries |

**No breaking changes** - existing code continues to work, just routes through the facade.
