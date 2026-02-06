# 04 - Integration Points

## Overview

All code locations that need updates to use the new `VectorStorageFacade`.

**Principle:** Replace direct database/service calls with facade calls while maintaining exact same behavior.

---

## Integration Map

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              UPLOAD FLOWS                                       │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                 │
│  FileController::processUploadedFile()                                          │
│       │                                                                         │
│       ▼                                                                         │
│  VectorizationService::vectorizeAndStore() ──► VectorStorageFacade             │
│       │                                                                         │
│  WidgetPublicController::processWidgetFile()                                    │
│       │                                                                         │
│       ▼                                                                         │
│  VectorizationService::vectorizeAndStore() ──► VectorStorageFacade             │
│       │                                                                         │
│  WordPressIntegrationService::step3UploadFile()                                 │
│       │                                                                         │
│       ▼                                                                         │
│  VectorizationService::vectorizeAndStore() ──► VectorStorageFacade             │
│                                                                                 │
├─────────────────────────────────────────────────────────────────────────────────┤
│                              SEARCH FLOWS                                       │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                 │
│  ChatHandler::loadRagContext()                                                  │
│       │                                                                         │
│       ▼                                                                         │
│  VectorSearchService::semanticSearch() ──► VectorStorageFacade                 │
│       │                                                                         │
│  RagController::search()                                                        │
│       │                                                                         │
│       ▼                                                                         │
│  VectorSearchService::semanticSearch() ──► VectorStorageFacade                 │
│                                                                                 │
├─────────────────────────────────────────────────────────────────────────────────┤
│                              DELETE FLOWS                                       │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                 │
│  FileController::deleteFile()                                                   │
│       │                                                                         │
│       ▼                                                                         │
│  RagDocumentRepository::deleteByMessageId() ──► VectorStorageFacade            │
│       │                                                                         │
│  PromptController::deleteFile()                                                 │
│       │                                                                         │
│       ▼                                                                         │
│  RagDocumentRepository::deleteByGroupKey() ──► VectorStorageFacade             │
│       │                                                                         │
│  UserDeletionService::deleteRagDocuments()                                      │
│       │                                                                         │
│       ▼                                                                         │
│  Direct entity deletion ──► VectorStorageFacade                                │
│                                                                                 │
├─────────────────────────────────────────────────────────────────────────────────┤
│                              GROUP MANAGEMENT                                   │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                 │
│  FileController::updateGroupKey()                                               │
│       │                                                                         │
│       ▼                                                                         │
│  RagDocumentRepository::updateGroupKey() ──► VectorStorageFacade               │
│       │                                                                         │
│  PromptController::linkFile()                                                   │
│       │                                                                         │
│       ▼                                                                         │
│  Direct update query ──► VectorStorageFacade                                   │
│                                                                                 │
└─────────────────────────────────────────────────────────────────────────────────┘
```

---

## 1. File Upload - FileController

**File:** `backend/src/Controller/FileController.php`

### Current Code (lines ~280-320)

```php
// CURRENT - Direct VectorizationService call
$vectorResult = $this->vectorizationService->vectorizeAndStore(
    $extractedText,
    $user->getId(),
    $file->getId(),
    $groupKey,
    $this->getFileTypeCode($fileExtension)
);
```

### Updated Code

```php
// UPDATED - No change needed!
// VectorizationService internally uses VectorStorageFacade now
$vectorResult = $this->vectorizationService->vectorizeAndStore(
    $extractedText,
    $user->getId(),
    $file->getId(),
    $groupKey,
    $this->getFileTypeCode($fileExtension)
);

