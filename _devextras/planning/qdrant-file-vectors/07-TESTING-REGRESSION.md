# Testing & Regression Prevention

## Overview

Comprehensive testing strategy to ensure no regression when switching between vector backends.

---

## Critical Paths to Test

### 1. File Upload & Vectorization

| Test Case | Expected Behavior |
|-----------|-------------------|
| Upload text file | Chunks created, vectors stored |
| Upload PDF | Text extracted via Tika, vectorized |
| Upload image | Vision extraction, single chunk stored |
| Upload to custom group | Group key set correctly |
| Upload via widget | `WIDGET:{id}` group key |
| Re-vectorize file | Old chunks deleted, new created |

### 2. Vector Search

| Test Case | Expected Behavior |
|-----------|-------------------|
| Search user's files | Returns relevant chunks |
| Search with group filter | Only returns from specified group |
| Search with minScore | Filters low-confidence results |
| Cross-user isolation | User A cannot find User B's files |
| Empty results | Graceful empty array response |

### 3. File Deletion

| Test Case | Expected Behavior |
|-----------|-------------------|
| Delete file | All associated chunks deleted |
| Delete file in group | Only that file's chunks removed |
| Delete all user data | All user's vectors cleared |

### 4. Group Operations

| Test Case | Expected Behavior |
|-----------|-------------------|
| Change file group | Chunks updated with new group key |
| Link file to prompt | Group key becomes `TASKPROMPT:{topic}` |
| List user groups | All distinct group keys returned |

### 5. Chat RAG Integration

| Test Case | Expected Behavior |
|-----------|-------------------|
| Chat with RAG context | Relevant chunks included in prompt |
| Widget chat | Only widget's files in context |
| Task prompt chat | Only linked files in context |

---

## Test Matrix

### Backend Compatibility

| Operation | MariaDB | Qdrant | Cross-Backend |
|-----------|---------|--------|---------------|
| storeChunk | ✅ | ✅ | N/A |
| storeChunkBatch | ✅ | ✅ | N/A |
| deleteByFile | ✅ | ✅ | N/A |
| deleteByGroupKey | ✅ | ✅ | N/A |
| updateGroupKey | ✅ | ✅ | N/A |
| search | ✅ | ✅ | N/A |
| getGroupKeys | ✅ | ✅ | N/A |
| getStats | ✅ | ✅ | N/A |
| isHealthy | ✅ | ✅ | N/A |
| Migration (M→Q) | N/A | N/A | ✅ |
| Migration (Q→M) | N/A | N/A | ✅ |

---

## Unit Tests

### VectorStorageInterface Tests

