<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunCheckpointStore;
use App\Service\Run\RunKeys;
use App\Service\Run\RunKeyspace;
use Psr\Clock\ClockInterface;

/**
 * The checkpoints of chat runs, in the kernel's `run:` keyspace. A finished
 * run is kept for the retention window and then expires; account deletion
 * removes it at once ({@see \App\Service\Iam\Erasure\RunEraser}).
 */
final readonly class ChatRunStore
{
    /** A waiting run stays alive while it waits for its owner. */
    public const ACTIVE_TTL_HOURS = 72;

    private RunCheckpointStore $runs;

    public function __construct(RunKeys $keys, ClockInterface $clock)
    {
        $this->runs = new RunCheckpointStore(
            $keys,
            RunKeyspace::runs(),
            new ChatRunCodec(),
            $clock,
            RunCheckpointStore::DEFAULT_RETENTION_HOURS,
            self::ACTIVE_TTL_HOURS,
        );
    }

    public function runs(): RunCheckpointStore
    {
        return $this->runs;
    }

    public function load(string $runId): ?ChatRunState
    {
        if (!RunKeyspace::runs()->isValid($runId)) {
            return null;
        }
        try {
            $state = $this->runs->load($runId);
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $state instanceof ChatRunState ? $state : null;
    }
}
