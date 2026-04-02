<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\OAuthStateService;
use App\Service\OidcTokenService;
use App\Service\OidcUserService;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Keycloak OIDC Authentication Controller.
 *
 * Implements proper OIDC flow with PKCE (RFC 7636):
 * 1. User authenticates via Keycloak
 * 2. PKCE: code_challenge sent, code_verifier verified
 * 3. Keycloak issues Access + Refresh tokens
 * 4. Tokens stored in HttpOnly cookies
 * 5. On token expiry: Refresh via Keycloak
 * 6. If Keycloak rejects refresh: Session ends (user logged out from Keycloak)
 *
 * PKCE (Proof Key for Code Exchange) protects against authorization code interception attacks.
 */
#[Route('/api/v1/auth/keycloak')]
class KeycloakAuthController extends AbstractController
{
    private string $appEnv;

    /** @var array<string> */
    private array $adminRoleNames;

    /** @var array<array<string>> Each element is an array of path segments, e.g. ['realm_access', 'roles'] */
    private array $roleClaimPaths;

    public function __construct(
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private TokenService $tokenService,
        private OidcTokenService $oidcTokenService,
        private OidcUserService $oidcUserService,
        private OAuthStateService $oauthStateService,
        private LoggerInterface $logger,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcDiscoveryUrl,
        string $oidcAdminRoles,
        string $oidcRoleClaims,
        private string $oidcScopes,
        private string $appUrl,
        private string $frontendUrl,
    ) {
        $this->appEnv = $_ENV['APP_ENV'] ?? 'prod';
        $this->adminRoleNames = array_map('strtolower', array_map('trim', explode(',', $oidcAdminRoles)));
        $this->roleClaimPaths = $this->parseRoleClaims($oidcRoleClaims);
    }

