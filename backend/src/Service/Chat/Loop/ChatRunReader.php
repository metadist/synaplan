<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunKeyspace;
use App\Service\Run\RunNotFound;

/**
 * Reads the events of a chat run for the person who started it. A run id is
 * not a secret: anyone else, and any id that is not a chat run, gets the
 * same "not found".
 */
final readonly class ChatRunReader
{
    public function __construct(
        private ChatRunRecords $records,
        private ChatRunEvents $events,
    ) {
    }

    /**
     * @return list<array{seq: int, event: string, data: array<string, mixed>}>
     *
     * @throws RunNotFound when the run is not the caller's or does not exist
     */
    public function events(string $runId, int $callerId, int $after = 0): array
    {
        $this->assertCaller($runId, $callerId);

        return $this->events->replay($runId, max(0, $after));
    }

    /**
     * @throws RunNotFound when the run is not the caller's or does not exist
     */
    public function assertCaller(string $runId, int $callerId): void
    {
        if ($callerId < 1 || !RunKeyspace::runs()->isValid($runId) || null === $this->records->forCaller($runId, $callerId)) {
            throw new RunNotFound($runId);
        }
    }
}
