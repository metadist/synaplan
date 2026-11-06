<?php

declare(strict_types=1);

namespace Plugin\Synamail\Service;

/**
 * A profile request the plugin refuses. The message is a stable sentence
 * for the API. It is never a stack trace or a provider error.
 */
final class ProfileException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
