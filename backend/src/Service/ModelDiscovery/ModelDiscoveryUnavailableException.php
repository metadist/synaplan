<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * Raised when the upstream model list cannot be fetched or fails validation.
 *
 * A broken source must never look like "nothing new" — callers map this to
 * exit code 1 so operators know the check did not run.
 */
final class ModelDiscoveryUnavailableException extends \RuntimeException
{
}
