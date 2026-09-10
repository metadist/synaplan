<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ResumeSavedTaskRunCommand
{
    public function __construct(
        public int $runId,
        public string $nodeId,
        public int $approvalId,
    ) {
    }
}
