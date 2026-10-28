<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Measured durations (in milliseconds) of one {@see AiCall} (`03_contracts.md` §1.7.2).
 */
final readonly class AiCallTimings
{
    public function __construct(
        public float $queuedMs = 0.0,
        public float $hooksBeforeMs = 0.0,
        public ?float $ttftMs = null,
        public float $providerMs = 0.0,
        public float $hooksAfterMs = 0.0,
    ) {
    }

    public function withHooksAfterMs(float $hooksAfterMs): self
    {
        return new self(
            queuedMs: $this->queuedMs,
            hooksBeforeMs: $this->hooksBeforeMs,
            ttftMs: $this->ttftMs,
            providerMs: $this->providerMs,
            hooksAfterMs: $hooksAfterMs,
        );
    }
}
