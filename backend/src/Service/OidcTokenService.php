<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\AuthCookieFactory;
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
final class OidcTokenService
{
    // Cookie names for OIDC tokens
    public const OIDC_ACCESS_COOKIE = 'oidc_access_token';
    public const OIDC_REFRESH_COOKIE = 'oidc_refresh_token';
    public const OIDC_ID_TOKEN_COOKIE = 'oidc_id_token';
    public const OIDC_PROVIDER_COOKIE = 'oidc_provider';

    private ?array $discoveryCache = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private Connection $connection,
        private LoggerInterface $logger,
        private JwtValidator $jwtValidator,
        private AuthCookieFactory $authCookieFactory,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcDiscoveryUrl,
        private string $oidcBearerAudience = '',
    ) {
    }

    /**
     * Resolve the audience to enforce on Keycloak-issued tokens.
     * Explicit OIDC_BEARER_AUDIENCE override wins, otherwise fall back
     * to OIDC_CLIENT_ID. Returns null when neither is configured — the
     * bearer path checks for null explicitly and fails closed; the
     * cookie path tolerates it (matches the prior behavior before this
     * method was extracted). Single source of truth for both
     * validate*Token methods below.
     */
    private function resolveExpectedAudience(): ?string
    {
        if ('' !== $this->oidcBearerAudience) {
            return $this->oidcBearerAudience;
        }
        if ('' !== $this->oidcClientId) {
            return $this->oidcClientId;
        }

        return null;
    }

    /**
     * Store OIDC tokens from provider (Keycloak).
     */
    public function storeOidcTokens(
        Response $response,
        string $accessToken,
        ?string $refreshToken,
        int $expiresIn,
        string $provider = 'keycloak',
        ?string $idToken = null,
    ): Response {
        // Access token cookie (shorter lifetime based on token expiry)
        $accessExpiry = time() + min($expiresIn, 3600); // Max 1 hour
        $response->headers->setCookie($this->createCookie(
            self::OIDC_ACCESS_COOKIE,
            $accessToken,
            $accessExpiry
        ));

        // Refresh token cookie (longer lifetime) — only set when provider returned one
        if ($refreshToken) {
            $response->headers->setCookie($this->createCookie(
                self::OIDC_REFRESH_COOKIE,
                $refreshToken,
                time() + 86400 * 30 // 30 days
            ));
        } else {
            // Clear any stale refresh cookie from a previous session (e.g. scopes changed at deploy time)
            $response->headers->setCookie($this->createCookie(self::OIDC_REFRESH_COOKIE, '', 1));
        }

        // ID token cookie (needed for RP-Initiated Logout id_token_hint)
        if ($idToken) {
            $response->headers->setCookie($this->createCookie(
                self::OIDC_ID_TOKEN_COOKIE,
                $idToken,
                time() + 86400 * 30
            ));
        }

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
                // Keycloak returns a fresh id_token on the refresh_token grant.
                // Surfacing it lets callers re-store the id_token cookie so the
                // RP-Initiated Logout id_token_hint stays current instead of the
                // stale login-time token living for the full cookie lifetime (#472).
                // Null when the provider omits it — callers must not clobber the
                // existing cookie in that case.
                'id_token' => $data['id_token'] ?? null,
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
     * Validate OIDC access token using JWT signature verification.
     *
     * This replaces the old UserInfo endpoint call with proper JWT validation:
     * - Fetches JWKS (JSON Web Key Set) from provider
     * - Verifies JWT signature (RS256/ES256)
     * - Validates claims (exp, iss, aud)
     *
     * Performance improvement: ~50-200ms → <5ms (no HTTP call!)
     * Security improvement: Cryptographic signature verification
     */
    public function validateOidcToken(string $accessToken, string $provider = 'keycloak'): ?array
    {
        try {
            $discovery = $this->getDiscoveryConfig($provider);

            // Validate JWT signature + claims, including audience.
            // Symmetric with validateBearerToken(): both paths use the
            // OIDC_BEARER_AUDIENCE → OIDC_CLIENT_ID fallback. The Keycloak
            // client must have a hardcoded audience mapper so its tokens
            // carry aud=<client_id> (otherwise Keycloak only emits
            // aud=account and validation fails).
            $claims = $this->jwtValidator->validateToken(
                token: $accessToken,
                jwksUri: $discovery['jwks_uri'],
                expectedIssuer: $discovery['issuer'],
                expectedAudience: $this->resolveExpectedAudience(),
            );

            if (!$claims) {
                $this->logger->debug('JWT validation failed', ['provider' => $provider]);

                return null;
            }

            // Return claims in same format as before (for compatibility)
            return [
                'sub' => $claims['sub'] ?? null,
                'email' => $claims['email'] ?? null,
                'preferred_username' => $claims['preferred_username'] ?? null,
                'given_name' => $claims['given_name'] ?? null,
                'family_name' => $claims['family_name'] ?? null,
                'name' => $claims['name'] ?? null,
            ];
        } catch (\Exception $e) {
            $this->logger->error('OIDC token validation error', [
                'error' => $e->getMessage(),
                'provider' => $provider,
            ]);

            return null;
        }
    }

    /**
     * Validate OIDC bearer token with full claims and audience check.
     *
     * Unlike validateOidcToken(), this returns ALL JWT claims (including role claims
     * like realm_access, resource_access, groups) and enforces an audience check.
     * Used by OidcBearerAuthenticator for externally supplied tokens (token exchange).
     *
     * @return array<string, mixed>|null Full JWT claims if valid, null otherwise
     */
    public function validateBearerToken(string $accessToken, string $provider = 'keycloak'): ?array
    {
        $expectedAudience = $this->resolveExpectedAudience();
        if (null === $expectedAudience) {
            $this->logger->error('OIDC bearer auth: no audience configured (set OIDC_CLIENT_ID or OIDC_BEARER_AUDIENCE)');

            return null;
        }

        try {
            $discovery = $this->getDiscoveryConfig($provider);

            $claims = $this->jwtValidator->validateToken(
                token: $accessToken,
                jwksUri: $discovery['jwks_uri'],
                expectedIssuer: $discovery['issuer'],
                expectedAudience: $expectedAudience,
            );

            if (!$claims) {
                $this->logger->debug('Bearer token JWT validation failed', ['provider' => $provider]);

                return null;
            }

            return $claims;
        } catch (\Exception $e) {
            $this->logger->error('Bearer token validation error', [
                'error' => $e->getMessage(),
                'provider' => $provider,
            ]);

            return null;
        }
    }

    /**
     * Resolve the claims a fresh login may trust, picking the mechanism by
     * token type so login and refresh agree on what a valid token is.
     *
     * A JWT access token is validated locally — signature via JWKS plus iss,
     * exp and aud — which is exactly the check the refresh path applies. Doing
     * it here means a misconfigured audience is rejected by the very first
     * login, with the reason in the log, instead of passing login and then
     * killing every session on the first refresh five minutes later (#1520).
     *
     * An opaque access token carries nothing we can inspect, so the IdP has to
     * resolve it: those fall back to the userinfo endpoint.
     *
     * @return array<string, mixed>|null Claims to provision the user from, null when the token is not acceptable
     */
    public function resolveLoginClaims(string $accessToken, string $provider = 'keycloak'): ?array
    {
        if (!self::looksLikeJwt($accessToken)) {
            $this->logger->debug('OIDC login: opaque access token, resolving via userinfo', [
                'provider' => $provider,
            ]);

            return $this->fetchUserInfo($accessToken, $provider);
        }

        $claims = $this->validateBearerToken($accessToken, $provider);

        if (null === $claims) {
            $this->logger->error('OIDC login rejected: the access token failed local JWT validation', [
                'provider' => $provider,
                'hint' => 'Preceding JWT log entries name the failing check (signature, iss, exp or aud).',
            ]);

            return null;
        }

        // Which profile claims reach the access token depends on the client's
        // protocol mappers, and provisioning needs at least one identifier
        // besides sub. Top those up from userinfo rather than creating a user
        // with a synthetic address. The validated claims stay authoritative.
        if (!isset($claims['email']) && !isset($claims['preferred_username'])) {
            $userInfo = $this->fetchUserInfo($accessToken, $provider) ?? [];
            $claims = array_merge($userInfo, $claims);
        }

        return $claims;
    }

    /**
     * A JWT is three base64url segments; anything else is an opaque token that
     * only the issuer can resolve. Structural check only — the signature and
     * claims are verified by the caller.
     */
    private static function looksLikeJwt(string $token): bool
    {
        $parts = explode('.', $token);

        if (3 !== count($parts)) {
            return false;
        }

        foreach ($parts as $part) {
            if ('' === $part || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ask the IdP who a token belongs to. Required for opaque tokens and used
     * to complete missing profile claims for JWTs.
     *
     * @return array<string, mixed>|null
     */
    private function fetchUserInfo(string $accessToken, string $provider): ?array
    {
        try {
            $discovery = $this->getDiscoveryConfig($provider);

            $endpoint = $discovery['userinfo_endpoint'] ?? null;
            if (!is_string($endpoint) || '' === $endpoint) {
                $this->logger->error('OIDC provider advertises no userinfo_endpoint', [
                    'provider' => $provider,
                ]);

                return null;
            }

            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => ['Authorization' => 'Bearer '.$accessToken],
            ]);

            $claims = $response->toArray();

            return $claims ?: null;
        } catch (\Exception $e) {
            $this->logger->error('OIDC userinfo request failed', [
                'error' => $e->getMessage(),
                'provider' => $provider,
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

        if (!$userInfo) {
            $this->logger->warning('OIDC token validation returned no user info', [
                'provider' => $provider,
            ]);

            return null;
        }

        // Try to find user by OIDC sub (if present in token)
        $sub = $userInfo['sub'] ?? null;
        if ($sub) {
            $sql = "SELECT BID FROM BUSER WHERE JSON_UNQUOTE(JSON_EXTRACT(BUSERDETAILS, '$.oidc_sub')) = :sub LIMIT 1";

            try {
                $stmt = $this->connection->prepare($sql);
                $stmt->bindValue('sub', $sub);
                $result = $stmt->executeQuery();
                $row = $result->fetchAssociative();

                if ($row && isset($row['BID'])) {
                    return $this->userRepository->find($row['BID']);
                }
            } catch (\Exception $e) {
                $this->logger->debug('OIDC sub lookup failed, falling back to email', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: Try by email (Keycloak access tokens may not have 'sub' but have 'email')
        if (isset($userInfo['email'])) {
            return $this->userRepository->findOneBy(['mail' => $userInfo['email']]);
        }

        // Fallback: Try by preferred_username
        if (isset($userInfo['preferred_username'])) {
            return $this->userRepository->findOneBy(['mail' => $userInfo['preferred_username']]);
        }

        $this->logger->warning('OIDC token has no sub, email, or username to identify user', [
            'provider' => $provider,
        ]);

        return null;
    }

    /**
     * Revoke OIDC tokens at the provider (Keycloak).
     *
     * Sends revocation requests for both access and refresh tokens.
     * This ensures tokens are immediately invalidated at the OIDC provider.
     *
     * @return bool True if revocation succeeded or is not supported, false on error
     */
    public function revokeOidcTokens(string $accessToken, ?string $refreshToken, string $provider = 'keycloak'): bool
    {
        try {
            $discovery = $this->getDiscoveryConfig($provider);

            // Check if provider supports token revocation
            if (!isset($discovery['revocation_endpoint'])) {
                $this->logger->debug('OIDC provider does not support token revocation', [
                    'provider' => $provider,
                ]);

                return true; // Not an error, just unsupported
            }

            $revocationEndpoint = $discovery['revocation_endpoint'];
            $revocationSuccess = true;

            // Revoke refresh token (more important - can create new access tokens)
            if ($refreshToken) {
                try {
                    $this->httpClient->request('POST', $revocationEndpoint, [
                        'body' => [
                            'token' => $refreshToken,
                            'token_type_hint' => 'refresh_token',
                            'client_id' => $this->oidcClientId,
                            'client_secret' => $this->oidcClientSecret,
                        ],
                    ]);

                    $this->logger->info('OIDC refresh token revoked', ['provider' => $provider]);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to revoke OIDC refresh token', [
                        'error' => $e->getMessage(),
                        'provider' => $provider,
                    ]);
                    $revocationSuccess = false;
                }
            }

            // Revoke access token
            try {
                $this->httpClient->request('POST', $revocationEndpoint, [
                    'body' => [
                        'token' => $accessToken,
                        'token_type_hint' => 'access_token',
                        'client_id' => $this->oidcClientId,
                        'client_secret' => $this->oidcClientSecret,
                    ],
                ]);

                $this->logger->info('OIDC access token revoked', ['provider' => $provider]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to revoke OIDC access token', [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                ]);
                $revocationSuccess = false;
            }

            return $revocationSuccess;
        } catch (\Exception $e) {
            $this->logger->error('OIDC token revocation failed', [
                'error' => $e->getMessage(),
                'provider' => $provider,
            ]);

            return false;
        }
    }

    /**
     * Get end session (logout) URL for OIDC provider.
     *
     * Returns the URL where the user should be redirected to logout from the OIDC provider.
     * This implements the OIDC RP-Initiated Logout specification.
     *
     * @param string      $postLogoutRedirectUri URL to redirect to after logout
     * @param string      $provider              OIDC provider name
     * @param string|null $idTokenHint           ID token for automatic redirect (skips confirmation page)
     *
     * @return string|null Logout URL or null if not supported
     */
    public function getEndSessionUrl(string $postLogoutRedirectUri, string $provider = 'keycloak', ?string $idTokenHint = null): ?string
    {
        try {
            $discovery = $this->getDiscoveryConfig($provider);

            if (!isset($discovery['end_session_endpoint'])) {
                $this->logger->debug('OIDC provider does not support end_session_endpoint', [
                    'provider' => $provider,
                ]);

                return null;
            }

            $params = [
                'post_logout_redirect_uri' => $postLogoutRedirectUri,
                'client_id' => $this->oidcClientId,
            ];

            if ($idTokenHint) {
                $params['id_token_hint'] = $idTokenHint;
            }

            return $discovery['end_session_endpoint'].'?'.http_build_query($params);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get end session URL', [
                'error' => $e->getMessage(),
                'provider' => $provider,
            ]);

            return null;
        }
    }

    /**
     * Clear OIDC cookies.
     */
    public function clearOidcCookies(Response $response): Response
    {
        $response->headers->setCookie($this->createCookie(self::OIDC_ACCESS_COOKIE, '', 1));
        $response->headers->setCookie($this->createCookie(self::OIDC_REFRESH_COOKIE, '', 1));
        $response->headers->setCookie($this->createCookie(self::OIDC_ID_TOKEN_COOKIE, '', 1));
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
        return $this->authCookieFactory->create($name, $value, $expire);
    }
}
