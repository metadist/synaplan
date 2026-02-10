# Backend Implementations

## Overview

Detailed implementation specifications for MariaDB and Qdrant vector storage backends.

---

## MariaDBVectorStorage

Extracts existing functionality from `VectorizationService` and `VectorSearchService` into the new interface.

### Implementation

```php
// src/Service/RAG/VectorStorage/MariaDBVectorStorage.php

namespace App\Service\RAG\VectorStorage;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class MariaDBVectorStorage implements VectorStorageInterface
{
    private const VECTOR_DIMENSION = 1024;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {}

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
    ): string {
        $conn = $this->entityManager->getConnection();
        
        // Pad/truncate vector to expected dimensions
        $normalizedVector = $this->normalizeVector($vector);
        $vectorString = '['.implode(',', $normalizedVector).']';
        
        $sql = <<<SQL
            INSERT INTO BRAG (BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BTEXT, BEMBED, BCREATED)
            VALUES (:uid, :mid, :groupKey, :type, :start, :end, :text, VEC_FromText(:vec), :created)
        SQL;
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('uid', $userId);
        $stmt->bindValue('mid', $fileId);
        $stmt->bindValue('groupKey', $groupKey);
        $stmt->bindValue('type', $fileType);
        $stmt->bindValue('start', $startLine);
        $stmt->bindValue('end', $endLine);
        $stmt->bindValue('text', $text);
        $stmt->bindValue('vec', $vectorString);
        $stmt->bindValue('created', time());
        $stmt->executeStatement();
        
        return (string) $conn->lastInsertId();
    }

    public function storeChunkBatch(int $userId, array $chunks): array
    {
        $ids = [];
        foreach ($chunks as $chunk) {
            $ids[] = $this->storeChunk(
                $userId,
                $chunk['file_id'],
                $chunk['group_key'],
                $chunk['file_type'],
                $chunk['chunk_index'],
                $chunk['start_line'],
                $chunk['end_line'],
                $chunk['text'],
                $chunk['vector']
            );
        }
        return $ids;
    }

    public function deleteByFile(int $userId, int $fileId): int
    {
        $conn = $this->entityManager->getConnection();
        $sql = 'DELETE FROM BRAG WHERE BUID = :uid AND BMID = :mid';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('uid', $userId);
        $stmt->bindValue('mid', $fileId);
        return $stmt->executeStatement();
    }

    public function deleteByGroupKey(int $userId, string $groupKey): int
    {
        $conn = $this->entityManager->getConnection();
        $sql = 'DELETE FROM BRAG WHERE BUID = :uid AND BGROUPKEY = :groupKey';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('uid', $userId);
        $stmt->bindValue('groupKey', $groupKey);
        return $stmt->executeStatement();
    }

    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        $conn = $this->entityManager->getConnection();
        $sql = 'UPDATE BRAG SET BGROUPKEY = :newKey WHERE BUID = :uid AND BMID = :mid';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('newKey', $newGroupKey);
        $stmt->bindValue('uid', $userId);
        $stmt->bindValue('mid', $fileId);
        return $stmt->executeStatement();
    }

    public function search(
        int $userId,
        array $queryVector,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3
    ): array {
        $conn = $this->entityManager->getConnection();
        
        $normalizedVector = $this->normalizeVector($queryVector);
        $vectorString = '['.implode(',', $normalizedVector).']';
        $maxDistance = 1.0 - $minScore; // Convert score to distance
        
        $sql = <<<SQL
            SELECT 
                r.BID as chunk_id,
                r.BMID as file_id,
                r.BGROUPKEY as group_key,
                r.BSTART as start_line,
                r.BEND as end_line,
                r.BTEXT as text,
                f.BFILENAME as filename,
                VEC_DISTANCE_COSINE(r.BEMBED, VEC_FromText(:vec)) as distance
            FROM BRAG r
            LEFT JOIN BFILES f ON f.BID = r.BMID
            WHERE r.BUID = :uid
        SQL;
        
        if ($groupKey !== null) {
            $sql .= ' AND r.BGROUPKEY = :groupKey';
        }
        
        $sql .= <<<SQL
            HAVING distance <= :maxDistance
            ORDER BY distance ASC
            LIMIT :limit
        SQL;
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('vec', $vectorString);
        $stmt->bindValue('uid', $userId);
        $stmt->bindValue('maxDistance', $maxDistance);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        
        if ($groupKey !== null) {
            $stmt->bindValue('groupKey', $groupKey);
        }
        
        $results = $stmt->executeQuery()->fetchAllAssociative();
        
        return array_map(fn($row) => new SearchResult(
            chunkId: (string) $row['chunk_id'],
            fileId: (int) $row['file_id'],
            groupKey: $row['group_key'],
            startLine: (int) $row['start_line'],
            endLine: (int) $row['end_line'],
            text: $row['text'],
            score: 1.0 - (float) $row['distance'], // Convert distance to score
            filename: $row['filename'],
        ), $results);
    }

    public function getGroupKeys(int $userId): array
    {
        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT DISTINCT BGROUPKEY FROM BRAG WHERE BUID = :uid ORDER BY BGROUPKEY';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('uid', $userId);
        return $stmt->executeQuery()->fetchFirstColumn();
    }

    public function getStats(int $userId): array
    {
        $conn = $this->entityManager->getConnection();
        
        // Total chunks
        $stmt = $conn->prepare('SELECT COUNT(*) FROM BRAG WHERE BUID = :uid');
        $stmt->bindValue('uid', $userId);
        $totalChunks = (int) $stmt->executeQuery()->fetchOne();
        
        // Distinct files
        $stmt = $conn->prepare('SELECT COUNT(DISTINCT BMID) FROM BRAG WHERE BUID = :uid');
        $stmt->bindValue('uid', $userId);
        $totalFiles = (int) $stmt->executeQuery()->fetchOne();
        
        // Groups with counts
        $stmt = $conn->prepare(<<<SQL
            SELECT BGROUPKEY, COUNT(*) as chunk_count, COUNT(DISTINCT BMID) as file_count
            FROM BRAG WHERE BUID = :uid
            GROUP BY BGROUPKEY ORDER BY BGROUPKEY
        SQL);
        $stmt->bindValue('uid', $userId);
        $groups = $stmt->executeQuery()->fetchAllAssociative();
        
        return [
            'total_chunks' => $totalChunks,
            'total_files' => $totalFiles,
            'groups' => $groups,
        ];
    }

    public function isHealthy(): bool
    {
        try {
            $conn = $this->entityManager->getConnection();
            $conn->executeQuery('SELECT 1');
            return true;
        } catch (\Exception $e) {
            $this->logger->error('MariaDB health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getBackendType(): string
    {
        return 'mariadb';
    }

    private function normalizeVector(array $vector): array
    {
        $count = count($vector);
        
        if ($count === self::VECTOR_DIMENSION) {
            return $vector;
        }
        
        if ($count > self::VECTOR_DIMENSION) {
            // Truncate
            return array_slice($vector, 0, self::VECTOR_DIMENSION);
        }
        
        // Pad with zeros
        return array_merge($vector, array_fill(0, self::VECTOR_DIMENSION - $count, 0.0));
    }
}
```