```php
// tests/Service/RAG/VectorStorage/VectorStorageInterfaceTest.php

abstract class VectorStorageInterfaceTest extends TestCase
{
    protected VectorStorageInterface $storage;
    protected int $testUserId = 999;
    protected int $testFileId = 1001;

    public function testStoreAndRetrieveChunk(): void
    {
        $chunkId = $this->storage->storeChunk(
            userId: $this->testUserId,
            fileId: $this->testFileId,
            groupKey: 'TEST_GROUP',
            fileType: 1,
            chunkIndex: 0,
            startLine: 1,
            endLine: 10,
            text: 'This is test content for vectorization.',
            vector: $this->generateTestVector()
        );

        $this->assertNotEmpty($chunkId);

        // Search should find it
        $results = $this->storage->search(
            userId: $this->testUserId,
            queryVector: $this->generateTestVector(),
            groupKey: 'TEST_GROUP',
            limit: 10,
            minScore: 0.0
        );

        $this->assertCount(1, $results);
        $this->assertEquals($this->testFileId, $results[0]->fileId);
    }

    public function testUserIsolation(): void
    {
        // Store chunk for user 1
        $this->storage->storeChunk(
            userId: 1,
            fileId: 100,
            groupKey: 'DEFAULT',
            fileType: 1,
            chunkIndex: 0,
            startLine: 1,
            endLine: 5,
            text: 'User 1 secret data',
            vector: $this->generateTestVector()
        );

        // Search as user 2
        $results = $this->storage->search(
            userId: 2,
            queryVector: $this->generateTestVector(),
            limit: 100
        );

        // Should not find user 1's data
        $this->assertEmpty($results);
    }

    public function testGroupFiltering(): void
    {
        // Store in group A
        $this->storage->storeChunk(
            userId: $this->testUserId,
            fileId: 1,
            groupKey: 'GROUP_A',
            fileType: 1,
            chunkIndex: 0,
            startLine: 1,
            endLine: 5,
            text: 'Group A content',
            vector: $this->generateTestVector()
        );

        // Store in group B
        $this->storage->storeChunk(
            userId: $this->testUserId,
            fileId: 2,
            groupKey: 'GROUP_B',
            fileType: 1,
            chunkIndex: 0,
            startLine: 1,
            endLine: 5,
            text: 'Group B content',
            vector: $this->generateTestVector()
        );

        // Search only group A
        $results = $this->storage->search(
            userId: $this->testUserId,
            queryVector: $this->generateTestVector(),
            groupKey: 'GROUP_A'
        );

        $this->assertCount(1, $results);
        $this->assertEquals('GROUP_A', $results[0]->groupKey);
    }

    public function testDeleteByFile(): void
    {
        // Store multiple chunks for same file
        for ($i = 0; $i < 3; $i++) {
            $this->storage->storeChunk(
                userId: $this->testUserId,
                fileId: $this->testFileId,
                groupKey: 'DEFAULT',
                fileType: 1,
                chunkIndex: $i,
                startLine: $i * 10,
                endLine: ($i + 1) * 10,
                text: "Chunk $i content",
                vector: $this->generateTestVector()
            );
        }

        // Delete by file
        $deleted = $this->storage->deleteByFile($this->testUserId, $this->testFileId);
        $this->assertEquals(3, $deleted);

        // Verify deleted
        $results = $this->storage->search(
            userId: $this->testUserId,
            queryVector: $this->generateTestVector()
        );
        $this->assertEmpty($results);
    }

    protected function generateTestVector(): array
    {
        // Generate 1024-dim normalized random vector
        $vector = [];
        for ($i = 0; $i < 1024; $i++) {
            $vector[] = (mt_rand() / mt_getrandmax()) - 0.5;
        }
        // Normalize
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $vector)));
        return array_map(fn($x) => $x / $magnitude, $vector);
    }
}
```

### MariaDB Implementation Tests

```php
// tests/Service/RAG/VectorStorage/MariaDBVectorStorageTest.php

class MariaDBVectorStorageTest extends VectorStorageInterfaceTest
{
    protected function setUp(): void
    {
        $this->storage = new MariaDBVectorStorage(
            self::getContainer()->get('doctrine.orm.entity_manager'),
            self::getContainer()->get('logger')
        );
    }

    // Inherits all interface tests
}
```

### Qdrant Implementation Tests

```php
// tests/Service/RAG/VectorStorage/QdrantVectorStorageTest.php

class QdrantVectorStorageTest extends VectorStorageInterfaceTest
{
    protected function setUp(): void
    {
        $this->markTestSkipped('Requires Qdrant service running');
        
        $this->storage = new QdrantVectorStorage(
            'http://localhost:8090',
            'test-key',
            'test_documents',
            self::getContainer()->get('http_client'),
            self::getContainer()->get('logger')
        );
    }

    // Inherits all interface tests
}
```

---

## Integration Tests

### File Upload Flow

