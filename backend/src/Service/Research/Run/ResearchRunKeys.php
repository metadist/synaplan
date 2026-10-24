<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * The small set of Redis operations a research run needs. A missing key and
 * a dead store are not the same answer where a mix-up would hide a failure.
 */
interface ResearchRunKeys
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): bool;

    /**
     * Store the value only when the key is free. False when it already holds
     * a value, so two identical starts share one run.
     */
    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool;

    /**
     * Take the lease and index it in one write. True when this caller took it.
     * False when the lease is already held.
     */
    public function claim(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool;

    /**
     * Write the state and release the claim, only while the claim still holds
     * the token and the run still exists. False when the lease was lost or the
     * run was deleted meanwhile.
     */
    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, int $ttlSeconds): bool;

    public function deleteIfEquals(string $key, string $expected): bool;

    /**
     * @throws ResearchStoreUnavailable when Redis did not answer, so a deletion is never skipped silently
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

    public function zAdd(string $key, int $score, string $member): void;

    public function zRem(string $key, string $member): void;

    /**
     * @return list<string>
     */
    public function zRangeByScore(string $key, int $maxScore): array;

    /**
     * Append one event body and return its sequence number. The body is the
     * JSON object `{"event","data"}`; the stored line is `{seq}\n{body}`.
     * The events expire with the run. Null when the run is gone, so a worker
     * cannot bring back a deleted run's history.
     */
    public function appendEvent(string $runId, string $body): ?int;

    /**
     * Keep a source's extracted text until the run expires. False when the
     * run is gone, so a worker cannot bring back a deleted run's text.
     */
    public function putSourceText(string $runId, string $sourceId, string $text): bool;
}
