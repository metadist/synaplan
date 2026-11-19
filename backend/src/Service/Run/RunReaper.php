<?php

declare(strict_types=1);

namespace App\Service\Run;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Recovers a run whose worker disappeared and hands a run that waits too
 * long to its policy. A lost unit is redispatched once; the second loss ends
 * the run as interrupted. It never holds a worker for a wait.
 *
 * Every write takes the unit's claim and goes through the checkpoint, so a
 * worker or an answer that arrives at the same moment is not overwritten.
 */
final readonly class RunReaper
{
    /** A unit that finished without its successor starting is stalled after this long. */
    public const STALL_SECONDS = 600;

    /** The loss that ends the run instead of redispatching it. */
    public const INTERRUPT_ATTEMPT = 2;

    public function __construct(
        private RunCheckpointStore $store,
        private RunReapPolicy $policy,
        private RunUnitBus $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $leaseMs,
    ) {
        if ($leaseMs < 1) {
            throw new \InvalidArgumentException('The reaper needs the lease of a unit.');
        }
    }

    /**
     * @return array{redispatched: int, interrupted: int, waited: int}
     */
    public function reap(): array
    {
        $counts = ['redispatched' => 0, 'interrupted' => 0, 'waited' => 0];
        $broken = function (string $runId, \Throwable $e): void {
            $this->logBroken($runId, $e);
        };
        foreach ($this->store->expiredClaims($broken) as $claim) {
            $this->guarded($claim['runId'], function () use ($claim, &$counts): void {
                $this->reapClaim($claim, $counts);
            });
        }
        foreach ($this->store->activeRuns($broken) as $state) {
            $this->guarded($state->runId(), function () use ($state, &$counts): void {
                $this->reapActive($state, $counts);
            });
        }

        return $counts;
    }

    /** One broken run must not keep every other run from being recovered. */
    private function guarded(string $runId, \Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logBroken($runId, $e);
        }
    }

    private function logBroken(string $runId, \Throwable $e): void
    {
        $this->logger->error('Run reaper could not recover run {run}: {error}', [
            'run' => $runId,
            'error' => $e->getMessage(),
            'exception' => $e,
        ]);
    }

    /**
     * @param array{runId: string, seq: int, token: string}           $claim
     * @param array{redispatched: int, interrupted: int, waited: int} $counts
     */
    private function reapClaim(array $claim, array &$counts): void
    {
        $state = $this->store->load($claim['runId']);
        if (null === $state || $state->isTerminal() || $state->expectedSeq() !== $claim['seq'] || $this->policy->isWaiting($state)) {
            $this->store->release($claim['runId'], $claim['seq'], $claim['token']);

            return;
        }
        $attempt = $this->store->noteLostClaim($claim['runId'], $claim['seq']);
        if ($attempt >= self::INTERRUPT_ATTEMPT) {
            if ($this->interrupt($state, $attempt)) {
                $this->store->release($claim['runId'], $claim['seq'], $claim['token']);
                ++$counts['interrupted'];
            }

            return;
        }
        $this->bus->dispatch($claim['runId'], $this->policy->resumeUnit($state), $claim['seq'], 0);
        $this->store->release($claim['runId'], $claim['seq'], $claim['token']);
        ++$counts['redispatched'];
    }

    /**
     * @param array{redispatched: int, interrupted: int, waited: int} $counts
     */
    private function reapActive(RunState $state, array &$counts): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->policy->isWaiting($state)) {
            if ($this->policy->reapWait($state, $now - $state->updatedAt())) {
                ++$counts['waited'];
            }

            return;
        }
        if (!$this->isStalled($state, $now)) {
            return;
        }
        if ($this->store->lostClaims($state->runId(), $state->expectedSeq()) > 0) {
            $attempt = $this->store->noteLostClaim($state->runId(), $state->expectedSeq());
            if ($this->interrupt($state, $attempt)) {
                ++$counts['interrupted'];
            }

            return;
        }
        $this->bus->dispatch($state->runId(), $this->policy->resumeUnit($state), $state->expectedSeq(), 0);
        $this->store->noteLostClaim($state->runId(), $state->expectedSeq());
        ++$counts['redispatched'];
    }

    /**
     * A unit finished but its successor never started: nobody holds the claim,
     * and neither the last checkpoint nor the last redispatch is recent.
     */
    private function isStalled(RunState $state, int $now): bool
    {
        $since = max($state->updatedAt(), $this->store->lastRedispatchAt($state->runId(), $state->expectedSeq()) ?? 0);

        return $now - $since >= self::STALL_SECONDS
            && !$this->store->isClaimed($state->runId(), $state->expectedSeq());
    }

    private function interrupt(RunState $state, int $attempt): bool
    {
        $saved = $this->store->underClaim(
            $state,
            $this->leaseMs,
            fn (RunState $fresh): bool => $this->policy->interrupt($fresh, $attempt),
        );
        if (null === $saved) {
            return false;
        }
        if ($saved->isTerminal()) {
            $this->policy->closed($saved);
        }

        return true;
    }
}
