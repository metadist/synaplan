<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\EncryptionService;
use App\Service\OAuth\OAuthClient;
use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthProviderConfig;
use App\Service\OAuthStateService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Interactive OAuth for an outbound MCP server (Notion, Higgsfield, …).
 *
 * Mirrors {@see \App\Service\OAuth\OAuthConsentService}: the PKCE verifier
 * stays on the server, and the signed state carries `owner_id` + `server_id`
 * so the callback does not need the SameSite=Strict auth cookie.
 */
final readonly class McpOAuthConsentService
{
    public const PROVIDER = 'mcp';

    private const VERIFIER_CACHE_PREFIX = 'mcp_oauth_pkce_';
    private const VERIFIER_TTL_SECONDS = 600;

    public function __construct(
        private McpClientConfig $config,
        private McpOAuthDiscovery $discovery,
        private McpOAuthRegistration $registration,
        private OAuthClient $oauth,
        private OAuthStateService $state,
        private McpServerConfigRepository $servers,
        private EncryptionService $encryption,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private string $appUrl,
    ) {
    }

    public function redirectUri(): string
    {
        return rtrim($this->appUrl, '/').'/api/v1/mcp-servers/oauth/callback';
    }

    /**
     * Discover + register (idempotent) and return the consent URL.
     *
     * @throws McpOAuthException
     */
    public function start(McpServerConfig $server, int $ownerId): string
    {
        if (!$this->config->isOAuthConnectorsEnabled()) {
            throw new McpOAuthException('Connecting with a sign-in is disabled by an administrator');
        }
        if ($server->getUserId() !== $ownerId) {
            throw new McpOAuthException('Server not found');
        }

        $discovered = $this->discovery->discover($server->getUrl());
        $existing = $server->getDecryptedOAuthState($this->encryption);

        $clientId = $existing->clientId;
        $supportsRefresh = $existing->supportsRefresh;
        $needsRegister = '' === $clientId || $existing->resource !== $discovered->resource;

        if ($needsRegister) {
            $registered = $this->registration->register(
                $discovered->registrationEndpoint,
                $this->redirectUri(),
                rtrim($this->appUrl, '/'),
                $discovered->scopes,
            );
            $clientId = $registered['client_id'];
            $supportsRefresh = $discovered->supportsRefreshGrant && $registered['supports_refresh'];
        }

        $state = new McpOAuthState(
            resource: $discovered->resource,
            authorizationEndpoint: $discovered->authorizationEndpoint,
            tokenEndpoint: $discovered->tokenEndpoint,
            registrationEndpoint: $discovered->registrationEndpoint,
            clientId: $clientId,
            scopes: $discovered->scopes,
            accessToken: $existing->accessToken,
            refreshToken: $existing->refreshToken,
            expiresAt: $existing->expiresAt,
            status: $existing->status,
            supportsRefresh: $supportsRefresh,
        );
        $server->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setDecryptedOAuthState($state, $this->encryption);
        $this->servers->save($server);

        $verifier = $this->oauth->generateCodeVerifier();
        $nonce = bin2hex(random_bytes(16));

        $item = $this->cache->getItem($this->verifierKey($nonce));
        $item->set(['verifier' => $verifier, 'owner_id' => $ownerId, 'server_id' => $server->getId()]);
        $item->expiresAfter(self::VERIFIER_TTL_SECONDS);
        $this->cache->save($item);

        $signed = $this->state->generateState(self::PROVIDER, [
            'owner_id' => $ownerId,
            'server_id' => $server->getId(),
            'pkce_nonce' => $nonce,
        ]);

        $provider = $this->toProviderConfig($state);

        return $this->oauth->authorizationUrl($provider, $signed, $this->oauth->codeChallenge($verifier));
    }

    /**
     * @throws McpOAuthException
     * @throws OAuthException
     */
    public function complete(string $code, string $stateToken): McpServerConfig
    {
        if (!$this->config->isOAuthConnectorsEnabled()) {
            throw new McpOAuthException('Connecting with a sign-in is disabled by an administrator');
        }

        $payload = $this->state->validateState($stateToken, self::PROVIDER);
        if (null === $payload) {
            throw new McpOAuthException('The sign-in link is invalid or has expired. Please start again.');
        }

        $ownerId = is_numeric($payload['owner_id'] ?? null) ? (int) $payload['owner_id'] : 0;
        $serverId = is_numeric($payload['server_id'] ?? null) ? (int) $payload['server_id'] : 0;
        $nonce = is_string($payload['pkce_nonce'] ?? null) ? $payload['pkce_nonce'] : '';
        if ($ownerId <= 0 || $serverId <= 0 || '' === $nonce) {
            throw new McpOAuthException('The sign-in link is incomplete. Please start again.');
        }

        $verifier = $this->consumeVerifier($nonce, $ownerId, $serverId);
        $server = $this->servers->findByIdAndUser($serverId, $ownerId);
        if (null === $server) {
            throw new McpOAuthException('Server not found');
        }

        $oauthState = $server->getDecryptedOAuthState($this->encryption);
        $tokens = $this->oauth->exchangeCode($this->toProviderConfig($oauthState), $code, $verifier);

        $status = McpOAuthState::STATUS_CONNECTED;
        $updated = $oauthState->withTokens(
            $tokens->accessToken,
            $tokens->refreshToken,
            $tokens->expiresAt,
            $status,
        );
        if ('' === $tokens->refreshToken && !$updated->supportsRefresh) {
            // Higgsfield-style: a working access token now, reconnect later.
            $updated = $updated->withStatus(McpOAuthState::STATUS_CONNECTED);
        }

        $server->setDecryptedOAuthState($updated, $this->encryption);
        $this->servers->save($server);

        $this->logger->info('MCP OAuth consent completed', [
            'server_id' => $server->getId(),
            'owner_id' => $ownerId,
            'has_refresh' => '' !== $tokens->refreshToken,
        ]);

        return $server;
    }

    public function disconnect(McpServerConfig $server): void
    {
        $state = $server->getDecryptedOAuthState($this->encryption)->withoutTokens();
        $server->setDecryptedOAuthState($state, $this->encryption);
        $this->servers->save($server);
    }

    private function toProviderConfig(McpOAuthState $state): OAuthProviderConfig
    {
        return new OAuthProviderConfig(
            provider: self::PROVIDER,
            authorizeUrl: $state->authorizationEndpoint,
            tokenUrl: $state->tokenEndpoint,
            clientId: $state->clientId,
            clientSecret: '',
            redirectUri: $this->redirectUri(),
            scopes: $state->scopes,
            extraAuthorizeParams: ['prompt' => 'consent'],
            includeScopeInTokenRequests: false,
        );
    }

    private function consumeVerifier(string $nonce, int $ownerId, int $serverId): string
    {
        $key = $this->verifierKey($nonce);
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            throw new McpOAuthException('The sign-in attempt has expired or was already used. Please start again.');
        }

        $stored = $item->get();
        $this->cache->deleteItem($key);

        if (!is_array($stored)
            || !is_string($stored['verifier'] ?? null)
            || ($stored['owner_id'] ?? null) !== $ownerId
            || ($stored['server_id'] ?? null) !== $serverId
        ) {
            throw new McpOAuthException('The sign-in attempt could not be verified. Please start again.');
        }

        return $stored['verifier'];
    }

    private function verifierKey(string $nonce): string
    {
        return self::VERIFIER_CACHE_PREFIX.hash('sha256', $nonce);
    }
}
