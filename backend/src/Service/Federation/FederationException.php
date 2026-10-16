<?php

declare(strict_types=1);

namespace App\Service\Federation;

/**
 * A federation failure with a stable code and one sentence for the admin.
 */
final class FederationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
