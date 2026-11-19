<?php

declare(strict_types=1);

namespace App\Service\Run;

use Psr\Clock\ClockInterface;

/**
 * Redis state of one kind of run: the checkpoint and the claim that fences it.
 * A unit writes through checkpoint() while it holds the claim; open() is for a
 * run no worker has claimed yet.
 *
 * By default a checkpoint has no expiry while the run is not terminal, so a
 * run waiting for a person keeps its state until its own deadline ends it. A
 * kind may keep its own sliding expiry instead ($activeTtlHours). A terminal
 * run keeps its keys for the retention window, then Redis drops them.
 */
final readonly class RunCheckpointStore
{
    public const DEFAULT_RETENTION_HOURS = 48;

    public function __construct(
        private RunKeys $keys,
        private RunKeyspace $keyspace,
        private RunStateCodec $codec,
        private ClockInterface $clock,
        private int $retentionHours = self::DEFAULT_RETENTION_HOURS,
        private ?int $activeTtlHours = null,
        private ?\Closure $written = null,
    ) {
        if ($retentionHours < 1 || $retentionHours > 168) {
            throw new \InvalidArgumentException('A finished run is kept between 1 and 168 hours.');
        }
        if (null !== $activeTtlHours && ($activeTtlHours < 1 || $activeTtlHours > 168)) {
            throw new \InvalidArgumentException('A running run is kept between 1 and 168 hours.');
        }
    }

    public function keyspace(): RunKeyspace
    {
        return $this->keyspace;
    }

    /** Store a new run before its first unit is queued. */
    public function open(RunState $state): void
    {
        $runId = $state->runId();
        $now = $this->now();
        $ttl = $this->ttlFor($state);
        $this->keys->set($this->keyspace->state($runId), $state->stored($state->expectedSeq(), $state->checkpointSeq(), $now, self::expiresAt($now, $ttl)), $ttl);
        $this->syncActive($state);
    }

    public function load(string $runId): ?RunState
    {
        $json = $this->keys->get($this->keyspace->state($runId));
        if (null === $json || '' === $json) {
            return null;
        }
        $state = $this->codec->decode($json);
        if (null === $state) {
            return null;
        }
        if ($state->runId() !== $runId) {
            throw new \UnexpectedValueException('A stored run does not match its key.');
        }

        return $state;
    }

    /** The token when this caller took the unit, null when another holds it. */
    public function claim(string $runId, int $seq, int $leaseMs): ?string
    {
        self::assertLease($leaseMs);
        $token = RunClaim::newToken();
        $claimed = $this->keys->claim(
            $this->keyspace->claim($runId, $seq),
            $token,
            $leaseMs,
            $this->keyspace->claims(),
            $this->nowMs() + $leaseMs,
            $this->keyspace->claimMember($runId, $seq, $token),
        );

        return $claimed ? $token : null;
    }

    /** Keep the same lease, only while $token still holds this unit. */
    public function renew(string $runId, int $seq, string $token, int $leaseMs): bool
    {
        self::assertLease($leaseMs);

        return $this->keys->renew(
            $this->keyspace->claim($runId, $seq),
            $token,
            $leaseMs,
            $this->keyspace->claims(),
            $this->nowMs() + $leaseMs,
            $this->keyspace->claimMember($runId, $seq, $token),
        );
    }

    /**
     * Write the run and release the claim, only while $token still holds the
     * claim of the unit in progress. A lost lease leaves the stored run as it
     * was, and the caller does nothing more.
     */
    public function checkpoint(RunState $state, string $token): bool
    {
        $runId = $state->runId();
        $seq = $state->expectedSeq();
        $now = $this->now();
        $ttl = $this->ttlFor($state);
        $expiresAt = self::expiresAt($now, $ttl);
        $written = $this->keys->compareAndSet(
            $this->keyspace->claim($runId, $seq),
            $token,
            $this->keyspace->state($runId),
            $state->stored($seq + 1, $seq, $now, $expiresAt),
            $ttl,
        );
        if (!$written) {
            return false;
        }

        $state->checkpointed($seq + 1, $seq, $now, $expiresAt);
        $this->keys->zRem($this->keyspace->claims(), $this->keyspace->claimMember($runId, $seq, $token));
        $this->syncActive($state);

        return true;
    }

    /**
     * Take the claim of the unit the run waits for, change the run, and
     * checkpoint it. Null when someone else holds the claim, the run moved on
     * meanwhile, or the change was refused.
     *
     * @param \Closure(RunState): bool $change false leaves the run as it is
     */
    public function underClaim(RunState $state, int $leaseMs, \Closure $change): ?RunState
    {
        $runId = $state->runId();
        $seq = $state->expectedSeq();
        $token = $this->claim($runId, $seq, $leaseMs);
        if (null === $token) {
            return null;
        }
        try {
            $fresh = $this->load($runId);
            if (null === $fresh || $fresh->isTerminal() || $fresh->expectedSeq() !== $seq || !$change($fresh)) {
                $this->release($runId, $seq, $token);

                return null;
            }
        } catch (\Throwable $e) {
            $this->release($runId, $seq, $token);

            throw $e;
        }

        return $this->checkpoint($fresh, $token) ? $fresh : null;
    }

    public function release(string $runId, int $seq, string $token): void
    {
        $this->keys->deleteIfEquals($this->keyspace->claim($runId, $seq), $token);
        $this->keys->zRem($this->keyspace->claims(), $this->keyspace->claimMember($runId, $seq, $token));
    }

    public function isClaimed(string $runId, int $seq): bool
    {
        return null !== $this->keys->get($this->keyspace->claim($runId, $seq));
    }

    /**
     * Leases that ran out before their unit was written.
     *
     * @param (\Closure(string, \Throwable): void)|null $broken told about a run whose stored form cannot be read; that run is skipped
     *
     * @return list<array{runId: string, seq: int, token: string}>
     */
    public function expiredClaims(?\Closure $broken = null): array
    {
        $due = [];
        foreach ($this->keys->zRangeByScore($this->keyspace->claims(), $this->nowMs()) as $member) {
            $parsed = $this->keyspace->parseClaimMember($member);
            if (null === $parsed) {
                $this->keys->zRem($this->keyspace->claims(), $member);
                continue;
            }
            $state = $this->loadOrSkip($parsed['runId'], $broken);
            if (false === $state) {
                continue;
            }
            if (null === $state || $state->checkpointSeq() >= $parsed['seq']) {
                $this->keys->zRem($this->keyspace->claims(), $member);
                continue;
            }
            $due[] = $parsed;
        }

        return $due;
    }

    /**
     * Runs that still have work left. A finished or vanished run leaves the index.
     *
     * @param (\Closure(string, \Throwable): void)|null $broken told about a run whose stored form cannot be read; that run is skipped
     *
     * @return list<RunState>
     */
    public function activeRuns(?\Closure $broken = null): array
    {
        $runs = [];
        foreach ($this->keys->members($this->keyspace->active()) as $runId) {
            $state = $this->keyspace->isValid($runId) ? $this->loadOrSkip($runId, $broken) : null;
            if (false === $state) {
                continue;
            }
            if (null === $state || $state->isTerminal()) {
                $this->keys->removeMember($this->keyspace->active(), $runId);
                continue;
            }
            $runs[] = $state;
        }

        return $runs;
    }

    /**
     * Count one more lost or stalled attempt of this unit, and when. A new
     * unit starts counting at one again.
     */
    public function noteLostClaim(string $runId, int $seq): int
    {
        $mark = $this->reapMark($runId, $seq);
        $next = $mark['count'] + 1;
        $this->keys->set($this->keyspace->reap($runId), $seq.'|'.$next.'|'.$this->clock->now()->getTimestamp(), $this->retentionSeconds());

        return $next;
    }

    public function lostClaims(string $runId, int $seq): int
    {
        return $this->reapMark($runId, $seq)['count'];
    }

    /** Unix time of the last redispatch of this unit, null when it never needed one. */
    public function lastRedispatchAt(string $runId, int $seq): ?int
    {
        return $this->reapMark($runId, $seq)['at'];
    }

    /**
     * Ask the run to stop. The flag lives in its own key, so a unit that is
     * running cannot overwrite it with its checkpoint, and its next paid call
     * sees it.
     */
    public function requestCancel(string $runId): void
    {
        $this->keys->set($this->keyspace->cancel($runId), '1', $this->retentionSeconds());
    }

    public function cancelRequested(string $runId): bool
    {
        return null !== $this->keys->get($this->keyspace->cancel($runId));
    }

    private function syncActive(RunState $state): void
    {
        if ($state->isTerminal()) {
            $this->keys->removeMember($this->keyspace->active(), $state->runId());
            $this->keys->expire($this->keyspace->events($state->runId()), $this->retentionSeconds());
            $this->keys->expire($this->keyspace->eventSeq($state->runId()), $this->retentionSeconds());
        } else {
            $this->keys->addMember($this->keyspace->active(), $state->runId());
        }
        if (null !== $this->written) {
            ($this->written)($state);
        }
    }

    /**
     * @return array{count: int, at: ?int}
     */
    private function reapMark(string $runId, int $seq): array
    {
        $raw = $this->keys->get($this->keyspace->reap($runId));
        if (!\is_string($raw) || 1 !== preg_match('/^([0-9]+)\|([0-9]+)\|([0-9]+)$/D', $raw, $match) || (int) $match[1] !== $seq) {
            return ['count' => 0, 'at' => null];
        }

        return ['count' => (int) $match[2], 'at' => (int) $match[3]];
    }

    /** The kind's running expiry (none by default) while work is left; the retention window once the run is over. */
    private function ttlFor(RunState $state): ?int
    {
        if ($state->isTerminal()) {
            return $this->retentionSeconds();
        }

        return null === $this->activeTtlHours ? null : $this->activeTtlHours * 3600;
    }

    private static function expiresAt(int $now, ?int $ttl): ?int
    {
        return null === $ttl ? null : $now + $ttl;
    }

    private static function assertLease(int $leaseMs): void
    {
        if ($leaseMs < 1) {
            throw new \InvalidArgumentException('A run claim needs a lease.');
        }
    }

    private function retentionSeconds(): int
    {
        return $this->retentionHours * 3600;
    }

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * A stored form that cannot be read stays where it is for a person to
     * look at; only a Redis outage stops the whole pass.
     *
     * @param (\Closure(string, \Throwable): void)|null $broken
     *
     * @return RunState|false|null false when the stored run is broken
     */
    private function loadOrSkip(string $runId, ?\Closure $broken): RunState|false|null
    {
        try {
            return $this->load($runId);
        } catch (RunStoreUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (null === $broken) {
                throw $e;
            }
            $broken($runId, $e);

            return false;
        }
    }
}
