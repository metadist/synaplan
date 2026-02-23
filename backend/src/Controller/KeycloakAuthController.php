<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\OAuthStateService;
use App\Service\OidcTokenService;
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

    public function __construct(
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private TokenService $tokenService,
        private OidcTokenService $oidcTokenService,
        private OAuthStateService $oauthStateService,
        private LoggerInterface $logger,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcDiscoveryUrl,
        string $oidcAdminRoles,
        private string $appUrl,
        private string $frontendUrl,
    ) {
        $this->appEnv = $_ENV['APP_ENV'] ?? 'prod';
        $this->adminRoleNames = !empty($oidcAdminRoles)
            ? array_map('strtolower', array_map('trim', explode(',', $oidcAdminRoles)))
            : ['admin', 'realm-admin', 'synaplan-admin', 'administrator'];
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
                'scope' => 'openid email profile offline_access', // offline_access for refresh token
                'state' => $state,
                // PKCE parameters (RFC 7636)
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256', // SHA-256
            ];

            $authUrl = $discovery['authorization_endpoint'].'?'.http_build_query($params);

            $this->logger->info('Keycloak OAuth login initiated with PKCE', [
                'redirect_uri' => $redirectUri,
                'pkce_enabled' => true,
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

            // Fetch user info from Keycloak
            $userInfoResponse = $this->httpClient->request('GET', $discovery['userinfo_endpoint'], [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
            ]);

            $userInfo = $userInfoResponse->toArray();

            // Merge role claims from the access token JWT (realm_access, resource_access)
            // The userinfo endpoint typically omits these; the JWT always has them.
            $tokenClaims = $this->decodeJwtPayload($accessToken);
            if ($tokenClaims) {
                foreach (['realm_access', 'resource_access', 'groups'] as $claim) {
                    if (isset($tokenClaims[$claim]) && !isset($userInfo[$claim])) {
                        $userInfo[$claim] = $tokenClaims[$claim];
                    }
                }
            }

            $this->logger->info('Keycloak user info retrieved', [
                'sub' => $userInfo['sub'] ?? 'unknown',
                'email' => $userInfo['email'] ?? 'unknown',
            ]);

            // Find or create user
            $user = $this->findOrCreateUser($userInfo, $refreshToken);

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

    private function findOrCreateUser(array $userInfo, ?string $refreshToken): User
    {
        $sub = $userInfo['sub'] ?? null;
        $email = $userInfo['email'] ?? null;
        $username = $userInfo['preferred_username'] ?? null;

        if (!$sub) {
            throw new \Exception('User subject (sub) not provided by Keycloak');
        }

        // Try to find existing user by email
        $user = null;
        if ($email) {
            $user = $this->userRepository->findOneBy(['mail' => $email]);
        }

        if (!$user) {
            // Try to find by OIDC sub
            $users = $this->userRepository->findAll();
            foreach ($users as $existingUser) {
                $details = $existingUser->getUserDetails() ?? [];
                if (isset($details['oidc_sub']) && $details['oidc_sub'] === $sub) {
                    $user = $existingUser;
                    break;
                }
            }
        }

        if ($user) {
            if ('keycloak' !== $user->getProviderId()) {
                throw new \Exception(sprintf('This email is already registered using %s. Please use the same login method.', $user->getAuthProviderName()));
            }

            $this->logger->info('Existing Keycloak user logging in', [
                'user_id' => $user->getId(),
            ]);
        } else {
            // Create new user
            $user = new User();
            $user->setMail($email ?? $username.'@keycloak.local');
            $user->setType('WEB');
            $user->setProviderId('keycloak');
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true);
            $user->setCreated(date('Y-m-d H:i:s'));
            $user->setUserDetails([]);
            $user->setPaymentDetails([]);

            $this->logger->info('Creating new user from Keycloak OAuth', [
                'email' => $email,
                'sub' => $sub,
            ]);
        }

        // Update user details
        $userDetails = $user->getUserDetails() ?? [];
        $userDetails['oidc_sub'] = $sub;
        $userDetails['oidc_email'] = $email;
        $userDetails['oidc_username'] = $username;
        $userDetails['oidc_refresh_token'] = $refreshToken; // Also store in DB as backup
        $userDetails['oidc_last_login'] = (new \DateTime())->format('Y-m-d H:i:s');

        if (isset($userInfo['given_name'])) {
            $userDetails['first_name'] = $userInfo['given_name'];
        }
        if (isset($userInfo['family_name'])) {
            $userDetails['last_name'] = $userInfo['family_name'];
        }
        if (isset($userInfo['name'])) {
            $userDetails['full_name'] = $userInfo['name'];
        }

        // Sync Keycloak roles if available (realm_access + resource_access for this client)
        $oidcRoles = [];
        if (isset($userInfo['realm_access']['roles']) && is_array($userInfo['realm_access']['roles'])) {
            $oidcRoles = $userInfo['realm_access']['roles'];
        }
        $clientId = $this->oidcClientId;
        if (isset($userInfo['resource_access'][$clientId]['roles']) && is_array($userInfo['resource_access'][$clientId]['roles'])) {
            $oidcRoles = array_values(array_unique(array_merge($oidcRoles, $userInfo['resource_access'][$clientId]['roles'])));
        }
        // Also check the 'groups' claim (standard OIDC groups scope)
        if (isset($userInfo['groups']) && is_array($userInfo['groups'])) {
            $oidcRoles = array_values(array_unique(array_merge($oidcRoles, $userInfo['groups'])));
        }
        if (!empty($oidcRoles)) {
            $userDetails['oidc_roles'] = $oidcRoles;

            $hasAdmin = !empty(array_intersect(array_map('strtolower', $oidcRoles), $this->adminRoleNames));
            if ($hasAdmin && 'ADMIN' !== $user->getUserLevel()) {
                $user->setUserLevel('ADMIN');
                $this->logger->info('User promoted to ADMIN via OIDC role', [
                    'user_id' => $user->getId(),
                    'oidc_roles' => $oidcRoles,
                ]);
            } elseif (!$hasAdmin && 'ADMIN' === $user->getUserLevel()) {
                $user->setUserLevel('FREE');
                $this->logger->info('User demoted from ADMIN — OIDC roles no longer include admin', [
                    'user_id' => $user->getId(),
                    'oidc_roles' => $oidcRoles,
                ]);
            }
        }

        $user->setUserDetails($userDetails);

        if (!$user->isEmailVerified() && $email && ($userInfo['email_verified'] ?? true)) {
            $user->setEmailVerified(true);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
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
