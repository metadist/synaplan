<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

/**
 * The adapter reached its backend and the backend refused the file (HTTP 4xx).
 * Distinct from "unavailable": health can still be green.
 */
class ExtractorRejectedException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
