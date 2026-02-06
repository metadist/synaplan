<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use App\Service\VectorSearch\QdrantClientHttp;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Migrates vector data from MariaDB BRAG table to Qdrant.
 *
 * Reuses existing embeddings — no re-vectorization needed.
 */
final readonly class VectorMigrationService
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private Connection $connection,
        private QdrantVectorStorage $qdrantStorage,
        private QdrantClientHttp $qdrantClient,
        private MariaDBVectorStorage $mariaDbStorage,
        private VectorStorageConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check if a file has chunks in MariaDB that are not yet in Qdrant.
     *
     * @return array{needsMigration: bool, mariadbChunks: int, qdrantChunks: int, groupKey: string|null}
     */
    public function getFileMigrationStatus(int $userId, int $fileId): array
    {
        $mariaInfo = $this->mariaDbStorage->getFileChunkInfo($userId, $fileId);
        $mariaChunks = $mariaInfo['chunks'];
        $groupKey = $mariaInfo['groupKey'];

        $qdrantChunks = 0;
        if ($this->config->isQdrantEnabled() && $this->qdrantClient->isAvailable()) {
            try {
                // Fast check: probe only the first chunk (doc_{userId}_{fileId}_0)
                $firstPointId = sprintf('doc_%d_%d_0', $userId, $fileId);
                $doc = $this->qdrantClient->getDocument($firstPointId);
                if (null !== $doc) {
                    $qdrantChunks = 1; // At least one chunk exists
                    $groupKey = $doc['payload']['group_key'] ?? $groupKey;
                }
            } catch (\Throwable) {
                // Qdrant unavailable — treat as 0
            }
        }

        return [
            'needsMigration' => $mariaChunks > 0 && 0 === $qdrantChunks && $this->config->isQdrantEnabled(),
            'mariadbChunks' => $mariaChunks,
            'qdrantChunks' => $qdrantChunks,
            'groupKey' => $groupKey,
        ];
    }

    /**
     * Migrate a single file's vectors from MariaDB to Qdrant.
     *
     * @return array{migrated: int, errors: int}
     */
    public function migrateFile(int $userId, int $fileId): array
    {
        $sql = <<<'SQL'
            SELECT BID, BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BTEXT, VEC_ToText(BEMBED) AS BEMBED, BCREATED
            FROM BRAG
            WHERE BUID = :userId AND BMID = :fileId
            ORDER BY BSTART ASC
        SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('fileId', $fileId);
        $rows = $stmt->executeQuery()->fetchAllAssociative();

        if (empty($rows)) {
            return ['migrated' => 0, 'errors' => 0];
        }

        $this->logger->info('Migrating file vectors MariaDB→Qdrant', [
            'user_id' => $userId,
            'file_id' => $fileId,
            'chunks' => count($rows),
        ]);

        $chunks = [];
        $chunkIndex = 0;

        foreach ($rows as $row) {
            $vector = $this->decodeVector($row['BEMBED']);
            if (null === $vector || empty($vector)) {
                $this->logger->warning('Skipping chunk with empty vector', ['bid' => $row['BID']]);
                continue;
            }

            $chunks[] = new VectorChunk(
                userId: (int) $row['BUID'],
                fileId: (int) $row['BMID'],
                groupKey: $row['BGROUPKEY'],
                fileType: (int) $row['BTYPE'],
                chunkIndex: $chunkIndex,
                startLine: (int) $row['BSTART'],
                endLine: (int) $row['BEND'],
                text: $row['BTEXT'],
                vector: $vector,
                createdAt: (int) $row['BCREATED'],
            );

            ++$chunkIndex;
        }

        if (empty($chunks)) {
            return ['migrated' => 0, 'errors' => 0];
        }

        // Batch upsert to Qdrant
        $migrated = 0;
        $errors = 0;

        foreach (array_chunk($chunks, self::BATCH_SIZE) as $batch) {
            try {
                $migrated += $this->qdrantStorage->storeChunkBatch($batch);
            } catch (\Throwable $e) {
                $errors += count($batch);
                $this->logger->error('Migration batch failed', [
                    'user_id' => $userId,
                    'file_id' => $fileId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('File migration complete', [
            'user_id' => $userId,
            'file_id' => $fileId,
            'migrated' => $migrated,
            'errors' => $errors,
        ]);

        return ['migrated' => $migrated, 'errors' => $errors];
    }

    /**
     * Decode MariaDB VECTOR column to float array.
     *
     * @return float[]|null
     */
    private function decodeVector(mixed $raw): ?array
    {
        if (null === $raw) {
            return null;
        }

        // Doctrine VectorType returns array directly
        if (is_array($raw)) {
            return $raw;
        }

        // Raw string from DBAL: could be JSON-like "[0.1,0.2,...]" or binary
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
