<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * The Redis operations the run kernel needs. A missing key and a dead store
 * are not the same answer where a mix-up would hide a failure: reads and
 * writes that must not fail silently throw RunStoreUnavailable.
 */
interface RunKeys
{
    public function get(string $key): ?string;

    /**
     * @param int|null $ttlSeconds null keeps the key until it is deleted
     */
    public function set(string $key, string $value, ?int $ttlSeconds): bool;

    /**
     * Store the value only when the key is free. False when it already holds
     * a value, so two identical starts share one run.
     *
     * @throws RunStoreUnavailable when Redis did not answer, so a dead store never reads as a held key
     */
    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool;

    /**
     * Take the lease and index it in one write. False when it is already held.
     */
    public function claim(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool;

    /**
     * Slide the lease forward, only while $token still holds it.
     */
    public function renew(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool;

    /**
     * Write the state and release the claim, only while the claim still holds
     * the token and the run still exists. A null TTL keeps the state until
     * it is deleted.
     */
    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, ?int $ttlSeconds): bool;

    public function deleteIfEquals(string $key, string $expected): bool;

    /**
     * @throws RunStoreUnavailable when Redis did not answer, so a deletion is never skipped silently
     */
    public function delete(string $key): void;

    public function expire(string $key, int $ttlSeconds): void;

    /**
     * @return list<string>
     */
    public function range(string $key, int $start, int $stop): array;

    public function addMember(string $key, string $member): void;

    public function removeMember(string $key, string $member): void;

    /**
     * @return list<string>
     */
    public function members(string $key): array;

    public function zRem(string $key, string $member): void;

    /**
     * @return list<string>
     */
    public function zRangeByScore(string $key, int $maxScore): array;

    /**
     * Append one event body and return its sequence number. The stored line
     * is `{seq}\n{body}`. Sequence and list take the run key's expiry, or
     * none while the run key has none. Null when the run key is gone, so a
     * worker cannot bring back a deleted run.
     */
    public function appendEvent(string $seqKey, string $listKey, string $runKey, string $body): ?int;

    /**
     * Wait until an event after `$afterSeq` is appended to `$listKey`, or
     * `$blockMs` passed. A blocking read on Redis: the waiter wakes on the
     * event instead of polling.
     */
    public function awaitEvent(string $listKey, int $afterSeq, int $blockMs): void;
}
