<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

/**
 * What the OAuth request in progress is about. One request handles one grant,
 * and the next request replaces both values: the cloud account travels from
 * the authorization code to the tokens issued for it, and the caller's
 * address is the one the token endpoint was called from.
 *
 * Shared for the process, so a value is cleared before it can be read for a
 * different grant.
 */
final class GrantContext
{
    private ?string $externalId = null;

    private string $ip = '';

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function requireExternalId(): string
    {
        if (null === $this->externalId || '' === $this->externalId) {
            throw new \LogicException('This grant has no cloud account.');
        }

        return $this->externalId;
    }

    public function setIp(string $ip): void
    {
        $this->ip = $ip;
    }

    public function ip(): string
    {
        return $this->ip;
    }
}
