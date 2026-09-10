<?php

declare(strict_types=1);

namespace App\Plug\Extraction\Docling;

use App\Plug\Extraction\ExtractorRejectedException;

/**
 * Docling answered the convert request but refused it (HTTP 4xx).
 * Distinct from {@see DoclingUnavailableException}: the sidecar is reachable.
 */
final class DoclingRejectedException extends ExtractorRejectedException
{
}
