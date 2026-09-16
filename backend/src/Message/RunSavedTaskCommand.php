<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Starts a Saved Task run off the request path. Dispatched by the public
 * webhook ingress after it has validated the token, signature and limits.
 */
final readonly class RunSavedTaskCommand
{
    /**
     * @param array<string, mixed> $triggerPayload JSON body of the starting event
     */
    public function __construct(
        public int $ownerId,
        public int $taskId,
        public string $trigger,
        public array $triggerPayload = [],
    ) {
    }
}
