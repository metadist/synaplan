<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Credential\CredentialNotFoundException;
use App\Service\Credential\CredentialVaultInterface;

/**
 * Reads and writes a {@see OAuthTokenSet} for a connection through the
 * encrypted credential vault (connector plan 07 F2).
 *
 * The whole token set travels as one encrypted JSON blob in BCREDENTIALS, so
 * OAuth needed no schema of its own — and no plaintext token is ever written
 * to BCONNECTIONS, which is the table the API serializes.
 */
final readonly class OAuthTokenStore
{
    public function __construct(
        private CredentialVaultInterface $vault,
        private ConnectionRepository $connections,
    ) {
    }

    /**
     * Persist tokens and keep the connection row in sync (credential id on the
     * first grant, granted scopes on every write so a scope downgrade is
     * visible without decrypting anything).
     */
    public function save(Connection $connection, OAuthTokenSet $tokens): void
    {
        $ownerId = $connection->getOwnerId();
        $credentialId = $connection->getCredentialId();

        if (null === $credentialId) {
            $credentialId = $this->vault->store($ownerId, $connection->getType(), $tokens->toJson());
            $connection->setCredentialId($credentialId);
        } else {
            $this->vault->rotate($credentialId, $ownerId, $tokens->toJson());
        }

        if ([] !== $tokens->scopes) {
            $connection->setScopes($tokens->scopes);
        }

        $this->connections->save($connection);
    }

    /**
     * @throws OAuthReauthRequiredException when nothing usable is stored — the
     *                                      same recovery as an expired grant, so callers need one catch
     */
    public function load(Connection $connection): OAuthTokenSet
    {
        $credentialId = $connection->getCredentialId();
        if (null === $credentialId) {
            throw new OAuthReauthRequiredException(sprintf('Connection %d has no stored credential', (int) $connection->getId()));
        }

        try {
            $json = $this->vault->reveal($credentialId, $connection->getOwnerId());
        } catch (CredentialNotFoundException $e) {
            throw new OAuthReauthRequiredException(sprintf('Stored credential for connection %d is gone', (int) $connection->getId()), 0, $e);
        }

        return OAuthTokenSet::fromJson($json);
    }

    public function forget(Connection $connection): void
    {
        $credentialId = $connection->getCredentialId();
        if (null === $credentialId) {
            return;
        }

        try {
            $this->vault->forget($credentialId, $connection->getOwnerId());
        } catch (CredentialNotFoundException) {
            // Already gone; clearing the pointer below is still correct.
        }

        $connection->setCredentialId(null);
        $this->connections->save($connection);
    }
}