---

## QdrantVectorStorage

HTTP client implementation for the qdrant-service.

### Implementation

```php
// src/Service/RAG/VectorStorage/QdrantVectorStorage.php

namespace App\Service\RAG\VectorStorage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final readonly class QdrantVectorStorage implements VectorStorageInterface
{
    public function __construct(
        private string $qdrantServiceUrl,
        private string $qdrantApiKey,
        private string $collectionName,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

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
    ): string {
        $pointId = sprintf('doc_%d_%d_%d', $userId, $fileId, $chunkIndex);
        
        $response = $this->request('POST', '/documents', [
            'point_id' => $pointId,
            'vector' => $vector,
            'payload' => [
                'user_id' => $userId,
                'file_id' => $fileId,
                'group_key' => $groupKey,
                'file_type' => $fileType,
                'chunk_index' => $chunkIndex,
                'start_line' => $startLine,
                'end_line' => $endLine,
                'text' => $text,
                'created' => time(),
            ],
        ]);
        
        return $response['point_id'] ?? $pointId;
    }

    public function storeChunkBatch(int $userId, array $chunks): array
    {
        $points = [];
        foreach ($chunks as $i => $chunk) {
            $pointId = sprintf('doc_%d_%d_%d', $userId, $chunk['file_id'], $chunk['chunk_index']);
            $points[] = [
                'point_id' => $pointId,
                'vector' => $chunk['vector'],
                'payload' => [
                    'user_id' => $userId,
                    'file_id' => $chunk['file_id'],
                    'group_key' => $chunk['group_key'],
                    'file_type' => $chunk['file_type'],
                    'chunk_index' => $chunk['chunk_index'],
                    'start_line' => $chunk['start_line'],
                    'end_line' => $chunk['end_line'],
                    'text' => $chunk['text'],
                    'created' => time(),
                ],
            ];
        }
        
        $response = $this->request('POST', '/documents/batch', [
            'points' => $points,
        ]);
        
        return array_map(fn($p) => $p['point_id'], $points);
    }

    public function deleteByFile(int $userId, int $fileId): int
    {
        $response = $this->request('DELETE', '/documents/by-file', [
            'user_id' => $userId,
            'file_id' => $fileId,
        ]);
        
        return $response['deleted'] ?? 0;
    }

    public function deleteByGroupKey(int $userId, string $groupKey): int
    {
        $response = $this->request('DELETE', '/documents/by-group', [
            'user_id' => $userId,
            'group_key' => $groupKey,
        ]);
        
        return $response['deleted'] ?? 0;
    }

    public function updateGroupKey(int $userId, int $fileId, string $newGroupKey): int
    {
        $response = $this->request('PATCH', '/documents/group-key', [
            'user_id' => $userId,
            'file_id' => $fileId,
            'new_group_key' => $newGroupKey,
        ]);
        
        return $response['updated'] ?? 0;
    }

    public function search(
        int $userId,
        array $queryVector,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3
    ): array {
        $response = $this->request('POST', '/documents/search', [
            'vector' => $queryVector,
            'user_id' => $userId,
            'group_key' => $groupKey,
            'limit' => $limit,
            'min_score' => $minScore,
        ]);
        
        $results = [];
        foreach ($response['results'] ?? [] as $hit) {
            $payload = $hit['payload'] ?? [];
            $results[] = new SearchResult(
                chunkId: $hit['id'] ?? '',
                fileId: (int) ($payload['file_id'] ?? 0),
                groupKey: $payload['group_key'] ?? '',
                startLine: (int) ($payload['start_line'] ?? 0),
                endLine: (int) ($payload['end_line'] ?? 0),
                text: $payload['text'] ?? '',
                score: (float) ($hit['score'] ?? 0),
                filename: $payload['filename'] ?? null,
            );
        }
        
        return $results;
    }

    public function getGroupKeys(int $userId): array
    {
        $response = $this->request('GET', '/documents/groups', [
            'user_id' => $userId,
        ]);
        
        return $response['groups'] ?? [];
    }

    public function getStats(int $userId): array
    {
        $response = $this->request('GET', '/documents/stats', [
            'user_id' => $userId,
        ]);
        
        return [
            'total_chunks' => $response['total_chunks'] ?? 0,
            'total_files' => $response['total_files'] ?? 0,
            'groups' => $response['groups'] ?? [],
        ];
    }

    public function isHealthy(): bool
    {
        if (empty($this->qdrantServiceUrl)) {
            return false;
        }
        
        try {
            $response = $this->httpClient->request('GET', $this->qdrantServiceUrl.'/health');
            $data = $response->toArray();
            return ($data['status'] ?? '') === 'healthy';
        } catch (\Exception $e) {
            $this->logger->error('Qdrant health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getBackendType(): string
    {
        return 'qdrant';
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $options = [
            'headers' => [
                'X-API-Key' => $this->qdrantApiKey,
                'Content-Type' => 'application/json',
            ],
        ];
        
        if ($method === 'GET' && !empty($data)) {
            $options['query'] = $data;
        } elseif (!empty($data)) {
            $options['json'] = $data;
        }
        
        try {
            $response = $this->httpClient->request(
                $method,
                $this->qdrantServiceUrl.$path,
                $options
            );
            
            return $response->toArray();
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Qdrant request failed', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Vector storage unavailable: '.$e->getMessage());
        }
    }
}
```

