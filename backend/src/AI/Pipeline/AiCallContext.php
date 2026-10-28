<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Caller, correlation and billing metadata for one {@see AiCall} (`03_contracts.md` §1.1).
 */
final readonly class AiCallContext
{
    public function __construct(
        public ?int $userId,
        public AiCallSource $source,
        public ?string $requestId = null,
        public ?string $turnId = null,
        public ?string $runId = null,
        public ?int $messageId = null,
        public bool $billable = true,
        public int $startedHrtimeNs = 0,
    ) {
    }

    public static function probe(?string $requestId = null): self
    {
        return new self(
            userId: null,
            source: AiCallSource::Probe,
            requestId: $requestId,
            billable: false,
        );
    }

    public function withStamp(?string $requestId, ?string $runId, int $startedHrtimeNs): self
    {
        return new self(
            userId: $this->userId,
            source: $this->source,
            requestId: $this->requestId ?? $requestId,
            turnId: $this->turnId,
            runId: $this->runId ?? $runId,
            messageId: $this->messageId,
            billable: $this->billable,
            startedHrtimeNs: $this->startedHrtimeNs > 0 ? $this->startedHrtimeNs : $startedHrtimeNs,
        );
    }
}
