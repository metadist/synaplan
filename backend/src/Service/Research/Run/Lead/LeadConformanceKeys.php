<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\ResearchRunKeys;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Enough of a run store for the conformance suite to append events and keep
 * a page. Nothing is shared and nothing talks to Redis.
 */
#[Exclude]
final class LeadConformanceKeys implements ResearchRunKeys
{
    /** @var list<string> */
    public array $events = [];

    public function get(string $key): ?string
    {
        return null;
    }

    public function set(string $key, string $value, int $ttlSeconds): bool
    {
        return true;
    }

    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        return true;
    }

    public function claim(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool
    {
        return false;
    }

    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, int $ttlSeconds): bool
    {
        return false;
    }

    public function deleteIfEquals(string $key, string $expected): bool
    {
        return false;
    }

    public function delete(string $key): void
    {
    }

    public function expire(string $key, int $ttlSeconds): void
    {
    }

    public function range(string $key, int $start, int $stop): array
    {
        return [];
    }

    public function addMember(string $key, string $member): void
    {
    }

    public function removeMember(string $key, string $member): void
    {
    }

    public function members(string $key): array
    {
        return [];
    }

    public function zAdd(string $key, int $score, string $member): void
    {
    }

    public function zRem(string $key, string $member): void
    {
    }

    public function zRangeByScore(string $key, int $maxScore): array
    {
        return [];
    }

    public function appendEvent(string $runId, string $body): ?int
    {
        if ('' === $runId) {
            return null;
        }
        $this->events[] = $body;

        return count($this->events);
    }

    public function putSourceText(string $runId, string $sourceId, string $text): bool
    {
        return true;
    }
}
