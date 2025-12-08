<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OIDC Token Service for Keycloak Integration.
 *
 * Handles the proper OIDC flow:
 * 1. Keycloak issues Access + Refresh tokens
 * 2. On token expiry: Refresh token sent to Keycloak → new Access token
 * 3. If Keycloak rejects refresh (user logged out) → session ends
 */
class OidcTokenService
{
    // Cookie names for OIDC tokens
    public const OIDC_ACCESS_COOKIE = 'oidc_access_token';
    public const OIDC_REFRESH_COOKIE = 'oidc_refresh_token';
    public const OIDC_PROVIDER_COOKIE = 'oidc_provider';

    private ?array $discoveryCache = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private Connection $connection,
        private LoggerInterface $logger,
        private string $appEnv,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcDiscoveryUrl,
    ) {
    }

    /**
     * Store OIDC tokens from provider (Keycloak).
     */
    public function storeOidcTokens(
        Response $response,
        string $accessToken,
        string $refreshToken,
        int $expiresIn,
        string $provider = 'keycloak',
    ): Response {
        // Access token cookie (shorter lifetime based on token expiry)
        $accessExpiry = time() + min($expiresIn, 3600); // Max 1 hour
        $response->headers->setCookie($this->createCookie(
            self::OIDC_ACCESS_COOKIE,
            $accessToken,
            $accessExpiry
        ));

        // Refresh token cookie (longer lifetime)
        $response->headers->setCookie($this->createCookie(
            self::OIDC_REFRESH_COOKIE,
            $refreshToken,
            time() + 86400 * 30 // 30 days
        ));

        // Provider cookie (to know which OIDC provider to refresh against)
        $response->headers->setCookie($this->createCookie(
            self::OIDC_PROVIDER_COOKIE,
            $provider,
            time() + 86400 * 30
        ));

        return $response;
    }

    /**
     * Refresh OIDC tokens using the refresh token.
     * Returns new tokens or null if refresh failed (user logged out from Keycloak).
     */
    public function refreshOidcTokens(string $refreshToken, string $provider = 'keycloak'): ?array
    {
        try {
            $discovery = $this->getDiscoveryConfig($provider);

            $response = $this->httpClient->request('POST', $discovery['token_endpoint'], [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->oidcClientId,
                    'client_secret' => $this->oidcClientSecret,
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('OIDC token refresh failed', [
                    'status' => $response->getStatusCode(),
                    'provider' => $provider,
                ]);

                return null;
            }

            $data = $response->toArray();

            $this->logger->info('OIDC tokens refreshed successfully', [
                'provider' => $provider,
                'expires_in' => $data['expires_in'] ?? 'unknown',
            ]);

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $refreshToken, // Some providers don't rotate
                'expires_in' => $data['expires_in'] ?? 3600,
                'token_type' => $data['token_type'] ?? 'Bearer',
            ];
        } catch (\Exception $e) {
            $this->logger->error('OIDC token refresh error', [
                'error' => $e->getMessage(),
                'provider' => $provider,
            ]);

            return null;
        }
    }

    /**
     * Validate OIDC access token by calling userinfo endpoint.
     */
    public function validateOidcToken(string $accessToken, string $provider = 'keycloak'): ?array
    {
        try {
            $discovery = $this->getDiscoveryConfig($provider);

            $response = $this->httpClient->request('GET', $discovery['userinfo_endpoint'], [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            return $response->toArray();
        } catch (\Exception $e) {
            $this->logger->debug('OIDC token validation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get user from OIDC token (validates and returns user).
     */
    public function getUserFromOidcToken(string $accessToken, string $provider = 'keycloak'): ?User
    {
        $userInfo = $this->validateOidcToken($accessToken, $provider);

        if (!$userInfo || !isset($userInfo['sub'])) {
            return null;
        }

        // Efficient query: Find user by OIDC sub in JSON column
        $sub = $userInfo['sub'];
        $sql = "SELECT BID FROM BUSER WHERE JSON_UNQUOTE(JSON_EXTRACT(BUSERDETAILS, '$.oidc_sub')) = :sub LIMIT 1";

        try {
            $result = $this->connection->executeQuery($sql, ['sub' => $sub]);
            $row = $result->fetchAssociative();

            if ($row && isset($row['BID'])) {
                return $this->userRepository->find($row['BID']);
            }
        } catch (\Exception $e) {
            $this->logger->debug('OIDC sub lookup failed, falling back to email', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: Try by email
        if (isset($userInfo['email'])) {
            return $this->userRepository->findOneBy(['mail' => $userInfo['email']]);
        }

        return null;
    }

    /**
     * Clear OIDC cookies.
     */
    public function clearOidcCookies(Response $response): Response
    {
        $response->headers->setCookie($this->createCookie(self::OIDC_ACCESS_COOKIE, '', 1));
        $response->headers->setCookie($this->createCookie(self::OIDC_REFRESH_COOKIE, '', 1));
        $response->headers->setCookie($this->createCookie(self::OIDC_PROVIDER_COOKIE, '', 1));

        return $response;
    }

    /**
     * Get OIDC discovery configuration (cached).
     */
    private function getDiscoveryConfig(string $provider): array
    {
        // Return cached config if available
        if (null !== $this->discoveryCache) {
            return $this->discoveryCache;
        }

        $discoveryEndpoint = rtrim($this->oidcDiscoveryUrl, '/').'/.well-known/openid-configuration';

        try {
            $response = $this->httpClient->request('GET', $discoveryEndpoint);
            $this->discoveryCache = $response->toArray();

            $this->logger->debug('OIDC discovery config loaded', [
                'issuer' => $this->discoveryCache['issuer'] ?? 'unknown',
                'provider' => $provider,
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
     * Create secure cookie.
     */
    private function createCookie(string $name, string $value, int $expire): Cookie
    {
        $isProduction = 'prod' === $this->appEnv;

        return Cookie::create($name)
            ->withValue($value)
            ->withExpires($expire)
            ->withPath('/')
            ->withSecure($isProduction)
            ->withHttpOnly(true)
            ->withSameSite($isProduction ? Cookie::SAMESITE_STRICT : Cookie::SAMESITE_LAX);
    }
}
