<?php

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OIDC Token Handler.
 *
 * Validates access tokens via OIDC UserInfo endpoint and refresh tokens
 * Client Secret is kept server-side only for security
 */
class OidcTokenHandler implements AccessTokenHandlerInterface
{
    private ?array $discoveryCache = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private OidcUserProvider $userProvider,
        #[Autowire('%env(OIDC_DISCOVERY_URL)%')]
        private string $discoveryUrl,
        #[Autowire('%env(OIDC_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(OIDC_CLIENT_SECRET)%')]
        private string $clientSecret,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        try {
            // Get OIDC discovery configuration
            $discovery = $this->getDiscoveryConfig();

            // Call UserInfo endpoint to get user data
            $response = $this->httpClient->request('GET', $discovery['userinfo_endpoint'], [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new BadCredentialsException('Invalid access token');
            }

            $userData = $response->toArray();

            // Extract user identifier (prefer 'sub' claim as per OIDC spec)
            $userIdentifier = $userData['sub'] ?? $userData['email'] ?? $userData['preferred_username'] ?? null;

            if (!$userIdentifier) {
                throw new BadCredentialsException('User identifier not found in token');
            }

            $this->logger->info('OIDC user authenticated', [
                'user_id' => $userIdentifier,
                'email' => $userData['email'] ?? null,
                'username' => $userData['preferred_username'] ?? null,
            ]);

            // Return UserBadge with user data
            return new UserBadge(
                $userIdentifier,
                function (string $identifier) use ($userData) {
                    // Load or create User from OIDC data
                    return $this->userProvider->loadUserFromOidcData($userData);
                }
            );
        } catch (\Exception $e) {
            $this->logger->error('OIDC token validation failed', [
                'error' => $e->getMessage(),
            ]);
            throw new BadCredentialsException('Invalid OIDC token', 0, $e);
        }
    }

    /**
     * Get OIDC discovery configuration (cached).
     */
    private function getDiscoveryConfig(): array
    {
        if (null !== $this->discoveryCache) {
            return $this->discoveryCache;
        }

        // Symfony automatically appends /.well-known/openid-configuration
        $discoveryEndpoint = rtrim($this->discoveryUrl, '/').'/.well-known/openid-configuration';

        try {
            $response = $this->httpClient->request('GET', $discoveryEndpoint);
            $this->discoveryCache = $response->toArray();

            $this->logger->info('OIDC discovery config loaded', [
                'issuer' => $this->discoveryCache['issuer'] ?? 'unknown',
                'userinfo_endpoint' => $this->discoveryCache['userinfo_endpoint'] ?? 'unknown',
            ]);

            return $this->discoveryCache;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load OIDC discovery config', [
                'url' => $discoveryEndpoint,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Refresh an access token using a refresh token
     * This should be called by the frontend/client when the access token expires.
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $discovery = $this->getDiscoveryConfig();

        try {
            $response = $this->httpClient->request('POST', $discovery['token_endpoint'], [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret, // NEVER sent to frontend
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException('Token refresh failed');
            }

            $tokenData = $response->toArray();

            $this->logger->info('OIDC token refreshed successfully');

            return [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? $refreshToken,
                'expires_in' => $tokenData['expires_in'] ?? 3600,
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
            ];
        } catch (\Exception $e) {
            $this->logger->error('OIDC token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            throw new BadCredentialsException('Failed to refresh token', 0, $e);
        }
    }
}
