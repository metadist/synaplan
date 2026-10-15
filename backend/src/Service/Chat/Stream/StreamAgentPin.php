<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * The assistant a turn is pinned to. `draft` runs the owner's unpublished
 * draft (test panel); `versionId` is the published version that answers.
 */
final readonly class StreamAgentPin
{
    public function __construct(
        public int $agentId,
        public bool $draft = false,
        public ?int $versionId = null,
    ) {
    }
}
