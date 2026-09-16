<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use Psr\Log\LoggerInterface;

/**
 * Per-turn write path into a {@see ChatRun}'s event log.
 *
 * One instance exists for the lifetime of one streaming request; it owns the
 * mutable coalescing state that must not be shared between turns, which is why
 * this is created by {@see ChatRunService} instead of being a container service.
 *
 * Coalescing
 * ----------
 * A turn emits one `data` event per model token — writing each of them as its
 * own Redis entry would mean thousands of round-trips per answer for a log that
 * is read at most a handful of times. Consecutive text chunks are therefore
 * merged into one entry per {@see self::FLUSH_INTERVAL_SECONDS} (or
 * {@see self::FLUSH_BYTES}, whichever comes first). Every other event kind
 * (`memories_loaded`, `file`, `task_*`, `complete`, …) is low-volume and is
 * appended verbatim, after flushing the pending text so ordering is preserved.
 */
final class ChatRunRecorder
{
    private const FLUSH_INTERVAL_SECONDS = 0.25;
    private const FLUSH_BYTES = 2048;

    /**
     * Hard ceiling for one run's event log. Beyond this the run is marked
     * truncated and stops recording: an unbounded buffer would let a single
     * pathological answer push everything else out of Redis, and a partial log
     * that silently drops the middle would be worse than falling back to the
     * existing history-poll recovery.
     */
    private const MAX_BUFFERED_BYTES = 1048576; // 1 MiB

    private string $pendingText = '';
    private float $lastFlushAt;
    private int $bufferedBytes = 0;
    private int $seq = 0;
    private bool $finished = false;

    /**
     * Terminal event seen on the wire, if any. Lets {@see self::finishFromRecordedOutcome()}
     * close a run correctly for paths that end without an explicit finish()
     * (e.g. the non-streaming model branch, which returns early).
     */
    private ?string $recordedOutcome = null;

    public function __construct(
        private readonly ChatRun $run,
        private readonly ChatRunBuffer $buffer,
        private readonly LoggerInterface $logger,
    ) {
        $this->lastFlushAt = microtime(true);
    }

    public function getRunId(): string
    {
        return $this->run->getRunId();
    }

    /**
     * Record one SSE event exactly as it goes out on the wire.
     *
     * @param array<string, mixed> $event the full payload including `status`
     */
    public function record(array $event): void
    {
        if ($this->finished || $this->run->isTruncated()) {
            return;
        }

        $status = $event['status'] ?? null;
        $chunk = $event['chunk'] ?? null;

        if (ChatRun::STATUS_COMPLETE === $status || ChatRun::STATUS_ERROR === $status) {
            $this->recordedOutcome = $status;
        }

        // Text chunks are the only high-volume event, and they only ever carry
        // `status` + `chunk` — anything richer takes the verbatim path so we
        // never drop a field by merging.
        if ('data' === $status && is_string($chunk) && ['status', 'chunk'] === array_keys($event)) {
            $this->pendingText .= $chunk;

            if (strlen($this->pendingText) >= self::FLUSH_BYTES
                || (microtime(true) - $this->lastFlushAt) >= self::FLUSH_INTERVAL_SECONDS) {
                $this->flushPendingText();
            }

            return;
        }

        $this->flushPendingText();
        $this->appendEvent($event);
        $this->persistRun();
    }

    /**
     * Close the run. Idempotent — the streaming callback marks the outcome it
     * knows about and the surrounding `finally` acts as a safety net so a run
     * can never stay `running` forever.
     */
    public function finish(string $status, ?int $messageId = null): void
    {
        if ($this->finished) {
            return;
        }

        $this->flushPendingText();
        $this->finished = true;

        $this->run
            ->markTerminal($status)
            ->setMessageId($messageId)
            ->setLastSeq($this->seq)
            ->touch();

        $this->buffer->save($this->run);
    }

    /**
     * Close a run that nothing else closed, using the last terminal event that
     * actually went out on the wire. Some paths end the turn by returning early
     * (the non-streaming model branch) rather than by calling finish(), and a
     * run left `running` would make a re-attaching client wait for a heartbeat
     * that never ticks again.
     */
    public function finishFromRecordedOutcome(): void
    {
        $this->finish($this->recordedOutcome ?? ChatRun::STATUS_ERROR);
    }

    private function flushPendingText(): void
    {
        if ('' === $this->pendingText) {
            return;
        }

        $text = $this->pendingText;
        $this->pendingText = '';
        $this->lastFlushAt = microtime(true);

        $this->appendEvent(['status' => 'data', 'chunk' => $text]);
        $this->persistRun();
    }

    /**
     * @param array<string, mixed> $event
     */
    private function appendEvent(array $event): void
    {
        $payload = json_encode($event, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $payload) {
            $this->logger->warning('ChatRunRecorder: dropped an unencodable event', [
                'run_id' => $this->run->getRunId(),
                'status' => $event['status'] ?? null,
            ]);

            return;
        }

        $this->bufferedBytes += strlen($payload);
        if ($this->bufferedBytes > self::MAX_BUFFERED_BYTES) {
            $this->run->markTruncated()->touch();
            $this->buffer->save($this->run);
            $this->logger->warning('ChatRunRecorder: event log exceeded the size cap, stopped recording', [
                'run_id' => $this->run->getRunId(),
                'buffered_bytes' => $this->bufferedBytes,
            ]);

            return;
        }

        $this->buffer->append($this->run->getRunId(), ++$this->seq, $payload);
    }

    /** Persists sequence progress and refreshes the heartbeat the reader watches. */
    private function persistRun(): void
    {
        $this->buffer->save($this->run->setLastSeq($this->seq)->touch());
    }
}
