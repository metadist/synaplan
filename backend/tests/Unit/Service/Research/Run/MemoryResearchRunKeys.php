<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchKey;
use App\Service\Research\Run\ResearchRunKeys;
use Psr\Clock\ClockInterface;

/**
 * In-memory Redis for research runs. A key disappears when its lease is over,
 * using the same clock the store uses to find expired claims.
 */
final class MemoryResearchRunKeys implements ResearchRunKeys
{
    /** @var array<string, array{value: string, expiresAt: int}> */
    private array $strings = [];

    /** @var array<string, array{items: list<string>, expiresAt: int}> */
    private array $lists = [];

    /** @var array<string, array{members: array<string, true>, expiresAt: int}> */
    private array $sets = [];

    /** @var array<string, array{value: int, expiresAt: int}> */
    private array $counters = [];

    /** @var array<string, array<string, int>> */
    private array $zsets = [];

    /** Runs once, right before the next claim, to interleave a second worker. */
    public ?\Closure $beforeClaim = null;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function get(string $key): ?string
    {
        $this->sweepString($key);

        return $this->strings[$key]['value'] ?? null;
    }

    public function set(string $key, string $value, int $ttlSeconds): bool
    {
        $this->strings[$key] = [
            'value' => $value,
            'expiresAt' => $this->nowMs() + $ttlSeconds * 1000,
        ];

        return true;
    }

    public function claim(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool
    {
        $interleave = $this->beforeClaim;
        $this->beforeClaim = null;
        if (null !== $interleave) {
            $interleave();
        }
        if (null !== $this->get($claimKey)) {
            return false;
        }
        $this->strings[$claimKey] = [
            'value' => $token,
            'expiresAt' => $this->nowMs() + $ttlMillis,
        ];
        $this->zAdd($indexKey, $score, $member);

        return true;
    }

    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, int $ttlSeconds): bool
    {
        if ($this->get($claimKey) !== $token || null === $this->get($stateKey)) {
            return false;
        }
        $this->set($stateKey, $state, $ttlSeconds);
        unset($this->strings[$claimKey]);

        return true;
    }

    public function deleteIfEquals(string $key, string $expected): bool
    {
        if ($this->get($key) !== $expected) {
            return false;
        }
        unset($this->strings[$key]);

        return true;
    }

    public function delete(string $key): void
    {
        unset($this->strings[$key], $this->lists[$key], $this->sets[$key], $this->counters[$key], $this->zsets[$key]);
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        $now = $this->nowMs();
        $expiresAt = $now + $ttlSeconds * 1000;
        if (isset($this->strings[$key]) && $this->strings[$key]['expiresAt'] > $now) {
            $this->strings[$key]['expiresAt'] = $expiresAt;
        }
        if (isset($this->lists[$key]) && $this->lists[$key]['expiresAt'] > $now) {
            $this->lists[$key]['expiresAt'] = $expiresAt;
        }
        if (isset($this->sets[$key]) && $this->sets[$key]['expiresAt'] > $now) {
            $this->sets[$key]['expiresAt'] = $expiresAt;
        }
        if (isset($this->counters[$key]) && $this->counters[$key]['expiresAt'] > $now) {
            $this->counters[$key]['expiresAt'] = $expiresAt;
        }
    }

    public function range(string $key, int $start, int $stop): array
    {
        $this->sweepList($key);
        $items = $this->lists[$key]['items'] ?? [];
        if ([] === $items) {
            return [];
        }
        if (-1 === $stop) {
            $stop = count($items) - 1;
        }
        if ($start > $stop) {
            return [];
        }

        return array_slice($items, $start, $stop - $start + 1);
    }

    public function addMember(string $key, string $member): void
    {
        $this->sweepSet($key);
        $expiresAt = $this->sets[$key]['expiresAt'] ?? ($this->nowMs() + 48 * 3600 * 1000);
        $members = $this->sets[$key]['members'] ?? [];
        $members[$member] = true;
        $this->sets[$key] = ['members' => $members, 'expiresAt' => $expiresAt];
    }

    public function removeMember(string $key, string $member): void
    {
        $this->sweepSet($key);
        unset($this->sets[$key]['members'][$member]);
    }

    public function members(string $key): array
    {
        $this->sweepSet($key);

        return array_keys($this->sets[$key]['members'] ?? []);
    }

    public function zAdd(string $key, int $score, string $member): void
    {
        $this->zsets[$key][$member] = $score;
    }

    public function zRem(string $key, string $member): void
    {
        unset($this->zsets[$key][$member]);
    }

    public function zRangeByScore(string $key, int $maxScore): array
    {
        $due = [];
        foreach ($this->zsets[$key] ?? [] as $member => $score) {
            if ($score <= $maxScore) {
                $due[] = $member;
            }
        }

        return $due;
    }

    public function appendEvent(string $runId, string $body): ?int
    {
        $expiresAt = $this->runExpiresAt($runId);
        if (null === $expiresAt) {
            return null;
        }
        $seqKey = ResearchKey::eventSeq($runId);
        $this->sweepCounter($seqKey);
        $seq = ($this->counters[$seqKey]['value'] ?? 0) + 1;
        $this->counters[$seqKey] = ['value' => $seq, 'expiresAt' => $expiresAt];

        $listKey = ResearchKey::events($runId);
        $this->sweepList($listKey);
        $items = $this->lists[$listKey]['items'] ?? [];
        $items[] = $seq."\n".$body;
        $this->lists[$listKey] = ['items' => $items, 'expiresAt' => $expiresAt];

        return $seq;
    }

    public function putSourceText(string $runId, string $sourceId, string $text): bool
    {
        $expiresAt = $this->runExpiresAt($runId);
        if (null === $expiresAt) {
            return false;
        }
        $this->strings[ResearchKey::text($runId, $sourceId)] = ['value' => $text, 'expiresAt' => $expiresAt];
        $indexKey = ResearchKey::textIndex($runId);
        $this->sweepSet($indexKey);
        $members = $this->sets[$indexKey]['members'] ?? [];
        $members[$sourceId] = true;
        $this->sets[$indexKey] = ['members' => $members, 'expiresAt' => $expiresAt];

        return true;
    }

    private function runExpiresAt(string $runId): ?int
    {
        $runKey = ResearchKey::run($runId);
        $this->sweepString($runKey);

        return $this->strings[$runKey]['expiresAt'] ?? null;
    }

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }

    private function sweepString(string $key): void
    {
        if (isset($this->strings[$key]) && $this->strings[$key]['expiresAt'] <= $this->nowMs()) {
            unset($this->strings[$key]);
        }
    }

    private function sweepList(string $key): void
    {
        if (isset($this->lists[$key]) && $this->lists[$key]['expiresAt'] <= $this->nowMs()) {
            unset($this->lists[$key]);
        }
    }

    private function sweepSet(string $key): void
    {
        if (isset($this->sets[$key]) && $this->sets[$key]['expiresAt'] <= $this->nowMs()) {
            unset($this->sets[$key]);
        }
    }

    private function sweepCounter(string $key): void
    {
        if (isset($this->counters[$key]) && $this->counters[$key]['expiresAt'] <= $this->nowMs()) {
            unset($this->counters[$key]);
        }
    }
}
