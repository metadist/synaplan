<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\SmartSearch\Index\FulltextQuery;
use App\Service\SmartSearch\Index\SearchDocument;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * DBAL access to BSEARCHINDEX (no Doctrine entity, see the migration).
 *
 * @phpstan-type IndexRow array{kind: string, refId: string, title: string, body: string, score: float}
 */
final readonly class SearchIndexRepository
{
    /** Title hits count twice as much as body hits. */
    private const TITLE_WEIGHT = 2;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Inserts or refreshes one row. A changed text clears the stored vector so
     * the semantic tier re-embeds it; an unchanged text keeps it.
     */
    public function upsert(SearchDocument $document): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BSEARCHINDEX (BUSERID, BKIND, BREFID, BTITLE, BBODY, BLANG, BHASH, BUPDATED)
                VALUES (:userId, :kind, :refId, :title, :body, :lang, :hash, :updated)
                ON DUPLICATE KEY UPDATE
                  BEMBED = IF(BHASH = VALUES(BHASH), BEMBED, NULL),
                  BEMBEDMODELID = IF(BHASH = VALUES(BHASH), BEMBEDMODELID, NULL),
                  BTITLE = VALUES(BTITLE),
                  BBODY = VALUES(BBODY),
                  BLANG = VALUES(BLANG),
                  BHASH = VALUES(BHASH),
                  BUPDATED = VALUES(BUPDATED)
            SQL,
            [
                'userId' => $document->userId,
                'kind' => $document->kind,
                'refId' => $document->refId,
                'title' => $document->title,
                'body' => $document->body,
                'lang' => $document->lang,
                'hash' => $document->hash(),
                'updated' => $document->updated,
            ],
            ['userId' => ParameterType::INTEGER, 'updated' => ParameterType::INTEGER],
        );
    }

    public function delete(int $userId, string $kind, string $refId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM BSEARCHINDEX WHERE BUSERID = :userId AND BKIND = :kind AND BREFID = :refId',
            ['userId' => $userId, 'kind' => $kind, 'refId' => $refId],
            ['userId' => ParameterType::INTEGER],
        );
    }

    /**
     * Removes the rows of one kind that are not in `$keepRefIds` (a full
     * re-index of a user drops items that no longer exist).
     *
     * @param list<string> $keepRefIds
     */
    public function deleteMissing(int $userId, string $kind, array $keepRefIds): int
    {
        if ([] === $keepRefIds) {
            return (int) $this->connection->executeStatement(
                'DELETE FROM BSEARCHINDEX WHERE BUSERID = :userId AND BKIND = :kind',
                ['userId' => $userId, 'kind' => $kind],
                ['userId' => ParameterType::INTEGER],
            );
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM BSEARCHINDEX WHERE BUSERID = :userId AND BKIND = :kind AND BREFID NOT IN (:keep)',
            ['userId' => $userId, 'kind' => $kind, 'keep' => $keepRefIds],
            ['userId' => ParameterType::INTEGER, 'keep' => ArrayParameterType::STRING],
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM BSEARCHINDEX WHERE BUSERID = :userId',
            ['userId' => $userId],
            ['userId' => ParameterType::INTEGER],
        );
    }

    /**
     * Keyword search over one user's rows, best first.
     *
     * @param list<string> $kinds
     *
     * @return list<IndexRow>
     */
    public function searchLexical(int $userId, FulltextQuery $query, array $kinds, int $limit): array
    {
        if ([] === $kinds) {
            return [];
        }

        if (!$query->hasTerms()) {
            return self::mapRows($this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT BKIND, BREFID, BTITLE, BBODY, 1 AS score
                    FROM BSEARCHINDEX
                    WHERE BUSERID = :userId
                      AND BKIND IN (:kinds)
                      AND BTITLE LIKE :like
                    ORDER BY BUPDATED DESC
                    LIMIT :limit
                SQL,
                ['userId' => $userId, 'kinds' => $kinds, 'limit' => $limit, 'like' => $query->likePattern()],
                ['userId' => ParameterType::INTEGER, 'kinds' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
            ));
        }

        $rows = $this->matchFulltext($userId, $query->booleanExpression(), $kinds, $limit);
        $relaxed = $query->relaxedExpression();
        if (null === $relaxed || count($rows) >= $limit) {
            return $rows;
        }

        // Rows that match only some words rank after the rows that match all.
        $seen = [];
        foreach ($rows as $row) {
            $seen[$row['kind'].':'.$row['refId']] = true;
        }
        foreach ($this->matchFulltext($userId, $relaxed, $kinds, $limit) as $row) {
            if (count($rows) >= $limit) {
                break;
            }
            if (!isset($seen[$row['kind'].':'.$row['refId']])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $kinds
     *
     * @return list<IndexRow>
     */
    private function matchFulltext(int $userId, string $expression, array $kinds, int $limit): array
    {
        $sql = sprintf(
            <<<'SQL'
                SELECT BKIND, BREFID, BTITLE, BBODY,
                  (MATCH(BTITLE) AGAINST(:q IN BOOLEAN MODE) * %d
                    + MATCH(BTITLE, BBODY) AGAINST(:q IN BOOLEAN MODE)) AS score
                FROM BSEARCHINDEX
                WHERE BUSERID = :userId
                  AND BKIND IN (:kinds)
                  AND MATCH(BTITLE, BBODY) AGAINST(:q IN BOOLEAN MODE)
                ORDER BY score DESC, BUPDATED DESC
                LIMIT :limit
            SQL,
            self::TITLE_WEIGHT,
        );

        return self::mapRows($this->connection->fetchAllAssociative(
            $sql,
            ['userId' => $userId, 'kinds' => $kinds, 'limit' => $limit, 'q' => $expression],
            ['userId' => ParameterType::INTEGER, 'kinds' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        ));
    }

    /**
     * Rows of one user that have no vector for `$modelId` yet (new, changed,
     * or embedded by a model that is no longer the search embedding model).
     *
     * @return list<array{id: int, title: string, body: string, hash: string}>
     */
    public function findPendingEmbeddings(int $userId, int $modelId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT BID, BTITLE, BBODY, BHASH
                FROM BSEARCHINDEX
                WHERE BUSERID = :userId
                  AND (BEMBED IS NULL OR BEMBEDMODELID IS NULL OR BEMBEDMODELID <> :modelId)
                ORDER BY BUPDATED DESC
                LIMIT :limit
            SQL,
            ['userId' => $userId, 'modelId' => $modelId, 'limit' => $limit],
            ['userId' => ParameterType::INTEGER, 'modelId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['BID'],
            'title' => (string) $row['BTITLE'],
            'body' => (string) $row['BBODY'],
            'hash' => (string) $row['BHASH'],
        ], $rows);
    }

    /**
     * Rows per owner that still need a vector from `$modelId`.
     *
     * @return array<int, int> owner id => pending rows
     */
    public function pendingCountsByUser(int $modelId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT BUSERID, COUNT(*) AS PENDING
                FROM BSEARCHINDEX
                WHERE BEMBED IS NULL OR BEMBEDMODELID IS NULL OR BEMBEDMODELID <> :modelId
                GROUP BY BUSERID
            SQL,
            ['modelId' => $modelId],
            ['modelId' => ParameterType::INTEGER],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['BUSERID']] = (int) $row['PENDING'];
        }

        return $counts;
    }

    /**
     * @return array{rows: int, embedded: int} embedded counts rows with a vector from `$modelId`
     */
    public function embeddingCoverage(?int $modelId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS ROWS_TOTAL, COALESCE(SUM(BEMBED IS NOT NULL AND BEMBEDMODELID = :modelId), 0) AS ROWS_EMBEDDED FROM BSEARCHINDEX',
            ['modelId' => $modelId ?? 0],
            ['modelId' => ParameterType::INTEGER],
        );

        return [
            'rows' => (int) ($row['ROWS_TOTAL'] ?? 0),
            'embedded' => (int) ($row['ROWS_EMBEDDED'] ?? 0),
        ];
    }

    /**
     * Stores a vector unless the row's text changed after it was read
     * (`$hash` guard), so a slow embed never overwrites a newer text.
     *
     * @param list<float> $vector
     */
    public function storeEmbedding(int $id, string $hash, array $vector, int $modelId): void
    {
        $this->connection->executeStatement(
            'UPDATE BSEARCHINDEX SET BEMBED = VEC_FromText(:vector), BEMBEDMODELID = :modelId WHERE BID = :id AND BHASH = :hash',
            ['vector' => self::vectorText($vector), 'modelId' => $modelId, 'id' => $id, 'hash' => $hash],
            ['modelId' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Meaning search over one user's rows, nearest first. `score` is the
     * cosine similarity (1 = same direction).
     *
     * @param list<float>  $vector
     * @param list<string> $kinds
     *
     * @return list<IndexRow>
     */
    public function searchSemantic(int $userId, array $vector, int $modelId, array $kinds, int $limit, float $minScore): array
    {
        if ([] === $kinds || [] === $vector) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT BKIND, BREFID, BTITLE, BBODY, 1 - VEC_DISTANCE_COSINE(BEMBED, VEC_FromText(:vector)) AS score
                FROM BSEARCHINDEX
                WHERE BUSERID = :userId
                  AND BKIND IN (:kinds)
                  AND BEMBEDMODELID = :modelId
                  AND BEMBED IS NOT NULL
                HAVING score >= :minScore
                ORDER BY score DESC
                LIMIT :limit
            SQL,
            [
                'vector' => self::vectorText($vector),
                'userId' => $userId,
                'kinds' => $kinds,
                'modelId' => $modelId,
                'minScore' => $minScore,
                'limit' => $limit,
            ],
            [
                'userId' => ParameterType::INTEGER,
                'kinds' => ArrayParameterType::STRING,
                'modelId' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ],
        );

        return self::mapRows($rows);
    }

    /**
     * Mean cosine similarity of `$vector` to every row of one owner that was
     * embedded by `$modelId`; null when there are none.
     *
     * @param list<float> $vector
     */
    public function averageSimilarity(int $userId, array $vector, int $modelId): ?float
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT AVG(1 - VEC_DISTANCE_COSINE(BEMBED, VEC_FromText(:vector)))
                FROM BSEARCHINDEX
                WHERE BUSERID = :userId AND BEMBEDMODELID = :modelId AND BEMBED IS NOT NULL
            SQL,
            ['vector' => self::vectorText($vector), 'userId' => $userId, 'modelId' => $modelId],
            ['userId' => ParameterType::INTEGER, 'modelId' => ParameterType::INTEGER],
        );

        return null === $value || false === $value ? null : (float) $value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<IndexRow>
     */
    private static function mapRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'kind' => (string) $row['BKIND'],
            'refId' => (string) $row['BREFID'],
            'title' => (string) $row['BTITLE'],
            'body' => (string) $row['BBODY'],
            'score' => (float) $row['score'],
        ], $rows);
    }

    /**
     * @param list<float> $vector
     */
    private static function vectorText(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }
}
