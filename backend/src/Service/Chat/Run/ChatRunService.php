<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Feature service for resumable chat turns — the only entry point controllers
 * use, so no controller has to know about Redis (see AGENTS-DEV).
 *
 * Write side: {@see self::begin()} hands out a {@see ChatRunRecorder} that the
 * streaming controller feeds with every SSE event it emits.
 * Read side: {@see self::describeActiveForChat()} lets a history response point
 * a returning client at a still-running turn, and {@see self::authorize()}
 * gates the re-attach endpoint.
 */
final readonly class ChatRunService
{
    /**
     * A run whose heartbeat is older than this is treated as abandoned: the
     * generating worker died (deploy, crash, OOM) without ever reaching a
     * terminal state, so a client waiting on it must be released instead of
     * hanging until the TTL expires.
     */
    public const STALE_AFTER_SECONDS = 30;

    public function __construct(
        private ChatRunBuffer $buffer,
        private LoggerInterface $logger,
    ) {
    }

    public static function ownerKeyForUser(int $userId): string
    {
        return 'user:'.$userId;
    }

    public static function ownerKeyForGuest(string $sessionId): string
    {
        return 'guest:'.$sessionId;
    }

    public static function ownerKeyForWidget(string $sessionId): string
    {
        return 'widget:'.$sessionId;
    }

    /**
     * Open a run for one turn, or null when Redis did not accept the record —
     * in that case the turn simply streams without resume support, exactly as
     * it did before this feature existed.
     */
    public function begin(string $ownerKey, ?int $chatId, string $trackId): ?ChatRunRecorder
    {
        $run = new ChatRun(Uuid::v4()->toRfc4122(), $ownerKey, $chatId, $trackId);

        if (!$this->buffer->save($run)) {
            $this->logger->warning('ChatRunService: could not open a resumable run, streaming without resume', [
                'chat_id' => $chatId,
                'track_id' => $trackId,
            ]);

            return null;
        }

        return new ChatRunRecorder($run, $this->buffer, $this->logger);
    }

    /**
     * Resolve a run for a re-attach request, or null when it does not exist or
     * belongs to somebody else. Ownership is compared in constant time so the
     * endpoint cannot be used to probe for foreign run ids.
     */
    public function authorize(string $runId, string $ownerKey): ?ChatRun
    {
        $run = $this->buffer->find($runId);
        if (null === $run) {
            return null;
        }

        if (!hash_equals($run->getOwnerKey(), $ownerKey)) {
            $this->logger->warning('ChatRunService: rejected a re-attach for a foreign run', [
                'run_id' => $runId,
            ]);

            return null;
        }

        return $run;
    }

    public function find(string $runId): ?ChatRun
    {
        return $this->buffer->find($runId);
    }

    /**
     * @return list<array{seq: int, payload: string}>
     */
    public function readEvents(string $runId, int $fromSeq = 0): array
    {
        return $this->buffer->readEvents($runId, $fromSeq);
    }

    public function isStale(ChatRun $run): bool
    {
        return !$run->isTerminal() && (time() - $run->getUpdated()) > self::STALE_AFTER_SECONDS;
    }

    /**
     * Chats of one owner with a turn generating right now, so the chat list can
     * show where an answer is still being written after the user moved on.
     *
     * @return list<int>
     */
    public function activeChatIds(string $ownerKey): array
    {
        return $this->buffer->findActiveChatIdsForOwner($ownerKey);
    }

    /**
     * Describe a chat's still-running turn for the history response, so a
     * client that reloaded (and therefore lost the run id) can paint the text
     * produced so far and re-attach to the live stream.
     *
     * @return array{runId: string, trackId: string, lastSeq: int, partialText: string}|null
     */
    public function describeActiveForChat(int $chatId, string $ownerKey): ?array
    {
        $run = $this->buffer->findActiveForChat($chatId);
        if (null === $run || !hash_equals($run->getOwnerKey(), $ownerKey) || $this->isStale($run)) {
            return null;
        }

        $lastSeq = 0;
        $partialText = '';
        foreach ($this->buffer->readEvents($run->getRunId()) as $event) {
            $lastSeq = $event['seq'];

            $decoded = json_decode($event['payload'], true);
            if (is_array($decoded) && 'data' === ($decoded['status'] ?? null) && is_string($decoded['chunk'] ?? null)) {
                $partialText .= $decoded['chunk'];
            }
        }

        return [
            'runId' => $run->getRunId(),
            'trackId' => $run->getTrackId(),
            'lastSeq' => $lastSeq,
            'partialText' => $partialText,
        ];
    }
}
