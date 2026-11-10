<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use Defuse\Crypto\Crypto;

/**
 * Opens the authorization codes and refresh tokens league encrypts. The key
 * is derived from the app secret, so it is the same on every node and
 * survives a restart. It is the key {@see OAuthServerFactory} hands to league.
 */
final readonly class TokenCipher
{
    public function __construct(private string $secret)
    {
    }

    public function key(): string
    {
        return hash('sha256', 'synaplan-oauth-server:'.$this->secret);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function open(string $token): ?array
    {
        if ('' === $token) {
            return null;
        }
        try {
            $data = json_decode(Crypto::decryptWithPassword($token, $this->key()), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