// Return now includes provider info
// $vectorResult = ['chunks' => 15, 'provider' => 'qdrant']
```

**Change:** None required in controller - VectorizationService handles facade internally.

---

## 2. Widget File Upload - WidgetPublicController

**File:** `backend/src/Controller/WidgetPublicController.php`

### Current Code (lines ~847-857)

```php
$groupKey = "WIDGET:{$widget->getWidgetId()}";
$vectorResult = $this->vectorizationService->vectorizeAndStore(
    $extractedText,
    $owner->getId(),  // Widget owner for quota
    $file->getId(),
    $groupKey,
    $this->getFileTypeCode($fileExtension)
);
```

### Updated Code

```php
// No change needed - VectorizationService uses facade internally
$groupKey = "WIDGET:{$widget->getWidgetId()}";
$vectorResult = $this->vectorizationService->vectorizeAndStore(
    $extractedText,
    $owner->getId(),
    $file->getId(),
    $groupKey,
    $this->getFileTypeCode($fileExtension)
);
```

**Change:** None required.

---

## 3. WordPress Integration

**File:** `backend/src/Service/WordPressIntegrationService.php`

### Current Code

```php
$this->vectorizationService->vectorizeAndStore(
    $extractedText,
    $userId,
    $file->getId(),
    'WORDPRESS_WIZARD',
    $this->getFileTypeCode($extension)
);
```

### Updated Code

```php
// No change needed
$this->vectorizationService->vectorizeAndStore(
    $extractedText,
    $userId,
    $file->getId(),
    'WORDPRESS_WIZARD',
    $this->getFileTypeCode($extension)
);
```

**Change:** None required.

---

## 4. Chat RAG Context - ChatHandler

**File:** `backend/src/Service/Message/Handler/ChatHandler.php`

### Current Code (lines ~328-345)

```php
// Search for RAG context
$ragResults = $this->vectorSearchService->semanticSearch(
    $userId,
    $messageText,
    $ragGroupKey,
    $ragLimit,
    $ragMinScore
);
```

### Updated Code

```php
// No change needed - VectorSearchService uses facade internally
$ragResults = $this->vectorSearchService->semanticSearch(
    $userId,
    $messageText,
    $ragGroupKey,
    $ragLimit,
    $ragMinScore
);
```

**Change:** None required - VectorSearchService refactored internally.

---

## 5. RAG API - RagController

**File:** `backend/src/Controller/RagController.php`

### Current Code

```php
#[Route('/api/v1/rag/search', methods: ['POST'])]
public function search(Request $request, #[CurrentUser] User $user): JsonResponse
{
    $data = json_decode($request->getContent(), true);

    $results = $this->vectorSearchService->semanticSearch(
        $user->getId(),
        $data['query'],
        $data['group_key'] ?? null,
        $data['limit'] ?? 10,
        $data['min_score'] ?? 0.3
    );

    return $this->json(['results' => $results]);
}
```

### Updated Code

```php
#[Route('/api/v1/rag/search', methods: ['POST'])]
public function search(Request $request, #[CurrentUser] User $user): JsonResponse
{
    $data = json_decode($request->getContent(), true);

    // No change needed - VectorSearchService uses facade
    $results = $this->vectorSearchService->semanticSearch(
        $user->getId(),
        $data['query'],
        $data['group_key'] ?? null,
        $data['limit'] ?? 10,
        $data['min_score'] ?? 0.3
    );

    // Optionally add provider info to response
    return $this->json([
        'results' => $results,
        'provider' => $this->vectorSearchService->getProviderName(), // NEW
    ]);
}
```

**Change:** Optional - add provider name to response for debugging.

---

## 6. File Deletion - FileController

**File:** `backend/src/Controller/FileController.php`

### Current Code (lines ~520-535)

```php
#[Route('/api/v1/files/{id}', methods: ['DELETE'])]
public function deleteFile(int $id, #[CurrentUser] User $user): JsonResponse
{
    $file = $this->fileRepository->find($id);
    // ... validation ...

    // Delete RAG documents
    $this->ragDocumentRepository->deleteByMessageId($file->getId());

    // Delete physical file and entity
    // ...
}
```

### Updated Code

```php
#[Route('/api/v1/files/{id}', methods: ['DELETE'])]
public function deleteFile(int $id, #[CurrentUser] User $user): JsonResponse
{
    $file = $this->fileRepository->find($id);
    // ... validation ...

    // Delete RAG documents via facade
    $this->vectorStorageFacade->deleteByFile($user->getId(), $file->getId());

    // Delete physical file and entity
    // ...
}
```

**Change:** Replace `ragDocumentRepository->deleteByMessageId()` with `vectorStorageFacade->deleteByFile()`.

---

## 7. Task Prompt File Management - PromptController

**File:** `backend/src/Controller/PromptController.php`

### Current Code - linkFile (lines ~1262-1290)

```php
#[Route('/api/v1/prompts/{topic}/files/{fileId}/link', methods: ['POST'])]
public function linkFile(string $topic, int $fileId, #[CurrentUser] User $user): JsonResponse
{
    // ... validation ...

    $groupKey = "TASKPROMPT:{$topic}";

    // Update group key in RAG documents
    $this->em->createQueryBuilder()
        ->update(RagDocument::class, 'r')
        ->set('r.groupKey', ':groupKey')
        ->where('r.userId = :userId')
        ->andWhere('r.messageId = :fileId')
        ->setParameter('groupKey', $groupKey)
        ->setParameter('userId', $user->getId())
        ->setParameter('fileId', $fileId)
        ->getQuery()
        ->execute();

    return $this->json(['success' => true]);
}
```

### Updated Code

```php
#[Route('/api/v1/prompts/{topic}/files/{fileId}/link', methods: ['POST'])]
public function linkFile(string $topic, int $fileId, #[CurrentUser] User $user): JsonResponse
{
    // ... validation ...

    $groupKey = "TASKPROMPT:{$topic}";

    // Update group key via facade
    $updated = $this->vectorStorageFacade->updateGroupKey(
        $user->getId(),
        $fileId,
        $groupKey
    );

    return $this->json(['success' => true, 'updated_chunks' => $updated]);
}
```

**Change:** Replace direct Doctrine query with facade call.

### Current Code - deleteFile (lines ~1180-1200)

```php
#[Route('/api/v1/prompts/{topic}/files/{fileId}', methods: ['DELETE'])]
public function deleteFile(string $topic, int $fileId, #[CurrentUser] User $user): JsonResponse
{
    $groupKey = "TASKPROMPT:{$topic}";

    $this->ragDocumentRepository->deleteByGroupKey($groupKey);
    $this->ragDocumentRepository->deleteByMessageId($fileId);

    return $this->json(['success' => true]);
}
```

### Updated Code

```php
#[Route('/api/v1/prompts/{topic}/files/{fileId}', methods: ['DELETE'])]
public function deleteFile(string $topic, int $fileId, #[CurrentUser] User $user): JsonResponse
{
    // Delete chunks via facade
    $deleted = $this->vectorStorageFacade->deleteByFile($user->getId(), $fileId);

    return $this->json(['success' => true, 'deleted_chunks' => $deleted]);
}
```

**Change:** Replace repository calls with single facade call.

---

## 8. User Deletion - UserDeletionService

**File:** `backend/src/Service/UserDeletionService.php`

### Current Code (lines ~135-145)

```php
private function deleteRagDocuments(User $user): void
{
    $qb = $this->em->createQueryBuilder();
    $qb->delete(RagDocument::class, 'r')
        ->where('r.userId = :userId')
        ->setParameter('userId', $user->getId());
    $qb->getQuery()->execute();
}
```

### Updated Code

```php
private function deleteRagDocuments(User $user): void
{
    $this->vectorStorageFacade->deleteAllForUser($user->getId());
}
```

**Change:** Replace Doctrine query with facade call.

---

## 9. Group Key Listing - FileController

**File:** `backend/src/Controller/FileController.php`

### Current Code

```php
#[Route('/api/v1/files/groups', methods: ['GET'])]
public function getFileGroups(#[CurrentUser] User $user): JsonResponse
{
    $groups = $this->ragDocumentRepository->findDistinctGroupKeysByUser($user->getId());
    return $this->json(['groups' => $groups]);
}
```

### Updated Code

```php
#[Route('/api/v1/files/groups', methods: ['GET'])]
public function getFileGroups(#[CurrentUser] User $user): JsonResponse
{
    $groups = $this->vectorStorageFacade->getGroupKeys($user->getId());
    return $this->json(['groups' => $groups]);
}
```

**Change:** Replace repository call with facade call.

---

## 10. Re-vectorization - FileController

**File:** `backend/src/Controller/FileController.php`

### Current Code (lines ~988-1010)

```php
#[Route('/api/v1/files/{id}/revectorize', methods: ['POST'])]
public function reVectorize(int $id, #[CurrentUser] User $user): JsonResponse
{
    $file = $this->fileRepository->find($id);

    // Delete existing vectors
    $this->ragDocumentRepository->deleteByMessageId($file->getId());

    // Re-extract and vectorize
    $text = $this->fileProcessor->extractText($file->getPath());
    $result = $this->vectorizationService->vectorizeAndStore(
        $text,
        $user->getId(),
        $file->getId(),
        $file->getGroupKey() ?? 'DEFAULT',
        $this->getFileTypeCode($file->getExtension())
    );

    return $this->json($result);
}
```

### Updated Code

```php
#[Route('/api/v1/files/{id}/revectorize', methods: ['POST'])]
public function reVectorize(int $id, #[CurrentUser] User $user): JsonResponse
{
    $file = $this->fileRepository->find($id);

    // Delete existing vectors via facade
    $this->vectorStorageFacade->deleteByFile($user->getId(), $file->getId());

    // Re-extract and vectorize (no change - VectorizationService uses facade)
    $text = $this->fileProcessor->extractText($file->getPath());
    $result = $this->vectorizationService->vectorizeAndStore(
        $text,
        $user->getId(),
        $file->getId(),
        $file->getGroupKey() ?? 'DEFAULT',
        $this->getFileTypeCode($file->getExtension())
    );

    return $this->json($result);
}
```

**Change:** Replace repository delete with facade delete.

---

## 11. Image Vectorization Flow

**File:** `backend/src/Service/File/FileProcessor.php`

The image-to-text flow already works correctly:

1. `FileProcessor::extractFromImage()` → Vision API → returns text
2. Controller calls `VectorizationService::vectorizeAndStore()` with extracted text
3. VectorizationService uses facade to store

**No changes needed** - the extracted text (whether from PDF, image, or document) all flows through VectorizationService which now uses the facade.

---

## Summary of Required Changes

| File | Method | Change Required |
|------|--------|-----------------|
| `VectorizationService.php` | All | Refactor to use facade (major) |
| `VectorSearchService.php` | All | Refactor to use facade (major) |
| `FileController.php` | `deleteFile()` | Replace repo with facade |
| `FileController.php` | `reVectorize()` | Replace repo with facade |
| `FileController.php` | `getFileGroups()` | Replace repo with facade |
| `FileController.php` | `updateGroupKey()` | Replace repo with facade |
| `PromptController.php` | `linkFile()` | Replace Doctrine query with facade |
| `PromptController.php` | `deleteFile()` | Replace repo with facade |
| `UserDeletionService.php` | `deleteRagDocuments()` | Replace Doctrine query with facade |
| `RagController.php` | `search()` | Optional: add provider to response |
| `WidgetPublicController.php` | None | No changes needed |
| `WordPressIntegrationService.php` | None | No changes needed |
| `ChatHandler.php` | None | No changes needed |

---

## Dependency Injection Updates

Add `VectorStorageFacade` to controllers that need it:

```php
// FileController.php constructor
public function __construct(
    private FileRepository $fileRepository,
    private VectorizationService $vectorizationService,
    private VectorStorageFacade $vectorStorageFacade,  // NEW
    private FileProcessor $fileProcessor,
    // ... other dependencies
) {}

// PromptController.php constructor
public function __construct(
    private PromptRepository $promptRepository,
    private VectorStorageFacade $vectorStorageFacade,  // NEW
    // ... other dependencies
) {}

// UserDeletionService.php constructor
public function __construct(
    private EntityManagerInterface $em,
    private VectorStorageFacade $vectorStorageFacade,  // NEW
    // ... other dependencies
) {}
```

---

## Testing Checklist

After integration, verify:

- [ ] File upload creates vectors (check MariaDB or Qdrant based on config)
- [ ] Widget file upload creates vectors with correct group key
- [ ] Chat RAG context retrieval works
- [ ] File deletion removes all associated vectors
- [ ] Group key update works
- [ ] Re-vectorization works
- [ ] User deletion cleans up all vectors
- [ ] Task prompt file linking works
- [ ] Provider switching works without restart
