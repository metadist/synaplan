<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchClock;
use App\Service\Research\Run\ResearchKey;
use App\Service\Research\Run\ResearchRunKeys;

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

    public function __construct(private readonly ResearchClock $clock)
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
            'expiresAt' => $this->clock->nowMs() + $ttlSeconds * 1000,
        ];

        return true;
    }

    public function setIfAbsent(string $key, string $value, int $ttlMillis): bool
    {
        if (null !== $this->get($key)) {
            return false;
        }
        $this->strings[$key] = [
            'value' => $value,
            'expiresAt' => $this->clock->nowMs() + $ttlMillis,
        ];

        return true;
    }

    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, int $ttlSeconds): bool
    {
        if ($this->get($claimKey) !== $token) {
            return false;
        }
        $this->set($stateKey, $state, $ttlSeconds);

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
        $expiresAt = $this->clock->nowMs() + $ttlSeconds * 1000;
        if (isset($this->strings[$key]) && $this->strings[$key]['expiresAt'] > $this->clock->nowMs()) {
            $this->strings[$key]['expiresAt'] = $expiresAt;
        }
        if (isset($this->lists[$key]) && $this->lists[$key]['expiresAt'] > $this->clock->nowMs()) {
            $this->lists[$key]['expiresAt'] = $expiresAt;
        }
        if (isset($this->sets[$key]) && $this->sets[$key]['expiresAt'] > $this->clock->nowMs()) {
            $this->sets[$key]['expiresAt'] = $expiresAt;
        }
        if (isset($this->counters[$key]) && $this->counters[$key]['expiresAt'] > $this->clock->nowMs()) {
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
        $expiresAt = $this->sets[$key]['expiresAt'] ?? ($this->clock->nowMs() + 48 * 3600 * 1000);
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

    public function appendEvent(string $runId, string $body, int $ttlSeconds): int
    {
        $expiresAt = $this->clock->nowMs() + $ttlSeconds * 1000;
        $seqKey = ResearchKey::eventSeq($runId);
        $this->sweepCounter($seqKey);
        $seq = ($this->counters[$seqKey]['value'] ?? 0) + 1;
        $this->counters[$seqKey] = ['value' => $seq, 'expiresAt' => $expiresAt];

        $listKey = ResearchKey::events($runId);
        $this->sweepList($listKey);
        $items = $this->lists[$listKey]['items'] ?? [];
        $items[] = $seq."\n".$body;
        $this->lists[$listKey] = ['items' => $items, 'expiresAt' => $expiresAt];

        $runKey = ResearchKey::run($runId);
        if (isset($this->strings[$runKey]) && $this->strings[$runKey]['expiresAt'] > $this->clock->nowMs()) {
            $this->strings[$runKey]['expiresAt'] = $expiresAt;
        }

        return $seq;
    }

    private function sweepString(string $key): void
    {
        if (isset($this->strings[$key]) && $this->strings[$key]['expiresAt'] <= $this->clock->nowMs()) {
            unset($this->strings[$key]);
        }
    }

    private function sweepList(string $key): void
    {
        if (isset($this->lists[$key]) && $this->lists[$key]['expiresAt'] <= $this->clock->nowMs()) {
            unset($this->lists[$key]);
        }
    }

    private function sweepSet(string $key): void
    {
        if (isset($this->sets[$key]) && $this->sets[$key]['expiresAt'] <= $this->clock->nowMs()) {
            unset($this->sets[$key]);
        }
    }

    private function sweepCounter(string $key): void
    {
        if (isset($this->counters[$key]) && $this->counters[$key]['expiresAt'] <= $this->clock->nowMs()) {
            unset($this->counters[$key]);
        }
    }
}
