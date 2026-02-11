<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Repository\RagDocumentRepository;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use Doctrine\DBAL\Connection;

final readonly class MariaDBVectorStorage implements VectorStorageInterface
{
    public function __construct(
        private RagDocumentRepository $ragRepository,
        private Connection $connection,
    ) {
    }

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
        $stmt->bindValue('created', $chunk->getCreatedTimestamp());
        $stmt->executeStatement();

        return (string) $this->connection->lastInsertId();
    }

    public function storeChunkBatch(array $chunks): int
    {
        if (empty($chunks)) {
            return 0;
        }

        $stored = 0;
        $this->connection->beginTransaction();

        try {
            foreach ($chunks as $chunk) {
                $this->storeChunk($chunk);
                ++$stored;
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $stored;
    }

    public function deleteByFile(int $userId, int $fileId): int
    {
        $sql = 'DELETE FROM BRAG WHERE BUID = :userId AND BMID = :fileId';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('fileId', $fileId);

        return $stmt->executeStatement();
    }

    public function deleteByGroupKey(int $userId, string $groupKey): int
    {
        $sql = 'DELETE FROM BRAG WHERE BUID = :userId AND BGROUPKEY = :groupKey';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('groupKey', $groupKey);

        return $stmt->executeStatement();
    }

    public function deleteAllForUser(int $userId): int
    {
        $sql = 'DELETE FROM BRAG WHERE BUID = :userId';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $userId);

        return $stmt->executeStatement();
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
                f.BFILEMIME as mime_type
            FROM BRAG r
            LEFT JOIN BFILES f ON r.BMID = f.BID
            WHERE r.BUID = :userId
        SQL;

        if (null !== $query->groupKey) {
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

        if (null !== $query->groupKey) {
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
            vector: $sourceDoc->getEmbedding(),
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

    public function getFileChunkInfo(int $userId, int $fileId): array
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) as chunks, MIN(BGROUPKEY) as group_key
            FROM BRAG
            WHERE BUID = :userId AND BMID = :fileId
        SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('fileId', $fileId);
        $result = $stmt->executeQuery()->fetchAssociative();

        return [
            'chunks' => (int) ($result['chunks'] ?? 0),
            'groupKey' => $result['group_key'] ?? null,
        ];
    }

    public function getFileIdsByGroupKey(int $userId, string $groupKey): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT BMID as file_id
            FROM BRAG
            WHERE BUID = :userId AND BGROUPKEY = :groupKey
        SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('groupKey', $groupKey);
        $rows = $stmt->executeQuery()->fetchAllAssociative();

        return array_map(fn (array $row) => (int) $row['file_id'], $rows);
    }

    public function getFilesWithChunks(int $userId): array
    {
        $sql = <<<'SQL'
            SELECT BMID as file_id, COUNT(*) as chunks, MIN(BGROUPKEY) as group_key
            FROM BRAG
            WHERE BUID = :userId
            GROUP BY BMID
        SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $userId);
        $rows = $stmt->executeQuery()->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['file_id']] = [
                'chunks' => (int) $row['chunks'],
                'groupKey' => $row['group_key'],
            ];
        }

        return $result;
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