---

## Qdrant Service Extensions

New endpoints to add to `synaplan-memories/qdrant-service/`.

### New Routes (main.rs)

```rust
// Add to existing router
.route("/documents", post(handlers::upsert_document))
.route("/documents/batch", post(handlers::batch_upsert_documents))
.route("/documents/search", post(handlers::search_documents))
.route("/documents/by-file", delete(handlers::delete_by_file))
.route("/documents/by-group", delete(handlers::delete_by_group))
.route("/documents/group-key", patch(handlers::update_group_key))
.route("/documents/groups", get(handlers::get_groups))
.route("/documents/stats", get(handlers::get_document_stats))
```

### New Models (models.rs)

```rust
// Document payload (separate from MemoryPayload)
#[derive(Serialize, Deserialize, ToSchema, Clone)]
pub struct DocumentPayload {
    pub user_id: i64,
    pub file_id: i64,
    pub group_key: String,
    pub file_type: i32,
    pub chunk_index: i32,
    pub start_line: i32,
    pub end_line: i32,
    pub text: String,
    pub created: i64,
}

#[derive(Deserialize, ToSchema)]
pub struct UpsertDocumentRequest {
    pub point_id: String,
    pub vector: Vec<f32>,
    pub payload: DocumentPayload,
}

#[derive(Deserialize, ToSchema)]
pub struct BatchUpsertDocumentsRequest {
    pub points: Vec<UpsertDocumentRequest>,
}

#[derive(Deserialize, ToSchema)]
pub struct SearchDocumentsRequest {
    pub vector: Vec<f32>,
    pub user_id: i64,
    #[serde(default)]
    pub group_key: Option<String>,
    #[serde(default = "default_limit")]
    pub limit: u64,
    #[serde(default = "default_min_score")]
    pub min_score: f32,
}

#[derive(Deserialize, ToSchema)]
pub struct DeleteByFileRequest {
    pub user_id: i64,
    pub file_id: i64,
}

#[derive(Deserialize, ToSchema)]
pub struct DeleteByGroupRequest {
    pub user_id: i64,
    pub group_key: String,
}

#[derive(Deserialize, ToSchema)]
pub struct UpdateGroupKeyRequest {
    pub user_id: i64,
    pub file_id: i64,
    pub new_group_key: String,
}

fn default_limit() -> u64 { 10 }
fn default_min_score() -> f32 { 0.3 }
```

### Collection Configuration (config.rs)

```rust
// Add to Config struct
pub documents_collection_name: String,  // Default: "user_documents"

// Collection initialization
pub async fn ensure_documents_collection(&self) -> Result<()> {
    let collection_name = &self.config.documents_collection_name;
    
    // Same HNSW config as memories, but can be tuned differently
    let vectors_config = VectorsConfig::Single(VectorParams {
        size: self.config.vector_size as u64,
        distance: Distance::Cosine,
        hnsw_config: Some(HnswConfigDiff {
            m: Some(16),           // Higher for better recall on larger datasets
            ef_construct: Some(100),
            ..Default::default()
        }),
        ..Default::default()
    });
    
    self.client
        .create_collection(collection_name, vectors_config)
        .await
        .map_err(|e| Error::Qdrant(e.to_string()))?;
    
    Ok(())
}
```
