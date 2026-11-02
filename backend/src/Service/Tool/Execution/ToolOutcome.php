<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Service\Document\Tool\DocumentToolResult;

final readonly class ToolOutcome
{
    public function __construct(
        public ToolOutcomeKind $kind,
        public string $resultText,
        public ?int $approvalId = null,
        public ?DocumentToolResult $document = null,
        public int $durationMs = 0,
    ) {
    }
}
