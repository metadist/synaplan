<?php

declare(strict_types=1);

namespace App\AI\Import;

/**
 * Thrown when an import source string names no known endpoint (unknown
 * OpenAI-compatible endpoint name, or an unsupported source prefix). The
 * controller maps this to HTTP 404 — distinct from a reachable endpoint that
 * simply lists nothing.
 */
final class UnknownImportSourceException extends \RuntimeException
{
}
