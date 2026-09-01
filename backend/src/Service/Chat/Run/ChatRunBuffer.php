<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\Service\Infrastructure\RedisService;

/**
 * Redis persistence for {@see ChatRun} and its replayable SSE event log.
 *
 * Layout
 * ------
 *   chatrun:{runId}         -> JSON snapshot of the run (TTL'd).
 *   chatrun:{runId}:events  -> ZSET of SSE payloads scored by sequence number.
 *                              Members are "{seq}\n{json}" — the seq prefix
 *                              keeps members unique, which matters because a
 *                              sorted set would otherwise collapse two
 *                              identical text chunks into one entry.
 *   chatrun:chat:{chatId}   -> runId of the chat's newest run, so the history
 *                              endpoint can surface a still-running turn after
 *                              a hard reload where the client lost the runId.
 *   chatrun:owner:{key}     -> SET of that owner's chat ids with a running turn.
 *                              Lets the chat list mark them with one round-trip
 *                              instead of probing every listed chat.
 *
 * Every operation degrades to a no-op / empty result when Redis is unavailable
 * ({@see RedisService} returns false/null instead of throwing), which reduces
 * the feature to the pre-existing behaviour rather than breaking the turn.
 */
final readonly class ChatRunBuffer
{
    private const RUN_PREFIX = 'chatrun:';
    private const EVENTS_SUFFIX = ':events';
    private const CHAT_POINTER_PREFIX = 'chatrun:chat:';
    private const OWNER_ACTIVE_PREFIX = 'chatrun:owner:';

    /** Retention while the turn is generating — generous enough for a long answer. */
    private const ACTIVE_TTL_SECONDS = 1800; // 30 min

    /**
     * Retention after the turn reached a terminal state. Short on purpose: the
     * answer is in BMESSAGES by then, this only covers a client that re-attaches
     * in the seconds around completion.
     */
    private const TERMINAL_TTL_SECONDS = 300; // 5 min

    public function __construct(
        private RedisService $redis,
    ) {
    }

    /**
     * @return bool whether the snapshot reached Redis; the caller uses the
     *              first save as its availability probe, so a false here means
     *              "run without resume support" rather than "turn failed"
     */
    public function save(ChatRun $run): bool
    {
        $ttl = $run->isTerminal() ? self::TERMINAL_TTL_SECONDS : self::ACTIVE_TTL_SECONDS;

        $encoded = json_encode($run->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            // Nothing here is user-controlled, so this is a programming error
            // rather than bad input — fail loudly instead of storing a broken
            // snapshot that would strand every re-attach on this run.
            throw new \RuntimeException(sprintf('ChatRunBuffer: failed to serialize run %s: %s', $run->getRunId(), json_last_error_msg()));
        }

        $stored = $this->redis->set(self::RUN_PREFIX.$run->getRunId(), $encoded, $ttl);
        $this->redis->expire($this->eventsKey($run->getRunId()), $ttl);

        $chatId = $run->getChatId();
        if (null === $chatId || $chatId <= 0) {
            return $stored;
        }

        $ownerKey = self::OWNER_ACTIVE_PREFIX.$run->getOwnerKey();

        if ($run->isTerminal()) {
            // Only drop the pointer when it still points at THIS run — a newer
            // turn in the same chat may already own it.
            if ($run->getRunId() === $this->redis->get(self::CHAT_POINTER_PREFIX.$chatId)) {
                $this->redis->delete(self::CHAT_POINTER_PREFIX.$chatId);
                $this->redis->sRem($ownerKey, (string) $chatId);
            }

            return $stored;
        }

        $this->redis->set(self::CHAT_POINTER_PREFIX.$chatId, $run->getRunId(), self::ACTIVE_TTL_SECONDS);
        $this->redis->sAdd($ownerKey, (string) $chatId);
        $this->redis->expire($ownerKey, self::ACTIVE_TTL_SECONDS);

        return $stored;
    }

    public function find(string $runId): ?ChatRun
    {
        if ('' === $runId) {
            return null;
        }

        $raw = $this->redis->get(self::RUN_PREFIX.$runId);
        if (null === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        /* @var array<string, mixed> $decoded */
        return ChatRun::fromArray($decoded);
    }

    /**
     * The chat's currently generating run, or null when nothing is running.
     * Self-heals a pointer whose run snapshot expired or already went terminal.
     */
    public function findActiveForChat(int $chatId): ?ChatRun
    {
        if ($chatId <= 0) {
            return null;
        }

        $runId = $this->redis->get(self::CHAT_POINTER_PREFIX.$chatId);
        if (null === $runId || '' === $runId) {
            return null;
        }

        $run = $this->find($runId);
        if (null === $run || $run->isTerminal()) {
            $this->redis->delete(self::CHAT_POINTER_PREFIX.$chatId);

            return null;
        }

        return $run;
    }

    /**
     * Chat ids of one owner that currently have a generating turn. Self-heals
     * index entries whose run finished or expired.
     *
     * @return list<int>
     */
    public function findActiveChatIdsForOwner(string $ownerKey): array
    {
        $indexKey = self::OWNER_ACTIVE_PREFIX.$ownerKey;
        $chatIds = [];

        foreach ($this->redis->sMembers($indexKey) as $member) {
            $chatId = (int) $member;
            if ($chatId <= 0 || null === $this->findActiveForChat($chatId)) {
                $this->redis->sRem($indexKey, $member);
                continue;
            }

            $chatIds[] = $chatId;
        }

        return $chatIds;
    }

    /** Append one already-encoded SSE payload under its sequence number. */
    public function append(string $runId, int $seq, string $payloadJson): void
    {
        $this->redis->zAdd($this->eventsKey($runId), (float) $seq, $seq."\n".$payloadJson);
    }

    /**
     * Replay the event log, exclusive of `$fromSeq` (pass 0 for everything).
     *
     * @return list<array{seq: int, payload: string}>
     */
    public function readEvents(string $runId, int $fromSeq = 0, ?int $limit = null): array
    {
        $members = $this->redis->zRangeByScore(
            $this->eventsKey($runId),
            '('.max(0, $fromSeq),
            '+inf',
            $limit,
        );

        $events = [];
        foreach ($members as $member) {
            $separator = strpos($member, "\n");
            if (false === $separator) {
                continue;
            }

            $events[] = [
                'seq' => (int) substr($member, 0, $separator),
                'payload' => substr($member, $separator + 1),
            ];
        }

        return $events;
    }

    /** Drop a run and its event log (used when a turn must leave no trace). */
    public function forget(ChatRun $run): void
    {
        $this->redis->delete(self::RUN_PREFIX.$run->getRunId());
        $this->redis->delete($this->eventsKey($run->getRunId()));

        $chatId = $run->getChatId();
        if (null !== $chatId && $run->getRunId() === $this->redis->get(self::CHAT_POINTER_PREFIX.$chatId)) {
            $this->redis->delete(self::CHAT_POINTER_PREFIX.$chatId);
            $this->redis->sRem(self::OWNER_ACTIVE_PREFIX.$run->getOwnerKey(), (string) $chatId);
        }
    }

    private function eventsKey(string $runId): string
    {
        return self::RUN_PREFIX.$runId.self::EVENTS_SUFFIX;
    }
}
