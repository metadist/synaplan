# Service Refactoring

## Overview

This document details how to refactor existing services to use the new `VectorStorageInterface`.

---

## Files to Modify

### 1. VectorizationService

**Current:** Direct SQL inserts into BRAG table
**After:** Uses `VectorStorageInterface`

```php
// src/Service/File/VectorizationService.php

namespace App\Service\File;

use App\Service\RAG\VectorStorage\VectorStorageInterface;
// ... other imports

final readonly class VectorizationService
{
    public const VECTOR_DIMENSION = 1024;

    public function __construct(
        private TextChunker $textChunker,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private VectorStorageInterface $vectorStorage,  // NEW: Replace EntityManager
        private LoggerInterface $logger,
    ) {}

    public function vectorizeAndStore(
        string $text,
        int $userId,
        int $fileId,
        string $groupKey,
        int $fileType
    ): array {
        // Get embedding model
        $model = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);
        if (!$model) {
            throw new \RuntimeException('No vectorization model configured');
        }

        // Chunk the text
        $chunks = $this->textChunker->chunkify($text);
        
        if (empty($chunks)) {
            $this->logger->warning('No chunks generated', ['fileId' => $fileId]);
            return ['chunks_stored' => 0];
        }

        // Generate embeddings in batch
        $texts = array_column($chunks, 'content');
        $embeddings = $this->aiFacade->embedBatch(
            $texts,
            $model->getService(),
            $model->getProvid()
        );

        // Prepare batch data
        $batchData = [];
        foreach ($chunks as $index => $chunk) {
            $batchData[] = [
                'file_id' => $fileId,
                'group_key' => $groupKey,
                'file_type' => $fileType,
                'chunk_index' => $index,
                'start_line' => $chunk['start_line'],
                'end_line' => $chunk['end_line'],
                'text' => $chunk['content'],
                'vector' => $embeddings[$index] ?? [],
            ];
        }

        // Store via interface (MariaDB or Qdrant)
        $chunkIds = $this->vectorStorage->storeChunkBatch($userId, $batchData);

        $this->logger->info('Vectorization complete', [
            'fileId' => $fileId,
            'userId' => $userId,
            'groupKey' => $groupKey,
            'chunks' => count($chunkIds),
            'backend' => $this->vectorStorage->getBackendType(),
        ]);

        return [
            'chunks_stored' => count($chunkIds),
            'chunk_ids' => $chunkIds,
        ];
    }
}
```

---

### 2. VectorSearchService

**Current:** Direct SQL queries against BRAG table
**After:** Uses `VectorStorageInterface`

```php
// src/Service/RAG/VectorSearchService.php

namespace App\Service\RAG;

use App\Service\RAG\VectorStorage\VectorStorageInterface;
use App\Service\RAG\VectorStorage\SearchResult;
// ... other imports

final readonly class VectorSearchService
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private VectorStorageInterface $vectorStorage,  // NEW: Replace EntityManager
        private LoggerInterface $logger,
    ) {}

    /**
     * Perform semantic search using natural language query.
     *
     * @return SearchResult[]
     */
    public function semanticSearch(
        string $query,
        int $userId,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3
    ): array {
        // Get embedding model
        $model = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);
        if (!$model) {
            $this->logger->warning('No vectorization model for search', ['userId' => $userId]);
            return [];
        }

        // Embed the query
        $queryVector = $this->aiFacade->embed(
            $query,
            $model->getService(),
            $model->getProvid()
        );

        if (empty($queryVector)) {
            $this->logger->error('Failed to embed query', ['query' => substr($query, 0, 100)]);
            return [];
        }

        // Search via interface
        return $this->vectorStorage->search(
            $userId,
            $queryVector,
            $groupKey,
            $limit,
            $minScore
        );
    }

    /**
     * Get user's RAG statistics.
     */
    public function getUserStats(int $userId): array
    {
        return $this->vectorStorage->getStats($userId);
    }

    /**
     * Get distinct group keys for user.
     */
    public function getGroupKeys(int $userId): array
    {
        return $this->vectorStorage->getGroupKeys($userId);
    }
}
```

---

### 3. RagDocumentRepository

**Status:** Keep for backward compatibility and direct DB queries when needed.

The repository should be kept but will be used less directly. It's still useful for:
- Complex queries not covered by the interface
- Admin operations
- Migrations
- Legacy code paths

```php
// src/Repository/RagDocumentRepository.php

// Keep existing methods, add deprecation notices where appropriate

/**
 * @deprecated Use VectorStorageInterface::deleteByFile() instead
 */
public function deleteByMessageId(int $messageId): int
{
    // ... existing implementation
}
```

---

## Controllers to Update

### FileController

**Changes:**
- Inject `VectorStorageInterface` instead of direct repository calls
- Use interface methods for delete/update operations

