<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

/**
 * An error answer of the registration endpoints (RFC 7591 section 3.2.2).
 */
final class RegistrationException extends \RuntimeException
{
    public function __construct(public readonly string $error, string $description, public readonly int $status = 400)
    {
        parent::__construct($description);
    }
}
