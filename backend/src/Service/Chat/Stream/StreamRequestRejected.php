<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * A stream request that ends before a turn starts.
 *
 * Usually a JSON error with its HTTP status. A guest who used up the trial
 * gets one SSE event instead, because the client reads that answer as an
 * event stream.
 */
final class StreamRequestRejected extends \RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly array $payload,
        public readonly int $httpStatus,
        public readonly ?string $sseEvent,
    ) {
        parent::__construct(sprintf('Stream request rejected (%s)', $sseEvent ?? (string) $httpStatus));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $httpStatus): self
    {
        return new self($payload, $httpStatus, null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function sseEvent(string $event, array $payload): self
    {
        return new self($payload, 200, $event);
    }
}
