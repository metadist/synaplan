<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * What a chat in a project adds to a turn: the knowledge folder, pinned on
 * the server, and the instructions block of the system prompt.
 */
final readonly class ProjectTurnContext
{
    public function __construct(
        public string $groupKey,
        public string $name,
        public ?string $instructions,
    ) {
    }
}
