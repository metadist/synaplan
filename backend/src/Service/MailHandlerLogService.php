<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\UseLog;
use App\Repository\MailHandlerLogRepository;
use Psr\Log\LoggerInterface;

/**
 * Per-handler activity log for inbound mail handlers.
 *
 * Stores a small ring-buffer of recent events (connection attempts,
 * per-message decisions, forward results) in BUSELOG so users can
 * diagnose "the email was marked seen but not forwarded" cases from
 * the mail-handler UI.
 *
 * - Tagged with `BACTION = MailHandlerLogRepository::ACTION` so
 *   usage-aggregations and rate limits (which key off other action
 *   names like MESSAGES, IMAGES, EMAIL_ROUTING, …) are not affected.
 * - `handler_id` is stored in the indexed `BPROVIDER` column (which is
 *   otherwise unused for these rows), keeping `findRecent` / `prune` /
 *   `deleteAll` index-friendly without adding a new column or a
 *   functional index on a JSON expression.
 * - Bound to (BUSERID, BPROVIDER). Old rows beyond the most recent
 *   {@see self::DEFAULT_KEEP} per (user, handler) are pruned at the end
 *   of every handler run, so the table cannot grow unbounded for
 *   long-lived handlers.
 */
final readonly class MailHandlerLogService
{
    /** Public alias so callers don't have to know about the repository. */
    public const ACTION = MailHandlerLogRepository::ACTION;

    public const DEFAULT_KEEP = 10;

    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    public const EVENT_CHECK = 'check';
    public const EVENT_CONNECT_FAILED = 'connect_failed';
    public const EVENT_FORWARDED = 'forwarded';
    public const EVENT_DISCARDED = 'discarded';
    public const EVENT_NO_SMTP = 'no_smtp';
    public const EVENT_FORWARD_FAILED = 'forward_failed';
    public const EVENT_PROCESS_ERROR = 'process_error';

    /**
     * Maximum length of free-text fields stored in BMETADATA / BERROR.
     * Keeps the JSON column compact so 10 entries per handler stay well
     * under typical row size limits even for very long subjects or AI
     * replies.
     */
    private const FIELD_TRUNCATE = 256;

    public function __construct(
        private MailHandlerLogRepository $repository,
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
     *                                      truncated. `handler_id` and `event`
     *                                      reserved keys are stripped and re-added
     *                                      from the explicit parameters.
     */
    public function log(
        int $userId,
        int $handlerId,
        string $event,
        string $status = self::STATUS_SUCCESS,
        ?string $error = null,
        array $details = [],
    ): void {
        $entry = new UseLog();
        $entry->setUserId($userId);
        $entry->setUnixTimestamp(time());
        $entry->setStatus($this->normalizeStatus($status));
        $entry->setError(null !== $error ? $this->truncate($error) : '');
        $entry->setMetadata($this->buildMetadata($event, $details));

        try {
            $this->repository->save($userId, $handlerId, $entry);
        } catch (\Throwable $e) {
            // Any failure (DBAL, ORM, mapping, …) must be swallowed —
            // a broken activity log must never cascade into a broken
            // mail-handler run.
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
            $rows = $this->repository->findRecent($userId, $handlerId, $limit);
        } catch (\Throwable $e) {
            $this->logger->warning('MailHandlerLogService: failed to read activity entries', [
                'user_id' => $userId,
                'handler_id' => $handlerId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $metadata = $this->decodeMetadata($row['metadata']);
            $event = isset($metadata['event']) && is_string($metadata['event']) ? $metadata['event'] : 'unknown';
            unset($metadata['event']);

            $result[] = [
                'id' => $row['id'],
                'timestamp' => $row['unix_time'],
                'event' => $event,
                'status' => '' !== $row['status'] ? $row['status'] : self::STATUS_SUCCESS,
                'error' => $row['error'],
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
            return $this->repository->prune($userId, $handlerId, $keep);
        } catch (\Throwable $e) {
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
            return $this->repository->deleteAll($userId, $handlerId);
        } catch (\Throwable $e) {
            $this->logger->warning('MailHandlerLogService: failed to delete activity entries', [
                'user_id' => $userId,
                'handler_id' => $handlerId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Build the metadata payload stored in `BMETADATA`. Reserved keys
     * `handler_id` and `event` from caller-supplied details are dropped:
     * `handler_id` already lives in the indexed `BPROVIDER` column, and
     * `event` comes from the explicit method parameter.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(string $event, array $details): array
    {
        $payload = ['event' => $event];

        foreach ($details as $key => $value) {
            if ('event' === $key || 'handler_id' === $key) {
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

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(string $raw): array
    {
        if ('' === $raw) {
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
