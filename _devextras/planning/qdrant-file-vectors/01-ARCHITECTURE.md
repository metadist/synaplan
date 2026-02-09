# Vector Storage Architecture

## Overview

This document describes the facade pattern architecture for configurable vector storage, allowing selection between MariaDB Vector and Qdrant via environment configuration.

## Design Goals

1. **Transparent Backend Selection** - Single interface, backend determined by config
2. **Per-User Isolation** - Users never see each other's vectors
3. **Group-Based Organization** - Files organized by purpose (widgets, prompts, default)
4. **Extensibility** - Easy to add future backends (Pinecone, Weaviate, etc.)
5. **No Regression** - Existing MariaDB functionality preserved

---

## Facade Pattern

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Application Layer                                  │
│                                                                             │
│   FileController    WidgetPublicController    ChatHandler    PromptController│
│        │                    │                     │               │         │
│        └────────────────────┴──────────┬──────────┴───────────────┘         │
│                                        │                                     │
│                                        ▼                                     │
│                        ┌───────────────────────────────┐                    │
│                        │    VectorStorageFacade       │ ◄── Single entry   │
│                        │   (decides backend at        │     point for all  │
│                        │    runtime from config)      │     vector ops     │
│                        └───────────────┬───────────────┘                    │
│                                        │                                     │
│                        ┌───────────────┴───────────────┐                    │
│                        ▼                               ▼                     │
│         ┌─────────────────────────┐   ┌─────────────────────────┐          │
│         │ MariaDBVectorStorage    │   │  QdrantVectorStorage    │          │
│         │ (existing BRAG table)   │   │  (HTTP to qdrant-svc)   │          │
│         └─────────────────────────┘   └─────────────────────────┘          │
│                        │                               │                     │
└────────────────────────│───────────────────────────────│─────────────────────┘
                         │                               │
                         ▼                               ▼
              ┌──────────────────┐           ┌──────────────────────┐
              │    MariaDB       │           │   Qdrant Service     │
              │   BRAG Table     │           │   (user_documents)   │
              │  VECTOR(1024)    │           │   Collection         │
              └──────────────────┘           └──────────────────────┘
```

---

## Interface Definition

```php
// src/Service/RAG/VectorStorage/VectorStorageInterface.php

namespace App\Service\RAG\VectorStorage;

interface VectorStorageInterface
{
    /**
     * Store a text chunk with its embedding vector.
     *
     * @param int    $userId     Owner of this vector (multi-tenant isolation)
     * @param int    $fileId     Reference to source file (BFILES.BID)
     * @param string $groupKey   Grouping key (e.g., 'DEFAULT', 'WIDGET:xyz', 'TASKPROMPT:abc')
     * @param int    $fileType   File type code (for filtering)
     * @param int    $chunkIndex Position within the file (0-based)
     * @param int    $startLine  Source line start (for context)
     * @param int    $endLine    Source line end (for context)
     * @param string $text       The chunk content
     * @param array  $vector     The embedding vector (float[])
     * @return string            Unique chunk ID
     */
    public function storeChunk(
        int $userId,
        int $fileId,
        string $groupKey,
        int $fileType,
        int $chunkIndex,
        int $startLine,
        int $endLine,
        string $text,
        array $vector
    ): string;

    /**
     * Store multiple chunks in a batch operation.
     *
     * @param int   $userId Owner of these vectors
     * @param array $chunks Array of chunk data (same structure as storeChunk params)
     * @return array        Array of chunk IDs
     */
    public function storeChunkBatch(int $userId, array $chunks): array;

    /**
     * Delete all vectors for a specific file.
     *
     * @param int $userId Owner of the vectors
     * @param int $fileId File ID to delete vectors for
     * @return int        Number of vectors deleted
     */
    public function deleteByFile(int $userId, int $fileId): int;

    /**
     * Delete all vectors for a specific group key.
     *
     * @param int    $userId   Owner of the vectors
     * @param string $groupKey Group key to delete
     * @return int             Number of vectors deleted
     */
    public function deleteByGroupKey(int $userId, string $groupKey): int;

    /**
     * Update the group key for all vectors of a file.
     *
     * @param int    $userId      Owner of the vectors
     * @param int    $fileId      File ID to update
     * @param string $newGroupKey New group key
     * @return int                Number of vectors updated
     */
    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int;

    /**
     * Perform semantic search using a query vector.
     *
     * @param int         $userId     Owner of the vectors to search
     * @param array       $queryVector Query embedding vector
     * @param string|null $groupKey   Optional group key filter
     * @param int         $limit      Maximum results (default: 10)
     * @param float       $minScore   Minimum similarity score 0-1 (default: 0.3)
     * @return array                  Array of SearchResult objects
     */
    public function search(
        int $userId,
        array $queryVector,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3
    ): array;

    /**
     * Get all distinct group keys for a user.
     *
     * @param int $userId Owner ID
     * @return array      Array of group key strings
     */
    public function getGroupKeys(int $userId): array;

    /**
     * Get statistics for a user's vectors.
     *
     * @param int $userId Owner ID
     * @return array      Stats array ['total_chunks', 'total_files', 'groups' => [...]]
     */
    public function getStats(int $userId): array;

