<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Generates and sanitises correlation ids.
 *
 * A correlation id ties every log record, event-ring entry and error response
 * of a single request together, so a user-reported id ("I saw abc123") can be
 * traced back to the exact server-side events without exposing any payload.
 *
 * Ids are opaque, non-sensitive tokens — never derived from user data.
 */
final readonly class RequestIdGenerator
{
    /** Request attribute + response header carry the correlation id under this name. */
    public const ATTRIBUTE = '_correlation_id';
    public const HEADER = 'X-Request-Id';

    private const MAX_LENGTH = 128;

    /**
     * A fresh, random correlation id (32 hex chars). No user input involved.
     */
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Accept a caller-supplied correlation id only when it is a short, opaque
     * token; otherwise fall back to a generated one. This lets an upstream
     * proxy propagate its own trace id without letting a client inject
     * arbitrary (or PII-laden) content into our logs.
     */
    public function sanitize(?string $candidate): string
    {
        if (null === $candidate) {
            return $this->generate();
        }

        $candidate = trim($candidate);
        if ('' === $candidate || \strlen($candidate) > self::MAX_LENGTH) {
            return $this->generate();
        }

        if (1 !== preg_match('/^[A-Za-z0-9._-]+$/', $candidate)) {
            return $this->generate();
        }

        return $candidate;
    }
}
