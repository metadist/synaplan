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

        $params = ['userId' => $userId, 'kinds' => $kinds, 'limit' => $limit];
        $types = [
            'userId' => ParameterType::INTEGER,
            'kinds' => ArrayParameterType::STRING,
            'limit' => ParameterType::INTEGER,
        ];

        if ($query->hasTerms()) {
            $params['q'] = $query->booleanExpression();
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
        } else {
            $params['like'] = $query->likePattern();
            $sql = <<<'SQL'
                SELECT BKIND, BREFID, BTITLE, BBODY, 1 AS score
                FROM BSEARCHINDEX
                WHERE BUSERID = :userId
                  AND BKIND IN (:kinds)
                  AND BTITLE LIKE :like
                ORDER BY BUPDATED DESC
                LIMIT :limit
            SQL;
        }

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $row): array => [
            'kind' => (string) $row['BKIND'],
            'refId' => (string) $row['BREFID'],
            'title' => (string) $row['BTITLE'],
            'body' => (string) $row['BBODY'],
            'score' => (float) $row['score'],
        ], $rows);
    }
}
