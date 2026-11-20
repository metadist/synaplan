<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunKeys;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * In-memory Redis for the run kernel. A key disappears when its time is up,
 * on the same clock the store uses to find expired claims. A null TTL keeps
 * a key until it is deleted.
 */
final class MemoryRunKeys implements RunKeys
{
    private const FOREVER = \PHP_INT_MAX;

    /** @var array<string, array{value: string, expiresAt: int}> */
    public array $strings = [];

    /** @var array<string, array{items: list<string>, expiresAt: int}> */
    private array $lists = [];

    /** @var array<string, array<string, true>> */
    private array $sets = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, array<string, int>> */
    private array $zsets = [];

    /** Runs once, right before the next claim, to interleave a second worker. */
    public ?\Closure $beforeClaim = null;

    /** Runs while a reader waits for an event, as a worker does meanwhile. */
    public ?\Closure $whileAwaiting = null;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function get(string $key): ?string
    {
        $this->sweep($key);

        return $this->strings[$key]['value'] ?? null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        $this->strings[$key] = ['value' => $value, 'expiresAt' => $this->expiry($ttlSeconds)];

        return true;
    }

    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        if (null !== $this->get($key)) {
            return false;
        }

        return $this->set($key, $value, $ttlSeconds);
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
        $this->strings[$claimKey] = ['value' => $token, 'expiresAt' => $this->nowMs() + $ttlMillis];
        $this->zsets[$indexKey][$member] = $score;

        return true;
    }

    public function renew(string $claimKey, string $token, int $ttlMillis, string $indexKey, int $score, string $member): bool
    {
        if ($this->get($claimKey) !== $token) {
            return false;
        }
        $this->strings[$claimKey]['expiresAt'] = $this->nowMs() + $ttlMillis;
        $this->zsets[$indexKey][$member] = $score;

        return true;
    }

    public function compareAndSet(string $claimKey, string $token, string $stateKey, string $state, ?int $ttlSeconds): bool
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
        if (isset($this->strings[$key])) {
            $this->strings[$key]['expiresAt'] = $this->expiry($ttlSeconds);
        }
        if (isset($this->lists[$key])) {
            $this->lists[$key]['expiresAt'] = $this->expiry($ttlSeconds);
        }
    }

    public function range(string $key, int $start, int $stop): array
    {
        $this->sweep($key);
        $items = $this->lists[$key]['items'] ?? [];
        if (-1 === $stop) {
            $stop = \count($items) - 1;
        }

        return $start > $stop ? [] : array_slice($items, $start, $stop - $start + 1);
    }

    public function addMember(string $key, string $member): void
    {
        $this->sets[$key][$member] = true;
    }

    public function removeMember(string $key, string $member): void
    {
        unset($this->sets[$key][$member]);
    }

    public function members(string $key): array
    {
        return array_map('strval', array_keys($this->sets[$key] ?? []));
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
                $due[] = (string) $member;
            }
        }

        return $due;
    }

    public function appendEvent(string $seqKey, string $listKey, string $runKey, string $body): ?int
    {
        $this->sweep($runKey);
        if (!isset($this->strings[$runKey])) {
            return null;
        }
        $expiresAt = $this->strings[$runKey]['expiresAt'];
        $seq = ($this->counters[$seqKey] ?? 0) + 1;
        $this->counters[$seqKey] = $seq;
        $items = $this->lists[$listKey]['items'] ?? [];
        $items[] = $seq."\n".$body;
        $this->lists[$listKey] = ['items' => $items, 'expiresAt' => $expiresAt];

        return $seq;
    }

    public function awaitEvent(string $listKey, int $afterSeq, int $blockMs): void
    {
        if ($this->clock instanceof MockClock) {
            $this->clock->sleep($blockMs / 1000);
        }
        if (null !== $this->whileAwaiting) {
            ($this->whileAwaiting)();
        }
    }

    /** Milliseconds until the key expires, -1 when it never does, null when it is gone. */
    public function ttlMs(string $key): ?int
    {
        $this->sweep($key);
        $expiresAt = $this->strings[$key]['expiresAt'] ?? $this->lists[$key]['expiresAt'] ?? null;
        if (null === $expiresAt) {
            return null;
        }

        return self::FOREVER === $expiresAt ? -1 : $expiresAt - $this->nowMs();
    }

    /** Every key name that still holds something. */
    public function keys(): array
    {
        foreach (array_keys($this->strings) as $key) {
            $this->sweep((string) $key);
        }

        return array_values(array_unique(array_map('strval', [
            ...array_keys($this->strings),
            ...array_keys($this->lists),
            ...array_keys(array_filter($this->sets)),
            ...array_keys($this->counters),
            ...array_keys(array_filter($this->zsets)),
        ])));
    }

    private function expiry(?int $ttlSeconds): int
    {
        return null === $ttlSeconds ? self::FOREVER : $this->nowMs() + $ttlSeconds * 1000;
    }

    private function sweep(string $key): void
    {
        $now = $this->nowMs();
        if (isset($this->strings[$key]) && $this->strings[$key]['expiresAt'] <= $now) {
            unset($this->strings[$key]);
        }
        if (isset($this->lists[$key]) && $this->lists[$key]['expiresAt'] <= $now) {
            unset($this->lists[$key]);
        }
    }

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }
}
