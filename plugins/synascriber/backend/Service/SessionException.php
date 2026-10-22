<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Service;

/**
 * A request the plugin refuses, with a stable code for the clients (they
 * translate it) and an English sentence for logs and API users.
 */
final class SessionException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
