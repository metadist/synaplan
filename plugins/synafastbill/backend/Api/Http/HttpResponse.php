<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Api\Http;

/**
 * Minimal, transport-agnostic HTTP response value object.
 */
final readonly class HttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
