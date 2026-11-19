<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Claims one unit of a run, runs it, and queues the next.
 *
 * A second delivery of the same unit finds the claim taken, or the run moved
 * on, and returns before any paid call. A unit whose lease ran out cannot
 * write: its checkpoint is refused and it does nothing more.
 */
final readonly class RunUnit
{
    /** Gap before the next unit, so one run cannot fill the queue. */
    public const GAP_MS = 3_000;

    public function __construct(
        private RunCheckpointStore $store,
        private RunUnitHandler $handler,
        private RunUnitBus $bus,
    ) {
    }

    public function handle(string $runId, string $unit, int $seq): void
    {
        if ($seq < 1 || !$this->handler->knows($unit) || !$this->store->keyspace()->isValid($runId)) {
            return;
        }
        $state = $this->store->load($runId);
        if (!self::due($state, $seq)) {
            return;
        }
        $leaseMs = $this->handler->leaseMs($state, $unit);
        $token = $this->store->claim($runId, $seq, $leaseMs);
        if (null === $token) {
            return;
        }
        // A duplicate delivery can load before the first one checkpoints and
        // claim right after it. Only the state read under the claim counts.
        $state = $this->store->load($runId);
        if (!self::due($state, $seq)) {
            $this->store->release($runId, $seq, $token);

            return;
        }
        $result = $this->runClaimed($state, $unit, new RunClaim($this->store, $runId, $seq, $token, $leaseMs));
        if (null === $result || !$this->store->checkpoint($state, $token)) {
            return;
        }
        $this->handler->after($state, $unit, $result);
        if (!$state->isTerminal() && null !== $result->next) {
            $this->bus->dispatch($runId, $result->next, $state->expectedSeq(), self::GAP_MS);
        }
    }

    /** @phpstan-assert-if-true RunState $state */
    private static function due(?RunState $state, int $seq): bool
    {
        return null !== $state && !$state->isTerminal() && $state->expectedSeq() === $seq;
    }

    /**
     * Null when the run disappeared meanwhile; the claim is released. Any
     * other failure keeps the claim until its lease runs out, so the reaper
     * redispatches the unit once.
     */
    private function runClaimed(RunState $state, string $unit, RunClaim $claim): ?RunUnitResult
    {
        try {
            if ($claim->cancelled()) {
                return $this->handler->cancel($state);
            }

            return $this->handler->run($state, $unit, $claim);
        } catch (RunNotFound) {
            $this->store->release($claim->runId, $claim->seq, $claim->token);

            return null;
        }
    }
}
