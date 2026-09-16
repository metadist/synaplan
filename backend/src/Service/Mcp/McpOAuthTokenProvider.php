<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\EncryptionService;
use App\Service\OAuth\OAuthClient;
use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthProviderConfig;
use App\Service\OAuth\OAuthReauthRequiredException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Hands {@see McpClient} a valid access token for an `oauth` server.
 *
 * Refresh is single-flight per server id (shared cache lock) so Galera web
 * nodes do not race and burn the refresh token.
 */
final readonly class McpOAuthTokenProvider
{
    private const LOCK_PREFIX = 'mcp_oauth_refresh_';
    private const LOCK_TTL_SECONDS = 15;
    private const SKEW_SECONDS = 60;

    public function __construct(
        private McpClientConfig $config,
        private OAuthClient $oauth,
        private EncryptionService $encryption,
        private McpServerConfigRepository $servers,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private string $appUrl,
    ) {
    }

    /**
     * @throws McpOAuthReauthRequiredException
     * @throws McpClientException
     */
    public function accessToken(McpServerConfig $server, bool $forceRefresh = false): string
    {
        if (!$this->config->isOAuthConnectorsEnabled()) {
            throw new McpOAuthReauthRequiredException('Connecting with a sign-in is disabled by an administrator');
        }

        $state = $server->getDecryptedOAuthState($this->encryption);
        if (!$state->hasAccessToken()) {
            throw new McpOAuthReauthRequiredException('This server is not signed in yet');
        }

        if (!$forceRefresh && !$state->isExpired(self::SKEW_SECONDS)) {
            return $state->accessToken;
        }

        if (!$state->hasRefreshToken()) {
            $this->markReauthRequired($server, $state);

            throw new McpOAuthReauthRequiredException('Please sign in again to continue using this server');
        }

        return $this->refresh($server, $state);
    }

    /**
     * @throws McpOAuthReauthRequiredException
     * @throws McpClientException
     */
    public function refresh(McpServerConfig $server, ?McpOAuthState $state = null): string
    {
        $state ??= $server->getDecryptedOAuthState($this->encryption);
        $serverId = (int) $server->getId();
        $lockKey = self::LOCK_PREFIX.$serverId;
        $lock = $this->cache->getItem($lockKey);

        if ($lock->isHit()) {
            usleep(200_000);
            $reloaded = $this->servers->findByIdAndUser($serverId, $server->getUserId());
            if (null !== $reloaded) {
                $fresh = $reloaded->getDecryptedOAuthState($this->encryption);
                if ($fresh->hasAccessToken() && !$fresh->isExpired(self::SKEW_SECONDS)) {
                    return $fresh->accessToken;
                }
            }
        }

        $lock->set(1);
        $lock->expiresAfter(self::LOCK_TTL_SECONDS);
        $this->cache->save($lock);

        try {
            $provider = new OAuthProviderConfig(
                provider: McpOAuthConsentService::PROVIDER,
                authorizeUrl: $state->authorizationEndpoint,
                tokenUrl: $state->tokenEndpoint,
                clientId: $state->clientId,
                clientSecret: '',
                redirectUri: rtrim($this->appUrl, '/').'/api/v1/mcp-servers/oauth/callback',
                scopes: $state->scopes,
                includeScopeInTokenRequests: false,
            );

            $tokens = $this->oauth->refresh($provider, $state->refreshToken);
            $updated = $state->withTokens($tokens->accessToken, $tokens->refreshToken, $tokens->expiresAt);
            $server->setDecryptedOAuthState($updated, $this->encryption);
            $this->servers->save($server);

            return $tokens->accessToken;
        } catch (OAuthReauthRequiredException $e) {
            $this->markReauthRequired($server, $state);

            throw new McpOAuthReauthRequiredException($e->getMessage(), 0, $e);
        } catch (OAuthException $e) {
            $this->logger->warning('MCP OAuth refresh failed', [
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);

            throw new McpClientException('Could not renew the sign-in for this server: '.$e->getMessage(), 0, $e);
        } finally {
            $this->cache->deleteItem($lockKey);
        }
    }

    public function markReauthRequired(McpServerConfig $server, ?McpOAuthState $state = null): void
    {
        $state ??= $server->getDecryptedOAuthState($this->encryption);
        $server->setDecryptedOAuthState($state->withStatus(McpOAuthState::STATUS_REAUTH_REQUIRED), $this->encryption);
        $this->servers->save($server);
    }
}
