<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\OAuthStateService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives the interactive half of the OAuth flow: build the consent URL, then
 * turn the redirect back into a stored connection.
 *
 * Two deliberate choices, both security-relevant:
 *
 * 1. **The PKCE verifier never leaves the server.** {@see OAuthStateService}
 *    tokens are signed but readable, and the state travels through the
 *    identity provider and the browser's history. Only the state's nonce goes
 *    out; the verifier waits in the cache under a hash of that nonce and is
 *    consumed exactly once.
 * 2. **The signed state carries the owner id**, so the callback does not depend
 *    on a cookie arriving. In production the auth cookie is SameSite=Strict and
 *    is NOT sent on a cross-site redirect back from Microsoft — a callback that
 *    relied on the session would work in dev and fail in production.
 */
final readonly class OAuthConsentService
{
    private const VERIFIER_CACHE_PREFIX = 'oauth_pkce_';
    private const VERIFIER_TTL_SECONDS = 600;

    public function __construct(
        private OAuthClient $client,
        private OAuthProviderRegistry $providers,
        private OAuthTokenStore $tokens,
        private OAuthStateService $state,
        private ConnectionRepository $connections,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string the URL the browser must be sent to
     */
    public function authorizationUrl(int $ownerId, string $providerId): string
    {
        $provider = $this->providers->get($providerId)->toProviderConfig();

        $verifier = $this->client->generateCodeVerifier();
        $nonce = bin2hex(random_bytes(16));

        $item = $this->cache->getItem($this->verifierKey($nonce));
        $item->set(['verifier' => $verifier, 'owner_id' => $ownerId]);
        $item->expiresAfter(self::VERIFIER_TTL_SECONDS);
        $this->cache->save($item);

        $state = $this->state->generateState($providerId, [
            'owner_id' => $ownerId,
            'pkce_nonce' => $nonce,
        ]);

        return $this->client->authorizationUrl($provider, $state, $this->client->codeChallenge($verifier));
    }

    /**
     * Validate the redirect, exchange the code and store the tokens.
     *
     * @throws OAuthException on a bad/expired state, a replayed callback or a
     *                        rejected exchange — never creates a connection in that case
     */
    public function completeConsent(string $providerId, string $code, string $state): Connection
    {
        $payload = $this->state->validateState($state, $providerId);
        if (null === $payload) {
            throw new OAuthException('The sign-in link is invalid or has expired. Please start again.');
        }

        $ownerId = is_numeric($payload['owner_id'] ?? null) ? (int) $payload['owner_id'] : 0;
        $nonce = is_string($payload['pkce_nonce'] ?? null) ? $payload['pkce_nonce'] : '';
        if ($ownerId <= 0 || '' === $nonce) {
            throw new OAuthException('The sign-in link is incomplete. Please start again.');
        }

        $verifier = $this->consumeVerifier($nonce, $ownerId);
        $source = $this->providers->get($providerId);
        $tokens = $this->client->exchangeCode($source->toProviderConfig(), $code, $verifier);

        $connection = $this->connections->findOneByOwnerAndType($ownerId, $providerId)
            ?? new Connection($ownerId, $providerId, $this->defaultName($providerId));

        $config = $connection->getConfig() ?? [];
        $config['provider'] = $providerId;
        $connection->setConfig($config);
        $connection->markChecked(Connection::STATUS_CONNECTED);

        $this->tokens->save($connection, $tokens);

        $this->logger->info('OAuth consent completed', [
            'connection_id' => $connection->getId(),
            'owner_id' => $ownerId,
            'provider' => $providerId,
            'scopes' => $tokens->scopes,
        ]);

        return $connection;
    }

    /**
     * Single use: a callback replayed from the browser history must not be able
     * to mint a second grant with the same verifier.
     */
    private function consumeVerifier(string $nonce, int $ownerId): string
    {
        $key = $this->verifierKey($nonce);
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            throw new OAuthException('The sign-in attempt has expired or was already used. Please start again.');
        }

        $stored = $item->get();
        $this->cache->deleteItem($key);

        if (!is_array($stored)
            || !is_string($stored['verifier'] ?? null)
            || ($stored['owner_id'] ?? null) !== $ownerId
        ) {
            throw new OAuthException('The sign-in attempt could not be verified. Please start again.');
        }

        return $stored['verifier'];
    }

    private function verifierKey(string $nonce): string
    {
        return self::VERIFIER_CACHE_PREFIX.hash('sha256', $nonce);
    }

    private function defaultName(string $providerId): string
    {
        return Connection::TYPE_M365 === $providerId ? 'Microsoft 365' : ucfirst($providerId);
    }
}
