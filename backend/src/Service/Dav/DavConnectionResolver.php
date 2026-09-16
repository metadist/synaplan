<?php

declare(strict_types=1);

namespace App\Service\Dav;

use App\Entity\Connection;
use App\Service\Credential\CredentialNotFoundException;
use App\Service\Credential\CredentialVaultInterface;

/**
 * Shared "connection row → DavTarget" resolution for the tester and both
 * destination providers. Null when the row is unusable (no credential, no
 * base URL) — the caller phrases the failure for its own surface.
 */
final readonly class DavConnectionResolver
{
    public static function resolve(Connection $connection, CredentialVaultInterface $vault): ?DavTarget
    {
        $credentialId = $connection->getCredentialId();
        if (null === $credentialId) {
            return null;
        }

        $config = $connection->getConfig() ?? [];
        $baseUrl = is_string($config['base_url'] ?? null) ? trim($config['base_url']) : '';
        $username = is_string($config['username'] ?? null) ? trim($config['username']) : '';
        if ('' === $baseUrl || '' === $username) {
            return null;
        }

        try {
            $password = $vault->reveal($credentialId, $connection->getOwnerId());
        } catch (CredentialNotFoundException) {
            return null;
        }

        return new DavTarget($baseUrl, $username, $password);
    }
}
