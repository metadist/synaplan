<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UseLog;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Storage adapter for per-handler mail activity log entries.
 *
 * Mail-handler activity rows are stored inside the shared {@see UseLog}
 * (`BUSELOG`) table — there is no dedicated table on purpose: the data
 * is short-lived (ring-buffer of {@see self::DEFAULT_KEEP_FALLBACK} per
 * (user, handler)) and benefits from the existing indexes on `BUSERID`,
 * `BACTION` and `BPROVIDER`.
 *
 * Discriminators inside `BUSELOG`:
 *   - `BACTION  = 'MAIL_HANDLER_LOG'` (never used by other writers, so it
 *     never collides with rate-limit / cost-aggregation queries).
 *   - `BPROVIDER = handler_id` (string-coerced). `BPROVIDER` is already
 *     indexed (`idx_uselog_provider`) and is otherwise unused for our
 *     rows, so we can filter / prune in O(log n) without adding either a
 *     new column or a JSON-functional index.
 *
 * Everything else (event name, free-form details) lives in the JSON
 * `BMETADATA` column, which is never used inside WHERE/ORDER clauses.
 */
final readonly class MailHandlerLogRepository
{
    /** Mirrors the service-level default so the repository stays usable standalone. */
    public const DEFAULT_KEEP_FALLBACK = 10;

    public const ACTION = 'MAIL_HANDLER_LOG';

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Persist a {@see UseLog} row representing one mail-handler activity event.
     *
     * The caller is responsible for filling in the user-facing payload
     * (status, error, metadata); this method only ensures the row is
     * tagged with the correct action + provider discriminator so the
     * other methods on this repository can find it again.
     *
     * @throws DbalException on persistence failure
     */
    public function save(int $userId, int $handlerId, UseLog $entry): void
    {
        $entry->setUserId($userId);
        $entry->setAction(self::ACTION);
        $entry->setProvider((string) $handlerId);

        $this->em->persist($entry);
        $this->em->flush();
    }

    /**
     * Fetch the most recent activity rows for (user, handler), newest first.
     *
     * Returns raw associative rows — the calling service is the one that
     * knows how to decode `BMETADATA` and shape the public API contract.
     *
     * @return list<array{id: int, unix_time: int, status: string, error: string, metadata: string}>
     *
     * @throws DbalException
     */
    public function findRecent(int $userId, int $handlerId, int $limit): array
    {
        // Native SQL keeps this query trivially observable in slow-query
        // logs ("WHERE BACTION=… AND BPROVIDER=…") — DQL would generate
        // the same plan but obscure it behind aliases. DBAL columns
        // come back as `mixed`; the normalization loop below casts to
        // the schema-guaranteed shape exposed by this method.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT
                BID         AS id,
                BUNIXTIMES  AS unix_time,
                BSTATUS     AS status,
                BERROR      AS error,
                BMETADATA   AS metadata
            FROM BUSELOG
            WHERE BUSERID = :user_id
              AND BACTION = :action
              AND BPROVIDER = :handler_id
            ORDER BY BUNIXTIMES DESC, BID DESC
            LIMIT :limit
            SQL,
            [
                'user_id' => $userId,
                'action' => self::ACTION,
                'handler_id' => (string) $handlerId,
                'limit' => $limit,
            ],
            [
                // Bind LIMIT as integer; otherwise DBAL emits a quoted
                // string and MariaDB raises a syntax error.
                'limit' => ParameterType::INTEGER,
            ]
        );

        $normalized = [];
        foreach ($rows as $row) {
            // DBAL returns mixed values from fetchAllAssociative; the
            // explicit casts double as a runtime safety net for NULLs
            // (e.g. truncated rows from partial replication).
            $normalized[] = [
                'id' => (int) $row['id'],
                'unix_time' => (int) $row['unix_time'],
                'status' => (string) ($row['status'] ?? ''),
                'error' => (string) ($row['error'] ?? ''),
                'metadata' => (string) ($row['metadata'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * Keep only the newest $keep entries for (user, handler), delete the rest.
     *
     * @return int Number of rows actually deleted
     *
     * @throws DbalException
     */
    public function prune(int $userId, int $handlerId, int $keep): int
    {
        // MariaDB does not allow a NOT IN sub-SELECT against the same
        // table without an intermediate derived table — wrap the keep-set
        // so the DELETE remains portable across MariaDB / MySQL.
        return (int) $this->em->getConnection()->executeStatement(
            <<<'SQL'
            DELETE FROM BUSELOG
             WHERE BUSERID = :user_id
               AND BACTION = :action
               AND BPROVIDER = :handler_id
               AND BID NOT IN (
                   SELECT keep_id FROM (
                       SELECT BID AS keep_id
                         FROM BUSELOG
                        WHERE BUSERID = :user_id
                          AND BACTION = :action
                          AND BPROVIDER = :handler_id
                        ORDER BY BUNIXTIMES DESC, BID DESC
                        LIMIT :keep
                   ) AS keep_set
               )
            SQL,
            [
                'user_id' => $userId,
                'action' => self::ACTION,
                'handler_id' => (string) $handlerId,
                'keep' => $keep,
            ],
            [
                'keep' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * Drop every activity row for (user, handler). Used when the handler
     * itself is deleted so we do not leave orphaned log entries behind.
     *
     * @return int Number of rows actually deleted
     *
     * @throws DbalException
     */
    public function deleteAll(int $userId, int $handlerId): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            <<<'SQL'
            DELETE FROM BUSELOG
             WHERE BUSERID = :user_id
               AND BACTION = :action
               AND BPROVIDER = :handler_id
            SQL,
            [
                'user_id' => $userId,
                'action' => self::ACTION,
                'handler_id' => (string) $handlerId,
            ]
        );
    }
}