```php
// tests/Controller/FileControllerIntegrationTest.php

class FileControllerIntegrationTest extends WebTestCase
{
    public function testUploadAndVectorize(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getTestUser());

        // Upload file
        $client->request('POST', '/api/v1/files/upload', [], [
            'file' => new UploadedFile(
                __DIR__.'/fixtures/test.txt',
                'test.txt',
                'text/plain'
            ),
        ], [
            'HTTP_X-Process-Level' => 'vectorize',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('file_id', $data);
        $this->assertEquals('vectorized', $data['status']);

        // Verify vectors created
        $client->request('GET', '/api/v1/rag/stats');
        $stats = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertGreaterThan(0, $stats['total_chunks']);
    }

    public function testDeleteFileRemovesVectors(): void
    {
        // Upload and vectorize
        // ...

        // Get initial stats
        $client->request('GET', '/api/v1/rag/stats');
        $initialChunks = json_decode($client->getResponse()->getContent(), true)['total_chunks'];

        // Delete file
        $client->request('DELETE', '/api/v1/files/'.$fileId);
        $this->assertResponseIsSuccessful();

        // Verify vectors removed
        $client->request('GET', '/api/v1/rag/stats');
        $finalChunks = json_decode($client->getResponse()->getContent(), true)['total_chunks'];

        $this->assertLessThan($initialChunks, $finalChunks);
    }
}
```

### Chat RAG Integration

```php
// tests/Service/Message/Handler/ChatHandlerIntegrationTest.php

class ChatHandlerIntegrationTest extends KernelTestCase
{
    public function testChatWithRagContext(): void
    {
        // Upload and vectorize a file about "AI trends"
        // ...

        // Send chat message asking about AI trends
        $response = $this->chatHandler->handleStream(
            message: 'What are the latest AI trends?',
            userId: $this->testUserId,
            options: ['rag_group_key' => 'DEFAULT']
        );

        // RAG context should be included
        // (verify by checking system prompt or response)
    }
}
```

---

## Regression Checklist

### Before Release

- [ ] All unit tests pass for both backends
- [ ] Integration tests pass with MariaDB (default)
- [ ] Integration tests pass with Qdrant (if available)
- [ ] File upload → vectorization works
- [ ] File delete → vector cleanup works
- [ ] Widget file upload works
- [ ] Task prompt file linking works
- [ ] Chat RAG context retrieval works
- [ ] User isolation verified
- [ ] Group filtering verified
- [ ] Performance acceptable (search < 100ms)

### Manual Testing

1. **Upload Test**
   - Upload text file → verify chunks in stats
   - Upload PDF → verify extraction + chunks
   - Upload image → verify vision + single chunk

2. **Search Test**
   - Search for content in uploaded file
   - Verify results are relevant
   - Verify score ordering

3. **Delete Test**
   - Delete file → verify chunks removed
   - Check stats show decreased count

4. **Widget Test**
   - Upload file via widget
   - Chat via widget
   - Verify only widget files in context

5. **Switch Backend Test**
   - Set `VECTOR_STORAGE_PROVIDER=qdrant`
   - Restart backend
   - Repeat all tests above

---

## Performance Benchmarks

### Expected Performance

| Operation | MariaDB | Qdrant |
|-----------|---------|--------|
| Store 1 chunk | < 50ms | < 20ms |
| Store 100 chunks (batch) | < 2s | < 500ms |
| Search (1k chunks) | < 200ms | < 50ms |
| Search (10k chunks) | < 1s | < 100ms |
| Delete by file | < 100ms | < 50ms |

### Monitoring

```php
// Log performance metrics
$startTime = microtime(true);
$results = $this->vectorStorage->search(...);
$duration = (microtime(true) - $startTime) * 1000;

$this->logger->info('Vector search completed', [
    'backend' => $this->vectorStorage->getBackendType(),
    'results' => count($results),
    'duration_ms' => round($duration, 2),
    'user_id' => $userId,
    'group_key' => $groupKey,
]);
```

---

## Rollback Plan

### If Issues Detected

1. **Immediate Rollback**
   ```bash
   # Change .env
   VECTOR_STORAGE_PROVIDER=mariadb
   
   # Restart
   docker compose restart backend
   ```

2. **Data Preserved**
   - MariaDB data remains intact
   - Qdrant data also preserved (can switch back)

3. **No Data Loss**
   - Both backends can coexist
   - Migration command can re-sync if needed
