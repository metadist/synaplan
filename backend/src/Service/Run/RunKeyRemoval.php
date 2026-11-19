<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Deletes every Redis key of one run. It needs no decoded state, so a run
 * whose stored form is broken is still removed completely.
 */
final readonly class RunKeyRemoval
{
    public function __construct(
        private RunKeys $keys,
        private RunKeyspace $keyspace,
    ) {
    }

    /**
     * @throws RunStoreUnavailable when Redis did not answer, so a deletion is never skipped silently
     */
    public function delete(string $runId): void
    {
        $this->keyspace->assert($runId);
        foreach ($this->keys->zRangeByScore($this->keyspace->claims(), \PHP_INT_MAX) as $member) {
            $parsed = $this->keyspace->parseClaimMember($member);
            if (null === $parsed || $parsed['runId'] !== $runId) {
                continue;
            }
            $this->keys->delete($this->keyspace->claim($runId, $parsed['seq']));
            $this->keys->zRem($this->keyspace->claims(), $member);
        }
        $this->keys->delete($this->keyspace->state($runId));
        $this->keys->delete($this->keyspace->events($runId));
        $this->keys->delete($this->keyspace->eventSeq($runId));
        $this->keys->delete($this->keyspace->reap($runId));
        $this->keys->delete($this->keyspace->cancel($runId));
        $this->keys->delete($this->keyspace->delivered($runId));
        $this->keys->removeMember($this->keyspace->active(), $runId);
    }
}
