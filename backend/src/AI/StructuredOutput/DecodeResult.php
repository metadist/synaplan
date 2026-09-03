<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * Outcome of {@see JsonResponseDecoder::decode()}. Deliberately explicit
 * about failure — the six legacy parsers this replaces all collapsed a parse
 * failure into a silent default (MessageSorter → topic "general", etc.),
 * which is exactly the failure mode phase 0a's `invalid_topic_rate` /
 * `parse_failure_rate` metrics exist to surface.
 */
final readonly class DecodeResult
{
    private function __construct(
        public bool $success,
        /** @var array<string, mixed>|null */
        public ?array $data,
        public ?string $errorReason,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(array $data): self
    {
        return new self(true, $data, null);
    }

    public static function fail(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
