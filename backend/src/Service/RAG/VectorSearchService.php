<?php

namespace App\Service\RAG;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class VectorSearchService
{
    private Connection $connection;

    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger
    ) {
        $this->connection = $em->getConnection();
    }

    /**
     * Semantic search using vector embeddings
     * 
     * @param string $query Search query
     * @param int $userId User ID for filtering
     * @param string|null $groupKey Optional group filter
     * @param int $limit Number of results (default: 10)
     * @param float $minScore Minimum similarity score (0-1, default: 0.3)
     * @return array Top-K similar documents
     */
    public function semanticSearch(
        string $query,
        int $userId,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3
    ): array {
        // 1. Get embedding model from DB
        $embeddingModelId = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);
        
        if (!$embeddingModelId) {
            $this->logger->error('VectorSearchService: No embedding model configured');
            return [];
        }

        // Get model details (name, provider)
        $model = $this->em->getRepository('App\Entity\Model')->find($embeddingModelId);
        if (!$model) {
            $this->logger->error('VectorSearchService: Model not found', ['model_id' => $embeddingModelId]);
            return [];
        }

        $modelName = $model->getProviderId(); // BPROVID contains the actual model name (e.g., 'bge-m3')
        $provider = strtolower($model->getService()); // BSERVICE contains provider name, normalize to lowercase (e.g., 'ollama')

        $this->logger->info('VectorSearchService: Starting semantic search', [
            'user_id' => $userId,
            'query_length' => strlen($query),
            'model_id' => $embeddingModelId,
            'model_name' => $modelName,
            'provider' => $provider
        ]);

        // 2. Embed the query with model details
        $queryEmbedding = $this->aiFacade->embed($query, $userId, [
            'model' => $modelName,
            'provider' => $provider
        ]);
        
        if (empty($queryEmbedding)) {
            $this->logger->error('VectorSearchService: Failed to embed query');
            return [];
        }

        // 2. Convert to MariaDB VECTOR format
        $vectorStr = '[' . implode(',', array_map('floatval', $queryEmbedding)) . ']';

        // 3. Build SQL with VEC_DISTANCE_COSINE (native MariaDB)
        $sql = '
            SELECT 
                r.BID as chunk_id,
                r.BMID as message_id,
                r.BTEXT as chunk_text,
                r.BSTART as start_line,
                r.BEND as end_line,
                r.BGROUPKEY as group_key,
                VEC_DISTANCE_COSINE(r.BEMBED, VEC_FromText(:query_vector)) as distance,
                m.BTEXT as message_text,
                m.BFILEPATH as message_file_path,
                m.BFILETYPE as message_file_type,
                f.BFILENAME as file_name,
                f.BFILEPATH as file_path,
                f.BFILEMIME as file_mime,
                f.BFILETEXT as file_text
            FROM BRAG r
            LEFT JOIN BMESSAGES m ON r.BMID = m.BID
            LEFT JOIN BFILES f ON r.BMID = f.BID
            WHERE r.BUID = :user_id
        ';

        $params = [
            'query_vector' => $vectorStr,
            'user_id' => $userId
        ];

        // 4. Optional: Filter by group
        if ($groupKey) {
            $sql .= ' AND r.BGROUPKEY = :group_key';
            $params['group_key'] = $groupKey;
        }

        // 5. Filter by min_score (convert to max distance)
        // VEC_DISTANCE_COSINE: 0 = identical, 1 = different
        // So minScore=0.7 means we want distance <= 0.3
        $maxDistance = 1.0 - $minScore;
        $sql .= ' HAVING distance <= :max_distance';
        $params['max_distance'] = $maxDistance;

        // 6. Order by distance (lower = more similar) and limit
        $sql .= '
            ORDER BY distance ASC
            LIMIT :limit
        ';
        $params['limit'] = $limit;

        // 7. Execute query
        try {
            $stmt = $this->connection->prepare($sql);
            
            // Bind parameters
            foreach ($params as $key => $value) {
                if ($key === 'limit') {
                    $stmt->bindValue($key, $value, \PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $result = $stmt->executeQuery();
            $results = $result->fetchAllAssociative();

            // Transform: Convert distance (0=identical) to score (1=identical)
            $results = array_map(function($row) {
                $row['distance'] = 1.0 - (float)$row['distance'];
                return $row;
            }, $results);

            $this->logger->info('VectorSearchService: Semantic search completed', [
                'user_id' => $userId,
                'query_length' => strlen($query),
                'results_count' => count($results),
                'group_key' => $groupKey,
                'min_score' => $minScore
            ]);

            return $results;

        } catch (\Throwable $e) {
            $this->logger->error('VectorSearchService: Search failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return [];
        }
    }

    /**
     * Get user RAG statistics
     */
    public function getUserStats(int $userId): array
    {
        try {
            $sql = '
                SELECT 
                    COUNT(DISTINCT r.BMID) as total_documents,
                    COUNT(r.BID) as total_chunks,
                    COUNT(DISTINCT r.BGROUPKEY) as total_groups,
                    AVG(CHAR_LENGTH(r.BTEXT)) as avg_chunk_size
                FROM BRAG r
                WHERE r.BUID = :user_id
            ';
            
            $result = $this->connection->executeQuery($sql, ['user_id' => $userId]);
            $stats = $result->fetchAssociative();
            
            return [
                'total_documents' => (int)($stats['total_documents'] ?? 0),
                'total_chunks' => (int)($stats['total_chunks'] ?? 0),
                'total_groups' => (int)($stats['total_groups'] ?? 0),
                'avg_chunk_size' => (int)($stats['avg_chunk_size'] ?? 0)
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('VectorSearchService: getUserStats failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return [
                'total_documents' => 0,
                'total_chunks' => 0,
                'total_groups' => 0,
                'avg_chunk_size' => 0
            ];
        }
    }

    /**
     * Find similar documents based on a source message ID
     * 
     * @param int $sourceMessageId Source message to find similar documents for
     * @param int $userId User ID for filtering
     * @param int $limit Number of results
     * @return array Similar documents
     */
    public function findSimilar(
        int $sourceMessageId,
        int $userId,
        int $limit = 10
    ): array {
        try {
            // Get the embedding of the source message's first chunk
            $sql = '
                SELECT 
                    r2.BID as chunk_id,
                    r2.BMID as message_id,
                    r2.BTEXT as chunk_text,
                    VEC_DISTANCE_COSINE(r2.BEMBED, r1.BEMBED) as distance
                FROM BRAG r1
                CROSS JOIN BRAG r2
                WHERE r1.BMID = :source_mid
                    AND r2.BUID = :user_id
                    AND r2.BMID != :source_mid
                ORDER BY distance ASC
                LIMIT :limit
            ';

            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('source_mid', $sourceMessageId, \PDO::PARAM_INT);
            $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);

            $result = $stmt->executeQuery();
            $results = $result->fetchAllAssociative();

            // Transform: Convert distance to score
            $results = array_map(function($row) {
                $row['distance'] = 1.0 - (float)$row['distance'];
                return $row;
            }, $results);

            return $results;

        } catch (\Throwable $e) {
            $this->logger->error('VectorSearchService: Find similar failed', [
                'error' => $e->getMessage(),
                'source_mid' => $sourceMessageId,
                'user_id' => $userId
            ]);
            return [];
        }
    }
}
