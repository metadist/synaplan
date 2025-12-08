<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
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
 * Implements proper OIDC flow:
 * 1. User authenticates via Keycloak
 * 2. Keycloak issues Access + Refresh tokens
 * 3. Tokens stored in HttpOnly cookies
 * 4. On token expiry: Refresh via Keycloak
 * 5. If Keycloak rejects refresh: Session ends (user logged out from Keycloak)
 */
#[Route('/api/v1/auth/keycloak')]
class KeycloakAuthController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private TokenService $tokenService,
        private OidcTokenService $oidcTokenService,
        private LoggerInterface $logger,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcDiscoveryUrl,
        private string $appUrl,
        private string $frontendUrl,
    ) {
    }

    #[Route('/login', name: 'keycloak_auth_login', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/keycloak/login',
        summary: 'Initiate Keycloak OAuth login',
        tags: ['Authentication']
    )]
    public function login(Request $request): Response
    {
        try {
            $discovery = $this->getDiscoveryConfig();

            $redirectUri = $this->appUrl . '/api/v1/auth/keycloak/callback';
            $state = bin2hex(random_bytes(16));

            $request->getSession()->set('keycloak_oauth_state', $state);

            $params = [
                'client_id' => $this->oidcClientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile offline_access', // offline_access for refresh token
                'state' => $state,
            ];

            $authUrl = $discovery['authorization_endpoint'] . '?' . http_build_query($params);

            $this->logger->info('Keycloak OAuth login initiated', [
                'redirect_uri' => $redirectUri,
            ]);

            return $this->redirect($authUrl);
        } catch (\Exception $e) {
            $this->logger->error('Keycloak login initiation failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->redirect($this->frontendUrl . '/auth/callback?' . http_build_query([
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
        $storedState = $request->getSession()->get('keycloak_oauth_state');

        // Validate state (CSRF protection)
        if (!$state || $state !== $storedState) {
            $this->logger->error('Keycloak OAuth state mismatch');
            return $this->redirect($this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Invalid state parameter',
                'provider' => 'keycloak',
            ]));
        }

        $request->getSession()->remove('keycloak_oauth_state');

        if (!$code) {
            return $this->redirect($this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Authorization code not received',
                'provider' => 'keycloak',
            ]));
        }

        try {
            $discovery = $this->getDiscoveryConfig();

            // Exchange authorization code for tokens
            $tokenResponse = $this->httpClient->request('POST', $discovery['token_endpoint'], [
                'body' => [
                    'code' => $code,
                    'client_id' => $this->oidcClientId,
                    'client_secret' => $this->oidcClientSecret,
                    'redirect_uri' => $this->appUrl . '/api/v1/auth/keycloak/callback',
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $tokenData = $tokenResponse->toArray();
            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $expiresIn = $tokenData['expires_in'] ?? 300;

            if (!$accessToken) {
                throw new \Exception('Access token not received from Keycloak');
            }

            // Fetch user info from Keycloak
            $userInfoResponse = $this->httpClient->request('GET', $discovery['userinfo_endpoint'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $userInfo = $userInfoResponse->toArray();

            $this->logger->info('Keycloak user info retrieved', [
                'sub' => $userInfo['sub'] ?? 'unknown',
                'email' => $userInfo['email'] ?? 'unknown',
            ]);

            // Find or create user
            $user = $this->findOrCreateUser($userInfo, $refreshToken);

            // Create redirect response
            $callbackUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
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
                'keycloak'
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
            $this->logger->error('Keycloak OAuth callback error', [
                'error' => $e->getMessage(),
            ]);

            return $this->redirect($this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Failed to authenticate with Keycloak',
                'provider' => 'keycloak',
            ]));
        }
    }

    private function getDiscoveryConfig(): array
    {
        $discoveryEndpoint = rtrim($this->oidcDiscoveryUrl, '/') . '/.well-known/openid-configuration';

        try {
            $response = $this->httpClient->request('GET', $discoveryEndpoint);
            return $response->toArray();
        } catch (\Exception $e) {
            $this->logger->error('Failed to load Keycloak discovery config', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to load OIDC discovery configuration');
        }
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
                throw new \Exception(sprintf(
                    'This email is already registered using %s. Please use the same login method.',
                    $user->getAuthProviderName()
                ));
            }

            $this->logger->info('Existing Keycloak user logging in', [
                'user_id' => $user->getId(),
            ]);
        } else {
            // Create new user
            $user = new User();
            $user->setMail($email ?? $username . '@keycloak.local');
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

        // Sync Keycloak roles if available
        if (isset($userInfo['realm_access']['roles'])) {
            $userDetails['oidc_roles'] = $userInfo['realm_access']['roles'];
        }

        $user->setUserDetails($userDetails);

        if (!$user->isEmailVerified() && $email && ($userInfo['email_verified'] ?? true)) {
            $user->setEmailVerified(true);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
