<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * Durable-in-Redis record of one resumable chat turn ("run").
 *
 * Why a run record exists
 * -----------------------
 * A turn survives a client disconnect (`ignore_user_abort(true)`, issues
 * #1142/#1223/#1225), but until now the produced tokens existed ONLY inside the
 * HTTP response of the request that started it. Reloading the page or switching
 * chats therefore left the user staring at their own prompt until the finished
 * answer hit BMESSAGES. A run gives the turn an identity plus a replayable
 * event log, so a second connection can re-attach and keep rendering where the
 * first one stopped.
 *
 * Why Redis (not a DB table)
 * --------------------------
 * Same reasoning as {@see \App\Service\Media\MediaJob}: the events are
 * high-write and inherently ephemeral (the persisted BMESSAGES row is the only
 * durable artefact), and production is a multi-node Galera cluster where the
 * re-attach request can land on a different web node than the one generating.
 * Redis is the canonical cross-node store here.
 *
 * This is a plain mutable value object; persistence lives in
 * {@see ChatRunBuffer} and the per-turn write path in {@see ChatRunRecorder}.
 */
final class ChatRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    private string $status = self::STATUS_RUNNING;
    private int $lastSeq = 0;
    private ?int $messageId = null;

    /**
     * Set once the event log hit {@see ChatRunRecorder::MAX_BUFFERED_BYTES}.
     * A truncated run can no longer be replayed faithfully, so the attach
     * endpoint ends it with a recoverable error and the client falls back to
     * the existing history-poll recovery.
     */
    private bool $truncated = false;

    private int $created;
    private int $updated;

    public function __construct(
        private readonly string $runId,
        private readonly string $ownerKey,
        private readonly ?int $chatId,
        private readonly string $trackId,
    ) {
        $now = time();
        $this->created = $now;
        $this->updated = $now;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * Opaque owner scope (`user:12`, `guest:{sessionId}`, `widget:{sessionId}`).
     * The attach endpoint rebuilds this from the incoming request and compares
     * it against the stored value — a foreign runId must never replay someone
     * else's conversation.
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    public function getTrackId(): string
    {
        return $this->trackId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isTerminal(): bool
    {
        return self::STATUS_RUNNING !== $this->status;
    }

    public function getLastSeq(): int
    {
        return $this->lastSeq;
    }

    public function setLastSeq(int $lastSeq): self
    {
        $this->lastSeq = $lastSeq;

        return $this;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    public function setMessageId(?int $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function markTruncated(): self
    {
        $this->truncated = true;

        return $this;
    }

    public function markTerminal(string $status): self
    {
        $this->status = \in_array($status, [self::STATUS_COMPLETE, self::STATUS_ERROR, self::STATUS_CANCELLED], true)
            ? $status
            : self::STATUS_ERROR;

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    /** Heartbeat: the attach endpoint treats a stale run as a dead worker. */
    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function touch(): self
    {
        $this->updated = time();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'runId' => $this->runId,
            'ownerKey' => $this->ownerKey,
            'chatId' => $this->chatId,
            'trackId' => $this->trackId,
            'status' => $this->status,
            'lastSeq' => $this->lastSeq,
            'messageId' => $this->messageId,
            'truncated' => $this->truncated,
            'created' => $this->created,
            'updated' => $this->updated,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $chatId = $data['chatId'] ?? null;

        $run = new self(
            (string) ($data['runId'] ?? ''),
            (string) ($data['ownerKey'] ?? ''),
            is_numeric($chatId) ? (int) $chatId : null,
            (string) ($data['trackId'] ?? ''),
        );

        $run->status = (string) ($data['status'] ?? self::STATUS_RUNNING);
        $run->lastSeq = (int) ($data['lastSeq'] ?? 0);
        $messageId = $data['messageId'] ?? null;
        $run->messageId = is_numeric($messageId) ? (int) $messageId : null;
        $run->truncated = (bool) ($data['truncated'] ?? false);
        $run->created = (int) ($data['created'] ?? time());
        $run->updated = (int) ($data['updated'] ?? time());

        return $run;
    }
}
