<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Filesystem and vector deletes that must run after the DB transaction
 * that removed the assistant has committed. Rolling those back is impossible.
 */
final readonly class AgentExternalCleanup
{
    /**
     * @param list<string> $filePaths
     */
    public function __construct(
        public int $ownerId,
        public string $groupKey,
        public array $filePaths,
    ) {
    }
}
