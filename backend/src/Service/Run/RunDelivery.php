<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Delivers a finished run into its chat exactly once, on the server: the
 * person does not need the tab open. The worker, a duplicate delivery and the
 * reaper may all reach the end of a run; the first one delivers.
 */
final readonly class RunDelivery
{
    public function __construct(
        private RunKeys $keys,
        private RunKeyspace $keyspace,
        private int $retentionSeconds = RunCheckpointStore::DEFAULT_RETENTION_HOURS * 3600,
    ) {
        if ($retentionSeconds < 1) {
            throw new \InvalidArgumentException('A delivery mark needs a lifetime.');
        }
    }

    /**
     * Run $deliver unless the run was delivered already. A delivery that
     * fails clears the mark, so the next caller tries again.
     *
     * @param \Closure(): void $deliver
     *
     * @return bool true when this call delivered
     */
    public function once(string $runId, \Closure $deliver): bool
    {
        $mark = $this->keyspace->delivered($runId);
        if (!$this->keys->setIfAbsent($mark, '1', $this->retentionSeconds)) {
            return false;
        }
        try {
            $deliver();
        } catch (\Throwable $e) {
            $this->keys->delete($mark);

            throw $e;
        }

        return true;
    }

    public function wasDelivered(string $runId): bool
    {
        return null !== $this->keys->get($this->keyspace->delivered($runId));
    }
}
