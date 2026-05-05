<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message dispatched by the admin "switch embedding model"
 * endpoint. The handler re-vectorizes one or more scopes (documents,
 * memories, synapse) using the model already set as VECTORIZE default
 * at the time of dispatch, updating the matching `BREVECTORIZE_RUNS`
 * row as it progresses.
 *
 * Queued in: async_index (low priority — Re-Vectorize can take many
 * minutes and must not block AI chat traffic).
 */
final readonly class ReVectorizeMessage
{
    public function __construct(
        public int $runId,
    ) {
    }
}
