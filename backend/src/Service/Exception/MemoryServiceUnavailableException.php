<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown when a user-initiated memory operation (create/update/delete)
 * cannot be performed because the Qdrant backend is not configured
 * or not reachable.
 *
 * Read paths intentionally soft-fail (return empty / null) so that
 * chat and message processing keep working without memory context.
 * Write paths throw this exception so callers (typically controllers)
 * can surface a proper 503 to the user instead of silently succeeding.
 */
final class MemoryServiceUnavailableException extends \RuntimeException
{
    public function __construct(string $detail = 'Memory service unavailable', ?\Throwable $previous = null)
    {
        parent::__construct($detail, 503, $previous);
    }
}
