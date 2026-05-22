<?php

namespace App\Service;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-handler activity log for inbound mail handlers.
 *
 * Stores a small ring-buffer of recent events (connection attempts,
 * per-message decisions, forward results) in BUSELOG so users can
 * diagnose "the email was marked seen but not forwarded" cases from
 * the mail-handler UI.
 *
 * - Tagged with BACTION = self::ACTION so usage-aggregations and rate
 *   limits (which key off other action names like MESSAGES, IMAGES,
 *   EMAIL_ROUTING, …) are not affected.
 * - Bound to (BUSERID, handler_id-in-metadata). Old rows beyond the
 *   most recent {@see self::DEFAULT_KEEP} per (user, handler) are
 *   pruned at the end of every handler run, so the table cannot grow
 *   unbounded for long-lived handlers.
 */
final readonly class MailHandlerLogService
{
    public const ACTION = 'MAIL_HANDLER_LOG';

    public const DEFAULT_KEEP = 10;

    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    public const EVENT_CHECK = 'check';
    public const EVENT_CONNECT_FAILED = 'connect_failed';
    public const EVENT_FORWARDED = 'forwarded';
    public const EVENT_DISCARDED = 'discarded';
    public const EVENT_NO_ROUTE = 'no_route';
    public const EVENT_NO_SMTP = 'no_smtp';
    public const EVENT_FORWARD_FAILED = 'forward_failed';
    public const EVENT_PROCESS_ERROR = 'process_error';

    /**
     * Maximum length of free-text fields stored in BMETADATA. Keeps the JSON
     * column compact so 10 entries per handler stay well under typical row
     * size limits even for very long subjects or AI replies.
     */
    private const FIELD_TRUNCATE = 256;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Append a single activity entry for (user, handler).
     *
     * Failures inside the log path are intentionally swallowed and only
     * logged via the framework logger — a broken activity log must never
     * cascade into a broken mail-handler run.
     *
     * @param array<string, mixed> $details Free-form metadata; large strings are
     *                                      truncated. `handler_id` is always set
     *                                      from the $handlerId parameter.
     */
    public function log(
        int $userId,
        int $handlerId,
        string $event,
        string $status = self::STATUS_SUCCESS,
        ?string $error = null,
        array $details = [],
    ): void {
        $payload = $this->buildMetadata($handlerId, $event, $details);

        try {
            $this->em->getConnection()->insert('BUSELOG', [
                'BUSERID' => $userId,
                'BUNIXTIMES' => time(),
                'BACTION' => self::ACTION,
                'BPROVIDER' => '',
                'BMODEL' => '',
                'BTOKENS' => 0,
                'BPROMPT_TOKENS' => 0,
                'BCOMPLETION_TOKENS' => 0,
                'BCACHED_TOKENS' => 0,
                'BCACHE_CREATION_TOKENS' => 0,
                'BESTIMATED' => 0,
                'BCOST' => '0.000000',
                'BLATENCY' => 0,
                'BSTATUS' => $this->normalizeStatus($status),
                'BERROR' => null !== $error ? $this->truncate($error) : '',
                'BMETADATA' => $payload,
            ]);
        } catch (DbalException $e) {
            $this->logger->warning('MailHandlerLogService: failed to persist activity entry', [
                'user_id' => $userId,
                'handler_id' => $handlerId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return the last $limit activity rows for (user, handler), newest first.
     *
     * @return list<array{
     *     id: int,
     *     timestamp: int,
     *     event: string,
     *     status: string,
     *     error: string,
     *     details: array<string, mixed>
     * }>
     */
    public function findRecent(int $userId, int $handlerId, int $limit = self::DEFAULT_KEEP): array
    {
        $limit = max(1, min($limit, 100));

        try {
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
                  AND JSON_EXTRACT(BMETADATA, '$.handler_id') = :handler_id
                ORDER BY BUNIXTIMES DESC, BID DESC
                LIMIT :limit
                SQL,
                [
                    'user_id' => $userId,
                    'action' => self::ACTION,
                    'handler_id' => $handlerId,
                    'limit' => $limit,
                ],
                [
                    // Bind LIMIT as integer or DBAL emits it as a quoted string
                    // and MariaDB raises a syntax error.
                    'limit' => ParameterType::INTEGER,
                ]
            );
        } catch (DbalException $e) {
            $this->logger->warning('MailHandlerLogService: failed to read activity entries', [
                'user_id' => $userId,
                'handler_id' => $handlerId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $metadata = $this->decodeMetadata($row['metadata'] ?? null);
            $event = isset($metadata['event']) && is_string($metadata['event']) ? $metadata['event'] : 'unknown';
            unset($metadata['event'], $metadata['handler_id']);

            $result[] = [
                'id' => (int) $row['id'],
                'timestamp' => (int) $row['unix_time'],
                'event' => $event,
                'status' => is_string($row['status']) ? $row['status'] : self::STATUS_SUCCESS,
                'error' => is_string($row['error']) ? $row['error'] : '',
                'details' => $metadata,
            ];
        }

        return $result;
    }

    /**
     * Keep only the newest $keep entries for (user, handler); delete the rest.
     *
     * Returns the number of rows deleted.
     */
    public function prune(int $userId, int $handlerId, int $keep = self::DEFAULT_KEEP): int
    {
        $keep = max(1, $keep);

        try {
            // MariaDB does not allow referencing the same table directly inside
            // a NOT IN subquery without an intermediate derived table — wrap
            // the keep-set in a sub-SELECT so the DELETE is portable.
            return (int) $this->em->getConnection()->executeStatement(
                <<<'SQL'
                DELETE FROM BUSELOG
                 WHERE BUSERID = :user_id
                   AND BACTION = :action
                   AND JSON_EXTRACT(BMETADATA, '$.handler_id') = :handler_id
                   AND BID NOT IN (
                       SELECT keep_id FROM (
                           SELECT BID AS keep_id
                             FROM BUSELOG
                            WHERE BUSERID = :user_id
                              AND BACTION = :action
                              AND JSON_EXTRACT(BMETADATA, '$.handler_id') = :handler_id
                            ORDER BY BUNIXTIMES DESC, BID DESC
                            LIMIT :keep
                       ) AS keep_set
                   )
                SQL,
                [
                    'user_id' => $userId,
                    'action' => self::ACTION,
                    'handler_id' => $handlerId,
                    'keep' => $keep,
                ],
                [
                    'keep' => ParameterType::INTEGER,
                ]
            );
        } catch (DbalException $e) {
            $this->logger->warning('MailHandlerLogService: failed to prune activity entries', [
                'user_id' => $userId,
                'handler_id' => $handlerId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Drop every activity row for (user, handler). Used when the handler
     * itself is deleted so we do not leave orphaned log entries behind.
     */
    public function deleteAll(int $userId, int $handlerId): int
    {
        try {
            return (int) $this->em->getConnection()->executeStatement(
                <<<'SQL'
                DELETE FROM BUSELOG
                 WHERE BUSERID = :user_id
                   AND BACTION = :action
                   AND JSON_EXTRACT(BMETADATA, '$.handler_id') = :handler_id
                SQL,
                [
                    'user_id' => $userId,
                    'action' => self::ACTION,
                    'handler_id' => $handlerId,
                ]
            );
        } catch (DbalException $e) {
            $this->logger->warning('MailHandlerLogService: failed to delete activity entries', [
                'user_id' => $userId,
                'handler_id' => $handlerId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function buildMetadata(int $handlerId, string $event, array $details): string
    {
        $payload = [
            'handler_id' => $handlerId,
            'event' => $event,
        ];

        foreach ($details as $key => $value) {
            if ('handler_id' === $key || 'event' === $key) {
                continue;
            }
            if (is_string($value)) {
                $payload[$key] = $this->truncate($value);
            } elseif (is_scalar($value) || is_array($value) || null === $value) {
                $payload[$key] = $value;
            } else {
                $payload[$key] = (string) (is_object($value) && method_exists($value, '__toString') ? $value : '');
            }
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function decodeMetadata(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::FIELD_TRUNCATE) {
            return $value;
        }

        return mb_substr($value, 0, self::FIELD_TRUNCATE - 1).'…';
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS, self::STATUS_WARNING, self::STATUS_ERROR => $status,
            default => self::STATUS_SUCCESS,
        };
    }
}
