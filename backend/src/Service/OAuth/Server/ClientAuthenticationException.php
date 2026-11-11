<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

/**
 * A caller did not prove it is the client, or its address failed too often
 * and has to wait ($retryAfter seconds).
 */
final class ClientAuthenticationException extends \RuntimeException
{
    public function __construct(
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(null === $retryAfter
            ? 'Client authentication failed (private_key_jwt).'
            : 'Too many failed client authentications. Try again later.');
    }

    public function isThrottled(): bool
    {
        return null !== $this->retryAfter;
    }
}
