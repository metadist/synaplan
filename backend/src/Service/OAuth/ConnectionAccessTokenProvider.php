<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use Psr\Log\LoggerInterface;

/**
 * Hands out a usable access token for a connection, refreshing it when needed.
 *
 * This is the seam the scheduler depends on (connector plan 07 F3): a task
 * firing at 07:00 must refresh a token with nobody logged in. The constructor
 * therefore takes no Security, no session and no current user — everything is
 * derived from the connection row's owner, exactly like
 * {@see \App\Service\SavedTask\SavedTaskRunner} does. A test pins that.
 */
final readonly class ConnectionAccessTokenProvider
{
    /**
     * Refresh this long before the token actually expires. A scheduled run can
     * sit in a queue for a while, and Graph rejects a token that expired
     * mid-flight with a 401 the run would report as a failure.
     */
    private const EXPIRY_SKEW_SECONDS = 300;

    public function __construct(
        private OAuthClient $client,
        private OAuthTokenStore $tokens,
        private OAuthProviderRegistry $providers,
        private ConnectionRepository $connections,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OAuthReauthRequiredException when the user must consent again;
     *                                      the connection is marked before this is thrown
     * @throws OAuthException               on a transient failure (network, 5xx) — safe to retry later
     */
    public function accessTokenFor(Connection $connection): string
    {
        $tokens = $this->loadOrFail($connection);

        if (!$tokens->isExpired(self::EXPIRY_SKEW_SECONDS)) {
            return $tokens->accessToken;
        }

        return $this->refresh($connection, $tokens);
    }

    /**
     * Force a refresh even if the stored token still looks valid — used after a
     * 401 from the resource server, which is the only authority on whether a
     * token really works.
     */
    public function refreshNow(Connection $connection): string
    {
        return $this->refresh($connection, $this->loadOrFail($connection));
    }

    private function refresh(Connection $connection, OAuthTokenSet $current): string
    {
        $providerId = $this->providerIdFor($connection);

        try {
            $provider = $this->providers->get($providerId)->toProviderConfig();
            $refreshed = $this->client->refresh($provider, $current->refreshToken);
        } catch (OAuthReauthRequiredException $e) {
            $this->markReauthRequired($connection, $e);

            throw $e;
        }

        $merged = $current->withTokens($refreshed);
        $this->tokens->save($connection, $merged);

        if (Connection::STATUS_CONNECTED !== $connection->getStatus()) {
            $connection->markChecked(Connection::STATUS_CONNECTED);
            $this->connections->save($connection);
        }

        $this->logger->info('OAuth access token refreshed', [
            'connection_id' => $connection->getId(),
            'owner_id' => $connection->getOwnerId(),
            'provider' => $providerId,
            'expires_at' => $merged->expiresAt,
        ]);

        return $merged->accessToken;
    }

    private function loadOrFail(Connection $connection): OAuthTokenSet
    {
        try {
            return $this->tokens->load($connection);
        } catch (OAuthReauthRequiredException $e) {
            $this->markReauthRequired($connection, $e);

            throw $e;
        }
    }

    private function markReauthRequired(Connection $connection, \Throwable $cause): void
    {
        $connection->markChecked(Connection::STATUS_REAUTH_REQUIRED);
        $this->connections->save($connection);

        $this->logger->warning('OAuth connection needs the user to sign in again', [
            'connection_id' => $connection->getId(),
            'owner_id' => $connection->getOwnerId(),
            'reason' => $cause->getMessage(),
        ]);
    }

    /**
     * The provider id lives in the connection config so one connection type can
     * be served by several authorization servers later without a schema change.
     */
    private function providerIdFor(Connection $connection): string
    {
        $config = $connection->getConfig() ?? [];
        $provider = $config['provider'] ?? null;

        if (!is_string($provider) || '' === $provider) {
            throw new OAuthException(sprintf('Connection %d has no OAuth provider recorded', (int) $connection->getId()));
        }

        return $provider;
    }
}