```php
// src/Controller/FileController.php

// In deleteFile() method:
// Before:
$this->ragDocumentRepository->deleteByMessageId($file->getId());

// After:
$this->vectorStorage->deleteByFile($user->getId(), $file->getId());

// In updateGroupKey() method:
// Before:
$updated = $this->ragDocumentRepository->updateGroupKeyForMessage($fileId, $newGroupKey);

// After:
$updated = $this->vectorStorage->updateGroupKey($user->getId(), $fileId, $newGroupKey);
```

---

### PromptController

**Changes:**
- Use interface for linking/unlinking files to prompts

```php
// src/Controller/PromptController.php

// In linkFile() method:
// Before:
$updated = $this->ragDocumentRepository->updateGroupKeyForMessage(
    $file->getId(),
    sprintf('TASKPROMPT:%s', $topic)
);

// After:
$updated = $this->vectorStorage->updateGroupKey(
    $user->getId(),
    $file->getId(),
    sprintf('TASKPROMPT:%s', $topic)
);

// In deleteFile() method:
// Before:
$this->ragDocumentRepository->deleteByMessageId($fileId);

// After:
$this->vectorStorage->deleteByFile($user->getId(), $fileId);
```

---

### RagController

**Changes:**
- Use `VectorSearchService` (which now uses interface internally)

```php
// src/Controller/RagController.php

// No direct changes needed - VectorSearchService handles abstraction
// Just ensure VectorSearchService is injected (already is)
```

---

### WidgetPublicController

**Changes:**
- `processWidgetFile()` uses `VectorizationService` (no direct changes needed)
- Vectorization automatically uses configured backend

```php
// src/Controller/WidgetPublicController.php

// No direct changes needed
// VectorizationService handles backend selection internally
```

---

## Message Handling Updates

### ChatHandler

**Changes:**
- `loadRagContext()` uses `VectorSearchService` (no direct changes needed)

```php
// src/Service/Message/Handler/ChatHandler.php

// No direct changes needed
// VectorSearchService handles backend selection internally
```

---

## User Deletion

### UserDeletionService

**Changes:**
- Update to use interface for comprehensive cleanup

```php
// src/Service/UserDeletionService.php

// Add to constructor:
private VectorStorageInterface $vectorStorage;

// In deleteRagDocuments() method:
// Note: For MariaDB, we still use the repository for bulk delete
// For Qdrant, we'd need a bulk delete by user endpoint

// Option A: Keep using repository for MariaDB (hybrid approach)
if ($this->vectorStorage->getBackendType() === 'mariadb') {
    // Existing behavior
    $this->ragDocumentRepository->deleteByUserId($userId);
} else {
    // For Qdrant, delete all groups then all documents
    $groups = $this->vectorStorage->getGroupKeys($userId);
    foreach ($groups as $groupKey) {
        $this->vectorStorage->deleteByGroupKey($userId, $groupKey);
    }
}

// Option B: Add deleteByUser() to interface (cleaner)
// $this->vectorStorage->deleteByUser($userId);
```

---

## Summary of Changes

| File | Change Type | Description |
|------|-------------|-------------|
| `VectorizationService.php` | **Major Refactor** | Use `VectorStorageInterface` instead of direct SQL |
| `VectorSearchService.php` | **Major Refactor** | Use `VectorStorageInterface` instead of direct SQL |
| `RagDocumentRepository.php` | **Deprecate Some** | Keep for compatibility, deprecate direct use |
| `FileController.php` | **Minor Update** | Use interface for delete/update ops |
| `PromptController.php` | **Minor Update** | Use interface for link/unlink ops |
| `RagController.php` | **No Change** | Uses VectorSearchService (already abstracted) |
| `WidgetPublicController.php` | **No Change** | Uses VectorizationService (already abstracted) |
| `ChatHandler.php` | **No Change** | Uses VectorSearchService (already abstracted) |
| `UserDeletionService.php` | **Minor Update** | Handle cleanup for both backends |
| `WordPressIntegrationService.php` | **No Change** | Uses VectorizationService (already abstracted) |

---

## Dependency Injection Changes

### services.yaml additions

```yaml
services:
    # Existing services use interfaces
    App\Service\File\VectorizationService:
        arguments:
            $vectorStorage: '@App\Service\RAG\VectorStorage\VectorStorageInterface'

    App\Service\RAG\VectorSearchService:
        arguments:
            $vectorStorage: '@App\Service\RAG\VectorStorage\VectorStorageInterface'

    # Controllers that need direct access
    App\Controller\FileController:
        arguments:
            $vectorStorage: '@App\Service\RAG\VectorStorage\VectorStorageInterface'

    App\Controller\PromptController:
        arguments:
            $vectorStorage: '@App\Service\RAG\VectorStorage\VectorStorageInterface'
```
