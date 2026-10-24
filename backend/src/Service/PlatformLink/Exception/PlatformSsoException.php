<?php

declare(strict_types=1);

namespace App\Service\PlatformLink\Exception;

/**
 * A refused organisation-login or adoption call. The code is what the app
 * branches on; the message is one sentence a person can read.
 */
final class PlatformSsoException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
