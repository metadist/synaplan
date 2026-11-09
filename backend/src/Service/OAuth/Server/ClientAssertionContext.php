<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

/**
 * The client a token request proved to be, by a verified private_key_jwt
 * assertion. Set by the token endpoint before the request reaches league,
 * read by the client repository: league only knows client secrets, and
 * Synaplan's clients have none.
 */
final class ClientAssertionContext implements \Symfony\Contracts\Service\ResetInterface
{
    private ?string $clientId = null;

    public function verified(string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function isVerified(string $clientId): bool
    {
        return null !== $this->clientId && hash_equals($this->clientId, $clientId);
    }

    public function reset(): void
    {
        $this->clientId = null;
    }
}
