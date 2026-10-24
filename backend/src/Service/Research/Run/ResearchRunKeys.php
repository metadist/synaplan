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
     * True when this caller stored the value. False when the key is already held.
     */
    public function setIfAbsent(string $key, string $value, int $ttlMillis): bool;

    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, int $ttlSeconds): bool;

    public function deleteIfEquals(string $key, string $expected): bool;

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
     */
    public function appendEvent(string $runId, string $body, int $ttlSeconds): int;
}
