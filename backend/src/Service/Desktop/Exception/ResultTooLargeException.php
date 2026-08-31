<?php

declare(strict_types=1);

namespace App\Service\Desktop\Exception;

/**
 * Thrown when a device reports a result whose JSON payload exceeds the size cap.
 * The result is untrusted input re-entering the account, so an oversized
 * payload is refused rather than stored.
 */
final class ResultTooLargeException extends \RuntimeException
{
}
