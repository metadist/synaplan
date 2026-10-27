<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Outcome, value, usage and timings of an {@see AiCall} (`03_contracts.md` §1.1).
 */
final readonly class AiCallResult
{
    public function __construct(
        public AiCallOutcome $outcome,
        public mixed $value,
        public ?AiUsage $usage,
        public AiCallTimings $timings,
        public ?string $failureClass = null,
        public ?\Throwable $exception = null,
    ) {
    }

    public function withUsage(?AiUsage $usage): self
    {
        return new self(
            outcome: $this->outcome,
            value: $this->value,
            usage: $usage,
            timings: $this->timings,
            failureClass: $this->failureClass,
            exception: $this->exception,
        );
    }

    public function withValue(mixed $value): self
    {
        return new self(
            outcome: $this->outcome,
            value: $value,
            usage: $this->usage,
            timings: $this->timings,
            failureClass: $this->failureClass,
            exception: $this->exception,
        );
    }

    public function withTimings(AiCallTimings $timings): self
    {
        return new self(
            outcome: $this->outcome,
            value: $this->value,
            usage: $this->usage,
            timings: $timings,
            failureClass: $this->failureClass,
            exception: $this->exception,
        );
    }
}
