<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Entity\Approval;
use App\Service\Document\Tool\DocumentToolResult;

final readonly class ToolOutcome
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public ToolOutcomeKind $kind,
        public string $resultText,
        public ?int $approvalId = null,
        public ?DocumentToolResult $document = null,
        public int $durationMs = 0,
        public ?Approval $approval = null,
        public array $details = [],
    ) {
    }

    public function stopped(): bool
    {
        return ToolOutcomeKind::Refused === $this->kind || ToolOutcomeKind::NeedsApproval === $this->kind;
    }

    public function withDuration(int $durationMs): self
    {
        return new self(
            $this->kind,
            $this->resultText,
            $this->approvalId,
            $this->document,
            $durationMs,
            $this->approval,
            $this->details,
        );
    }
}
