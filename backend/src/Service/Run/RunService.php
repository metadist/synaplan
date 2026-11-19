<?php

declare(strict_types=1);

namespace App\Service\Run;

use Psr\Clock\ClockInterface;

/**
 * Starts and stops runs of one kind. A start with the same idempotency key
 * within 24 hours returns the run the first call opened, so a retried click
 * never pays twice. A stop is a flag the running unit reads before its next
 * paid call; stopping twice is the same as stopping once.
 */
final readonly class RunService
{
    public const IDEMPOTENCY_SECONDS = 86_400;
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 64;

    public function __construct(
        private RunKeys $keys,
        private RunCheckpointStore $store,
        private RunUnitBus $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param \Closure(string): RunState $open builds the new run for an id; it is called only when this call opens the run
     *
     * @throws RunStoreUnavailable when the key's earlier run cannot be read back
     */
    public function start(int $userId, ?string $idempotencyKey, string $firstUnit, \Closure $open): RunStart
    {
        $keyspace = $this->store->keyspace();
        $runId = RunIds::newId($this->clock);
        if (!$keyspace->isValid($runId)) {
            throw new \LogicException('Only runs addressed by a ULID start here.');
        }
        if (null !== $idempotencyKey) {
            self::assertIdempotencyKey($idempotencyKey);
            $redisKey = $keyspace->idempotency($userId, $idempotencyKey);
            if (!$this->keys->setIfAbsent($redisKey, $runId, self::IDEMPOTENCY_SECONDS)) {
                $existing = $this->keys->get($redisKey);
                if (null === $existing || !$keyspace->isValid($existing)) {
                    throw new RunStoreUnavailable();
                }

                return new RunStart($existing, false);
            }
        }

        try {
            $state = $open($runId);
            if ($state->runId() !== $runId || $state->isTerminal() || 1 !== $state->expectedSeq()) {
                throw new \LogicException('A new run starts at its first unit with the id it was given.');
            }
            $this->store->open($state);
        } catch (\Throwable $e) {
            // A retry with the same key must open the run, not find one that never existed.
            if (null !== $idempotencyKey) {
                $this->keys->deleteIfEquals($keyspace->idempotency($userId, $idempotencyKey), $runId);
            }

            throw $e;
        }
        $this->bus->dispatch($runId, $firstUnit, 1, 0);

        return new RunStart($runId, true);
    }

    public function stop(string $runId): void
    {
        $this->store->requestCancel($runId);
    }

    private static function assertIdempotencyKey(string $key): void
    {
        if ('' === $key || \strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH || 1 !== preg_match('/^[\x21-\x7E]+$/D', $key)) {
            throw new \InvalidArgumentException('An idempotency key is 1 to 64 visible ASCII characters.');
        }
    }
}
