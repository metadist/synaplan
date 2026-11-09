<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Continue a plan after a media job that a later step needs has finished.
 * The web request already returned; this runs on a worker.
 */
final readonly class ResumeMediaPlanCommand
{
    public function __construct(
        public int $messageId,
        public string $nodeId,
        public string $jobKey,
    ) {
    }
}
