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
 * Searches read the caller's own rows plus, through `$sharedRefs`, the
 * owner's rows of items shared with the caller. Callers must re-check access
 * on every hit (see SearchDocumentSourceInterface::resolve()).
 *
 * @phpstan-type IndexRow array{kind: string, refId: string, title: string, body: string, score: float}
 * @phpstan-type SharedRefs array<string, list<string>>
 */
final readonly class SearchIndexRepository
{
    /** Title hits count twice as much as body hits. */
    private const TITLE_WEIGHT = 2;
    /** Keeps `NOT IN (...)` lists and packets small on large accounts. */
    private const DELETE_CHUNK = 500;

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

    /** Every row of one person (account deletion). */
    public function deleteByUser(int $userId): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM BSEARCHINDEX WHERE BUSERID = :userId',
            ['userId' => $userId],
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
        $keep = array_flip($keepRefIds);
        $stale = [];
        foreach ($this->refIdsOf($userId, $kind) as $refId) {
            if (!isset($keep[$refId])) {
                $stale[] = $refId;
            }
        }

        $deleted = 0;
        foreach (array_chunk($stale, self::DELETE_CHUNK) as $chunk) {
            $deleted += (int) $this->connection->executeStatement(
                'DELETE FROM BSEARCHINDEX WHERE BUSERID = :userId AND BKIND = :kind AND BREFID IN (:refIds)',
                ['userId' => $userId, 'kind' => $kind, 'refIds' => $chunk],
                ['userId' => ParameterType::INTEGER, 'refIds' => ArrayParameterType::STRING],
            );
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function refIdsOf(int $userId, string $kind): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT BREFID FROM BSEARCHINDEX WHERE BUSERID = :userId AND BKIND = :kind',
            ['userId' => $userId, 'kind' => $kind],
            ['userId' => ParameterType::INTEGER],
        ));
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
     * Keyword search over one user's rows (and the rows shared with them), best first.
     *
     * @param list<string> $kinds
     * @param SharedRefs   $sharedRefs
     *
     * @return list<IndexRow>
     */
    public function searchLexical(int $userId, FulltextQuery $query, array $kinds, int $limit, array $sharedRefs = []): array
    {
        if ([] === $kinds) {
            return [];
        }

        if (!$query->hasTerms()) {
            [$scope, $params, $types] = self::scope($userId, $sharedRefs);

            return self::mapRows($this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT BKIND, BREFID, BTITLE, BBODY, 1 AS score
                    FROM BSEARCHINDEX
                    WHERE {$scope}
                      AND BKIND IN (:kinds)
                      AND BTITLE LIKE :like
                    ORDER BY BUPDATED DESC
                    LIMIT :limit
                SQL,
                $params + ['kinds' => $kinds, 'limit' => $limit, 'like' => $query->likePattern()],
                $types + ['kinds' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
            ));
        }

        $rows = $this->matchFulltext($userId, $query->booleanExpression(), $kinds, $limit, $sharedRefs);
        $relaxed = $query->relaxedExpression();
        if (null === $relaxed || count($rows) >= $limit) {
            return $rows;
        }

        // Rows that match only some words rank after the rows that match all.
        $seen = [];
        foreach ($rows as $row) {
            $seen[$row['kind'].':'.$row['refId']] = true;
        }
        foreach ($this->matchFulltext($userId, $relaxed, $kinds, $limit, $sharedRefs) as $row) {
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
     * @param SharedRefs   $sharedRefs
     *
     * @return list<IndexRow>
     */
    private function matchFulltext(int $userId, string $expression, array $kinds, int $limit, array $sharedRefs): array
    {
        [$scope, $params, $types] = self::scope($userId, $sharedRefs);
        $sql = sprintf(
            <<<SQL
                SELECT BKIND, BREFID, BTITLE, BBODY,
                  (MATCH(BTITLE) AGAINST(:q IN BOOLEAN MODE) * %d
                    + MATCH(BTITLE, BBODY) AGAINST(:q IN BOOLEAN MODE)) AS score
                FROM BSEARCHINDEX
                WHERE {$scope}
                  AND BKIND IN (:kinds)
                  AND MATCH(BTITLE, BBODY) AGAINST(:q IN BOOLEAN MODE)
                ORDER BY score DESC, BUPDATED DESC
                LIMIT :limit
            SQL,
            self::TITLE_WEIGHT,
        );

        return self::mapRows($this->connection->fetchAllAssociative(
            $sql,
            $params + ['kinds' => $kinds, 'limit' => $limit, 'q' => $expression],
            $types + ['kinds' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        ));
    }

    /**
     * `BUSERID = :userId`, widened by one `(BKIND = … AND BREFID IN (…))`
     * term per shared kind. Only fixed placeholder names reach the SQL.
     *
     * @param SharedRefs $sharedRefs
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType|ArrayParameterType>}
     */
    private static function scope(int $userId, array $sharedRefs): array
    {
        $terms = ['BUSERID = :userId'];
        $params = ['userId' => $userId];
        $types = ['userId' => ParameterType::INTEGER];
        $index = 0;
        foreach ($sharedRefs as $kind => $refIds) {
            if ([] === $refIds) {
                continue;
            }
            $terms[] = "(BKIND = :sharedKind{$index} AND BREFID IN (:sharedRefs{$index}))";
            $params["sharedKind{$index}"] = $kind;
            $params["sharedRefs{$index}"] = $refIds;
            $types["sharedRefs{$index}"] = ArrayParameterType::STRING;
            ++$index;
        }

        return ['('.implode(' OR ', $terms).')', $params, $types];
    }

    /**
     * Rows of one user that have no vector for `$modelId` yet (new, changed,
     * or embedded by a model that is no longer the search embedding model),
     * most recently updated first. `$after` continues behind the last row of
     * the previous page, so a row that keeps failing is never read twice.
     *
     * @param array{updated: int, id: int}|null $after
     *
     * @return list<array{id: int, title: string, body: string, hash: string, updated: int}>
     */
    public function findPendingEmbeddings(int $userId, int $modelId, int $limit, ?array $after = null): array
    {
        $cursor = null === $after ? '' : 'AND (BUPDATED < :afterUpdated OR (BUPDATED = :afterUpdated AND BID < :afterId))';
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT BID, BTITLE, BBODY, BHASH, BUPDATED
                FROM BSEARCHINDEX
                WHERE BUSERID = :userId
                  AND (BEMBED IS NULL OR BEMBEDMODELID IS NULL OR BEMBEDMODELID <> :modelId)
                  {$cursor}
                ORDER BY BUPDATED DESC, BID DESC
                LIMIT :limit
            SQL,
            ['userId' => $userId, 'modelId' => $modelId, 'limit' => $limit]
                + (null === $after ? [] : ['afterUpdated' => $after['updated'], 'afterId' => $after['id']]),
            ['userId' => ParameterType::INTEGER, 'modelId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER]
                + (null === $after ? [] : ['afterUpdated' => ParameterType::INTEGER, 'afterId' => ParameterType::INTEGER]),
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['BID'],
            'title' => (string) $row['BTITLE'],
            'body' => (string) $row['BBODY'],
            'hash' => (string) $row['BHASH'],
            'updated' => (int) $row['BUPDATED'],
        ], $rows);
    }

    /**
     * Rows behind `$after` (see findPendingEmbeddings()) that still need a vector from `$modelId`.
     *
     * @param array{updated: int, id: int}|null $after
     */
    public function countPendingEmbeddings(int $userId, int $modelId, ?array $after = null): int
    {
        $cursor = null === $after ? '' : 'AND (BUPDATED < :afterUpdated OR (BUPDATED = :afterUpdated AND BID < :afterId))';

        return (int) $this->connection->fetchOne(
            <<<SQL
                SELECT COUNT(*)
                FROM BSEARCHINDEX
                WHERE BUSERID = :userId
                  AND (BEMBED IS NULL OR BEMBEDMODELID IS NULL OR BEMBEDMODELID <> :modelId)
                  {$cursor}
            SQL,
            ['userId' => $userId, 'modelId' => $modelId]
                + (null === $after ? [] : ['afterUpdated' => $after['updated'], 'afterId' => $after['id']]),
            ['userId' => ParameterType::INTEGER, 'modelId' => ParameterType::INTEGER]
                + (null === $after ? [] : ['afterUpdated' => ParameterType::INTEGER, 'afterId' => ParameterType::INTEGER]),
        );
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
     * Meaning search over one user's rows (and the rows shared with them),
     * nearest first. `score` is the cosine similarity (1 = same direction).
     *
     * @param list<float>  $vector
     * @param list<string> $kinds
     * @param SharedRefs   $sharedRefs
     *
     * @return list<IndexRow>
     */
    public function searchSemantic(int $userId, array $vector, int $modelId, array $kinds, int $limit, float $minScore, array $sharedRefs = []): array
    {
        if ([] === $kinds || [] === $vector) {
            return [];
        }

        [$scope, $params, $types] = self::scope($userId, $sharedRefs);
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT BKIND, BREFID, BTITLE, BBODY, 1 - VEC_DISTANCE_COSINE(BEMBED, VEC_FromText(:vector)) AS score
                FROM BSEARCHINDEX
                WHERE {$scope}
                  AND BKIND IN (:kinds)
                  AND BEMBEDMODELID = :modelId
                  AND BEMBED IS NOT NULL
                HAVING score >= :minScore
                ORDER BY score DESC
                LIMIT :limit
            SQL,
            $params + [
                'vector' => self::vectorText($vector),
                'kinds' => $kinds,
                'modelId' => $modelId,
                'minScore' => $minScore,
                'limit' => $limit,
            ],
            $types + [
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
