<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;

#[Route('/api/v1/auth/keycloak')]
class KeycloakAuthController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private JWTTokenManagerInterface $jwtManager,
        private LoggerInterface $logger,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcDiscoveryUrl,
        private string $appUrl,
        private string $frontendUrl
    ) {}

    #[Route('/login', name: 'keycloak_auth_login', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/keycloak/login',
        summary: 'Initiate Keycloak OAuth login',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 302,
        description: 'Redirect to Keycloak login page'
    )]
    public function login(Request $request): Response
    {
        try {
            // Get OIDC discovery configuration
            $discovery = $this->getDiscoveryConfig();
            
            $redirectUri = $this->appUrl . '/api/v1/auth/keycloak/callback';
            $state = bin2hex(random_bytes(16));
            
            // Store state in session for CSRF protection
            $request->getSession()->set('keycloak_oauth_state', $state);

            $params = [
                'client_id' => $this->oidcClientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state
            ];

            $authUrl = $discovery['authorization_endpoint'] . '?' . http_build_query($params);

            $this->logger->info('Keycloak OAuth login initiated', [
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'auth_url' => $authUrl
            ]);

            return $this->redirect($authUrl);
            
        } catch (\Exception $e) {
            $this->logger->error('Keycloak login initiation failed', [
                'error' => $e->getMessage()
            ]);
            
            $errorUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Failed to initiate Keycloak login',
                'provider' => 'keycloak'
            ]);
            
            return $this->redirect($errorUrl);
        }
    }

    #[Route('/callback', name: 'keycloak_auth_callback', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/keycloak/callback',
        summary: 'Handle Keycloak OAuth callback',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 302,
        description: 'Redirects to frontend with JWT token or error'
    )]
    public function callback(Request $request): Response
    {
        $code = $request->query->get('code');
        $state = $request->query->get('state');
        $storedState = $request->getSession()->get('keycloak_oauth_state');

        // Validate state (CSRF protection)
        if (!$state || $state !== $storedState) {
            $this->logger->error('Keycloak OAuth state mismatch', [
                'received_state' => $state,
                'stored_state' => $storedState
            ]);
            
            $errorUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Invalid state parameter',
                'provider' => 'keycloak'
            ]);
            
            return $this->redirect($errorUrl);
        }

        // Clear stored state
        $request->getSession()->remove('keycloak_oauth_state');

        if (!$code) {
            $errorUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Authorization code not received',
                'provider' => 'keycloak'
            ]);
            
            return $this->redirect($errorUrl);
        }

        try {
            // Get OIDC discovery configuration
            $discovery = $this->getDiscoveryConfig();
            
            // Exchange authorization code for access token
            $tokenResponse = $this->httpClient->request('POST', $discovery['token_endpoint'], [
                'body' => [
                    'code' => $code,
                    'client_id' => $this->oidcClientId,
                    'client_secret' => $this->oidcClientSecret,
                    'redirect_uri' => $this->appUrl . '/api/v1/auth/keycloak/callback',
                    'grant_type' => 'authorization_code'
                ]
            ]);

            $tokenData = $tokenResponse->toArray();
            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;

            if (!$accessToken) {
                throw new \Exception('Access token not received from Keycloak');
            }

            // Fetch user info from Keycloak
            $userInfoResponse = $this->httpClient->request('GET', $discovery['userinfo_endpoint'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);

            $userInfo = $userInfoResponse->toArray();

            $this->logger->info('Keycloak user info retrieved', [
                'sub' => $userInfo['sub'] ?? 'unknown',
                'email' => $userInfo['email'] ?? 'unknown',
                'preferred_username' => $userInfo['preferred_username'] ?? 'unknown'
            ]);

            // Find or create user
            $user = $this->findOrCreateUser($userInfo, $refreshToken);

            // Generate JWT token for our app
            $jwtToken = $this->jwtManager->create($user);

            // Redirect to frontend with token
            $callbackUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'token' => $jwtToken,
                'provider' => 'keycloak'
            ]);

            $this->logger->info('Keycloak OAuth successful, redirecting to frontend', [
                'user_id' => $user->getId(),
                'email' => $user->getMail()
            ]);

            return $this->redirect($callbackUrl);

        } catch (\Exception $e) {
            $this->logger->error('Keycloak OAuth callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Redirect to frontend with error
            $errorUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Failed to authenticate with Keycloak',
                'provider' => 'keycloak'
            ]);

            return $this->redirect($errorUrl);
        }
    }

    private function getDiscoveryConfig(): array
    {
        $discoveryEndpoint = rtrim($this->oidcDiscoveryUrl, '/') . '/.well-known/openid-configuration';

        try {
            $response = $this->httpClient->request('GET', $discoveryEndpoint);
            $config = $response->toArray();

            $this->logger->debug('Keycloak discovery config loaded', [
                'issuer' => $config['issuer'] ?? 'unknown',
                'authorization_endpoint' => $config['authorization_endpoint'] ?? 'unknown',
                'token_endpoint' => $config['token_endpoint'] ?? 'unknown'
            ]);

            return $config;

        } catch (\Exception $e) {
            $this->logger->error('Failed to load Keycloak discovery config', [
                'error' => $e->getMessage(),
                'endpoint' => $discoveryEndpoint
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
            // Try to find by OIDC sub in userDetails
            $users = $this->userRepository->findAll();
            foreach ($users as $existingUser) {
                $details = $existingUser->getUserDetails() ?? [];
                if (isset($details['oidc_sub']) && $details['oidc_sub'] === $sub) {
                    $user = $existingUser;
                    break;
                }
            }
        }

        if (!$user) {
            // Create new user
            $user = new User();
            $user->setMail($email ?? $username . '@keycloak.local');
            $user->setType('OIDC');
            $user->setProviderId('keycloak');
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true); // OIDC users are pre-verified
            $user->setCreated(date('Y-m-d H:i:s'));

            $this->logger->info('Creating new user from Keycloak OAuth', [
                'email' => $email,
                'sub' => $sub,
                'username' => $username
            ]);
        }

        // Update user details with Keycloak data
        $userDetails = $user->getUserDetails() ?? [];
        $userDetails['oidc_sub'] = $sub;
        $userDetails['oidc_email'] = $email;
        $userDetails['oidc_username'] = $username;
        $userDetails['oidc_refresh_token'] = $refreshToken;
        $userDetails['oidc_last_login'] = (new \DateTime())->format('Y-m-d H:i:s');

        // Update name if provided (store in userDetails)
        if (isset($userInfo['given_name'])) {
            $userDetails['first_name'] = $userInfo['given_name'];
        }
        if (isset($userInfo['family_name'])) {
            $userDetails['last_name'] = $userInfo['family_name'];
        }
        if (isset($userInfo['name'])) {
            $userDetails['full_name'] = $userInfo['name'];
        }

        $user->setUserDetails($userDetails);
        
        // Verify email if user logged in via OIDC (OIDC emails are trusted)
        if (!$user->isEmailVerified() && $email && ($userInfo['email_verified'] ?? true)) {
            $user->setEmailVerified(true);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}