    #[Route('/login', name: 'keycloak_auth_login', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/keycloak/login',
        summary: 'Initiate Keycloak OAuth login with PKCE',
        description: 'Initiates OAuth 2.0 Authorization Code Flow with PKCE (RFC 7636) for enhanced security',
        tags: ['Authentication']
    )]
    public function login(): Response
    {
        try {
            $discovery = $this->getDiscoveryConfig();

            $redirectUri = $this->appUrl.'/api/v1/auth/keycloak/callback';

            // PKCE (RFC 7636): Generate code_verifier and code_challenge
            $codeVerifier = $this->generateCodeVerifier();
            $codeChallenge = $this->generateCodeChallenge($codeVerifier);

            // Generate signed state token with PKCE verifier embedded (no session required)
            $state = $this->oauthStateService->generateState('keycloak', [
                'pkce_verifier' => $codeVerifier,
            ]);

            $params = [
                'client_id' => $this->oidcClientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => $this->oidcScopes,
                'state' => $state,
                // PKCE parameters (RFC 7636)
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256', // SHA-256
            ];

            $authUrl = $discovery['authorization_endpoint'].'?'.http_build_query($params);

            $this->logger->info('Keycloak OAuth login initiated with PKCE', [
                'redirect_uri' => $redirectUri,
                'pkce_enabled' => true,
                'scopes' => $this->oidcScopes,
            ]);

            return $this->redirect($authUrl);
        } catch (\Exception $e) {
            // Log detailed error information for debugging
            $errorDetails = [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'code' => $e->getCode(),
            ];

            // In debug mode, include full stack trace
            if ('dev' === $this->appEnv) {
                $errorDetails['trace'] = $e->getTraceAsString();
            }

            $this->logger->error('Keycloak login initiation failed', $errorDetails);

            return $this->redirect($this->frontendUrl.'/auth/callback?'.http_build_query([
                'error' => 'Failed to initiate Keycloak login',
                'provider' => 'keycloak',
            ]));
        }
    }

    #[Route('/callback', name: 'keycloak_auth_callback', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/keycloak/callback',
        summary: 'Handle Keycloak OAuth callback',
        tags: ['Authentication']
    )]
    public function callback(Request $request): Response
    {
        $code = $request->query->get('code');
        $state = $request->query->get('state');

        // Validate signed state token and extract PKCE verifier (no session required)
        $statePayload = $state ? $this->oauthStateService->validateState($state, 'keycloak') : null;

        if (!$statePayload) {
            $this->logger->error('Keycloak OAuth state validation failed', [
                'state_present' => !empty($state),
            ]);

            return $this->redirect($this->frontendUrl.'/auth/callback?'.http_build_query([
                'error' => 'Invalid state parameter',
                'provider' => 'keycloak',
            ]));
        }

        // Extract PKCE verifier from validated state
        $codeVerifier = $statePayload['pkce_verifier'] ?? null;
        if (!$codeVerifier) {
            $this->logger->error('PKCE code_verifier missing from state token');

            return $this->redirect($this->frontendUrl.'/auth/callback?'.http_build_query([
                'error' => 'PKCE verification failed',
                'provider' => 'keycloak',
            ]));
        }

        if (!$code) {
            return $this->redirect($this->frontendUrl.'/auth/callback?'.http_build_query([
                'error' => 'Authorization code not received',
                'provider' => 'keycloak',
            ]));
        }

        try {
            $discovery = $this->getDiscoveryConfig();

            // Exchange authorization code for tokens (with PKCE verification)
            $tokenResponse = $this->httpClient->request('POST', $discovery['token_endpoint'], [
                'body' => [
                    'code' => $code,
                    'client_id' => $this->oidcClientId,
                    'client_secret' => $this->oidcClientSecret,
                    'redirect_uri' => $this->appUrl.'/api/v1/auth/keycloak/callback',
                    'grant_type' => 'authorization_code',
                    // PKCE verification (RFC 7636)
                    'code_verifier' => $codeVerifier,
                ],
            ]);

            $tokenData = $tokenResponse->toArray();
            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $idToken = $tokenData['id_token'] ?? null;
            $expiresIn = $tokenData['expires_in'] ?? 300;

            if (!$accessToken) {
                throw new \Exception('Access token not received from Keycloak');
            }

            if (!$refreshToken) {
                $offlineRequested = str_contains($this->oidcScopes, 'offline_access');
                $this->logger->log(
                    $offlineRequested ? 'warning' : 'info',
                    $offlineRequested
                        ? 'No refresh token received despite requesting offline_access scope'
                        : 'No refresh token received (offline_access not requested, app token provides fallback)',
                    ['scopes_requested' => $this->oidcScopes],
                );
            }

            // Fetch user info from Keycloak
            $userInfoResponse = $this->httpClient->request('GET', $discovery['userinfo_endpoint'], [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
            ]);

            $userInfo = $userInfoResponse->toArray();

            // Merge role claims from the access token JWT.
            // The userinfo endpoint typically omits these; the JWT always has them.
            $tokenClaims = $this->decodeJwtPayload($accessToken);
            if ($tokenClaims) {
                foreach ($this->getTopLevelRoleClaimKeys() as $claim) {
                    if (isset($tokenClaims[$claim]) && !isset($userInfo[$claim])) {
                        $userInfo[$claim] = $tokenClaims[$claim];
                    }
                }
            }

            $this->logger->info('Keycloak user info retrieved', [
                'sub' => $userInfo['sub'] ?? 'unknown',
                'email' => $userInfo['email'] ?? 'unknown',
            ]);

            // Find or create user via shared OIDC user service
            $user = $this->oidcUserService->findOrCreateFromClaims($userInfo, $refreshToken);

            // Sync OIDC roles (uses controller-specific role claim config)
            $oidcRoles = [];
            foreach ($this->roleClaimPaths as $segments) {
                $value = $this->resolveClaimPath($userInfo, $segments);
                if (is_array($value)) {
                    $oidcRoles = array_values(array_unique(array_merge($oidcRoles, $value)));
                }
            }
            $this->oidcUserService->syncRoles($user, $oidcRoles, $this->adminRoleNames);

            // Create redirect response
            $callbackUrl = $this->frontendUrl.'/auth/callback?'.http_build_query([
                'success' => 'true',
                'provider' => 'keycloak',
            ]);

            $response = new RedirectResponse($callbackUrl);

            // Store OIDC tokens in HttpOnly cookies (the real tokens from Keycloak)
            $this->oidcTokenService->storeOidcTokens(
                $response,
                $accessToken,
                $refreshToken,
                $expiresIn,
                'keycloak',
                $idToken,
            );

            // Also generate our app tokens for internal use
            $appAccessToken = $this->tokenService->generateAccessToken($user);
            $appRefreshToken = $this->tokenService->generateRefreshToken($user, $request->getClientIp());
            $this->tokenService->addAuthCookies($response, $appAccessToken, $appRefreshToken);

            $this->logger->info('Keycloak OAuth successful', [
                'user_id' => $user->getId(),
                'email' => $user->getMail(),
            ]);

            return $response;
        } catch (\Exception $e) {
            // Log detailed error information for debugging
            $errorDetails = [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'code' => $e->getCode(),
            ];

            // In debug mode, include full stack trace
            if ('dev' === $this->appEnv) {
                $errorDetails['trace'] = $e->getTraceAsString();
            }

            $this->logger->error('Keycloak OAuth callback error', $errorDetails);

            return $this->redirect($this->frontendUrl.'/auth/callback?'.http_build_query([
                'error' => 'Failed to authenticate with Keycloak',
                'provider' => 'keycloak',
            ]));
        }
    }

    private function getDiscoveryConfig(): array
    {
        $discoveryEndpoint = rtrim($this->oidcDiscoveryUrl, '/').'/.well-known/openid-configuration';

        try {
            $response = $this->httpClient->request('GET', $discoveryEndpoint);
            $config = $response->toArray();

            return $config;
        } catch (\Exception $e) {
            // Log detailed error information for debugging
            $errorDetails = [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'code' => $e->getCode(),
                'discovery_endpoint' => $discoveryEndpoint,
            ];

            // In debug mode, include full stack trace
            if ('dev' === $this->appEnv) {
                $errorDetails['trace'] = $e->getTraceAsString();
            }

            $this->logger->error('Failed to load Keycloak discovery config', $errorDetails);
            throw new \Exception('Failed to load OIDC discovery configuration');
        }
    }

    /**
     * Generate PKCE code_verifier (RFC 7636).
     *
     * Creates a cryptographically random string of 43-128 characters
     * from the unreserved characters [A-Z] / [a-z] / [0-9] / "-" / "." / "_" / "~"
     */
    private function generateCodeVerifier(): string
    {
        // Generate 32 random bytes (256 bits) and encode as Base64URL
        $randomBytes = random_bytes(32);

        return $this->base64UrlEncode($randomBytes);
    }

    /**
     * Generate PKCE code_challenge from code_verifier (RFC 7636).
     *
     * Uses S256 method: BASE64URL(SHA256(code_verifier))
     */
    private function generateCodeChallenge(string $codeVerifier): string
    {
        $hash = hash('sha256', $codeVerifier, true);

        return $this->base64UrlEncode($hash);
    }

    /**
     * Base64URL encoding (RFC 4648 Section 5).
     *
     * Standard Base64 encoding with URL-safe characters:
     * - Replace '+' with '-'
     * - Replace '/' with '_'
     * - Remove padding '='
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Parse OIDC_ROLE_CLAIMS into an array of claim path segments.
     *
     * Each path is a dot-separated string (e.g. "realm_access.roles").
     * Backslash-escaped dots (\.) are treated as literal dots in key names.
     * The placeholder {client_id} is replaced with the actual OIDC client ID.
     * Empty/unset input falls back to Keycloak-compatible defaults.
     *
     * @return array<array<string>>
     */
    private function parseRoleClaims(string $oidcRoleClaims): array
    {
        $raw = array_map('trim', explode(',', $oidcRoleClaims));

        $paths = [];
        foreach ($raw as $path) {
            if ('' === $path) {
                continue;
            }
            $path = str_replace('{client_id}', $this->oidcClientId, $path);
            // Split on unescaped dots (backslash-escaped dots are literal)
            $segments = preg_split('/(?<!\\\\)\./', $path);
            $segments = array_map(static fn (string $s) => str_replace('\\.', '.', $s), $segments);
            $paths[] = $segments;
        }

        return $paths;
    }

    /**
     * Resolve a value from a nested array by following a path of segments.
     *
     * @param array<string, mixed> $data
     * @param array<string>        $segments
     */
    private function resolveClaimPath(array $data, array $segments): mixed
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Get the unique set of top-level claim keys needed for role extraction.
     *
     * Used to merge JWT claims into userInfo (the userinfo endpoint often omits them).
     *
     * @return array<string>
     */
    private function getTopLevelRoleClaimKeys(): array
    {
        $keys = [];
        foreach ($this->roleClaimPaths as $segments) {
            $keys[] = $segments[0];
        }

        return array_values(array_unique($keys));
    }

    /**
     * Decode the payload of a JWT without signature verification.
     * Safe here because the token was received directly from the OIDC token endpoint.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (3 !== count($parts)) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $payload) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
