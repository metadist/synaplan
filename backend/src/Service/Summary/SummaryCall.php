<?php

declare(strict_types=1);

namespace App\Service\Summary;

/**
 * One summary for the DocumentSummarizer: the instructions (system prompt),
 * the message that carries the document, the temperature, and what the
 * usage log records — its kind, its input and, from the request's start, its
 * latency.
 */
final readonly class SummaryCall
{
    public function __construct(
        public string $instructions,
        public string $message,
        public float $temperature,
        public string $usageKind,
        public string $usageInput,
        public ?float $startedAt = null,
    ) {
    }
}