    /**
     * Check if the storage backend is available.
     *
     * @return bool True if backend is reachable and healthy
     */
    public function isHealthy(): bool;

    /**
     * Get the backend type identifier.
     *
     * @return string 'mariadb' or 'qdrant'
     */
    public function getBackendType(): string;
}
```

---

## Search Result DTO

```php
// src/Service/RAG/VectorStorage/SearchResult.php

namespace App\Service\RAG\VectorStorage;

final readonly class SearchResult
{
    public function __construct(
        public string $chunkId,
        public int $fileId,
        public string $groupKey,
        public int $startLine,
        public int $endLine,
        public string $text,
        public float $score,        // 0.0-1.0, higher = more similar
        public ?string $filename = null,  // Optionally joined from BFILES
    ) {}

    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'file_id' => $this->fileId,
            'group_key' => $this->groupKey,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'text' => $this->text,
            'score' => $this->score,
            'filename' => $this->filename,
        ];
    }
}
```

---

## Facade Implementation

```php
// src/Service/RAG/VectorStorage/VectorStorageFacade.php

namespace App\Service\RAG\VectorStorage;

final readonly class VectorStorageFacade implements VectorStorageInterface
{
    private VectorStorageInterface $storage;

    public function __construct(
        private string $vectorStorageProvider,  // From env: 'mariadb' or 'qdrant'
        private MariaDBVectorStorage $mariadbStorage,
        private QdrantVectorStorage $qdrantStorage,
        private LoggerInterface $logger,
    ) {
        $this->storage = match ($this->vectorStorageProvider) {
            'qdrant' => $this->qdrantStorage,
            default => $this->mariadbStorage,
        };
        
        $this->logger->info('Vector storage initialized', [
            'provider' => $this->vectorStorageProvider,
            'backend' => $this->storage->getBackendType(),
        ]);
    }

    // All interface methods delegate to $this->storage
    public function storeChunk(...$args): string
    {
        return $this->storage->storeChunk(...$args);
    }

    // ... other methods delegate similarly
    
    public function getBackendType(): string
    {
        return $this->storage->getBackendType();
    }
}
```

---

## Multi-Tenant Isolation

### User Isolation Requirements

1. **Every operation requires `userId`** - No cross-user queries possible
2. **Group keys are user-scoped** - `WIDGET:abc` for user 1 ≠ user 2
3. **Vectors stored with user ownership** - `BUID` in MariaDB, `user_id` in Qdrant

### Group Key Conventions

| Pattern | Purpose | Example |
|---------|---------|---------|
| `DEFAULT` | Standard chat uploads | User's general knowledge base |
| `WIDGET:{widgetId}` | Widget-specific files | `WIDGET:wdg_abc123def456` |
| `TASKPROMPT:{topic}` | Task prompt context | `TASKPROMPT:codeme` |
| `WORDPRESS_WIZARD` | WordPress integration | Temporary wizard files |
| Custom | User-defined groups | `PROJECT:marketing`, `CLIENT:acme` |

### Group Management

Users can create custom groups for organizing files:
- Group creation is implicit (first file upload creates group)
- Groups can be renamed via `updateGroupKey()`
- Empty groups are automatically cleaned up
- Default group is always available

---

## Backend Comparison

| Feature | MariaDB Vector | Qdrant |
|---------|---------------|--------|
| **Performance** | Slower (~100ms) | Fast (~5ms) |
| **Scaling** | Limited | Horizontal |
| **Dependencies** | Built-in | External service |
| **Backup** | DB backup | Separate snapshots |
| **Filtering** | SQL WHERE | Qdrant filters |
| **Index Tuning** | Limited | HNSW params |

---

## Diagram: Data Flow

```
User uploads file via FileController
          │
          ▼
    FileStorageService
    (stores physical file)
          │
          ▼
    FileProcessor.extractText()
    (Tika, Vision, Whisper)
          │
          ▼
    VectorizationService.vectorizeAndStore()
          │
          ├─► TextChunker.chunkify()
          │   (splits into chunks with line numbers)
          │
          ├─► AiFacade.embed() / embedBatch()
          │   (generates vectors via user's VECTORIZE model)
          │
          └─► VectorStorageFacade.storeChunkBatch()
              │
              ├─► [mariadb] INSERT INTO BRAG ... VEC_FromText()
              │
              └─► [qdrant] POST /documents/batch
                           │
                           ▼
                  qdrant-service → Qdrant DB
                  (user_documents collection)
```

---

## Diagram: Search Flow

```
User sends chat message
          │
          ▼
    ChatHandler.handleStream()
          │
          ├─► Gets rag_group_key from options
          │   (e.g., "WIDGET:wdg_abc123")
          │
          └─► VectorSearchService.semanticSearch()
              │
              ├─► AiFacade.embed(query)
              │   (embeds user's question)
              │
              └─► VectorStorageFacade.search()
                  │
                  ├─► [mariadb] SELECT ... VEC_DISTANCE_COSINE()
                  │
                  └─► [qdrant] POST /documents/search
                               │
                               ▼
                      Returns SearchResult[]
                               │
                               ▼
              Formats as RAG context string
                               │
                               ▼
              Appends to system prompt
                               │
                               ▼
              AI generates response with context
```
