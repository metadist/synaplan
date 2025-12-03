<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;

#[Route('/api/v1/auth/github')]
class GitHubAuthController extends AbstractController
{
    private const GITHUB_AUTH_URL = 'https://github.com/login/oauth/authorize';
    private const GITHUB_TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const GITHUB_USER_URL = 'https://api.github.com/user';
    private const GITHUB_EMAIL_URL = 'https://api.github.com/user/emails';

    public function __construct(
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private JWTTokenManagerInterface $jwtManager,
        private LoggerInterface $logger,
        private string $githubClientId,
        private string $githubClientSecret,
        private string $appUrl,
        private string $frontendUrl
    ) {
    }

    #[Route('/login', name: 'github_auth_login', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/github/login',
        summary: 'Initiate GitHub OAuth login',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 302,
        description: 'Redirect to GitHub OAuth authorization'
    )]
    public function login(Request $request): Response
    {
        $redirectUri = $this->appUrl . '/api/v1/auth/github/callback';
        $state = bin2hex(random_bytes(16));
        
        // Store state in session for CSRF protection
        $request->getSession()->set('github_oauth_state', $state);

        $params = [
            'client_id' => $this->githubClientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'user:email read:user',
            'state' => $state
        ];

        $authUrl = self::GITHUB_AUTH_URL . '?' . http_build_query($params);

        $this->logger->info('GitHub OAuth login initiated', [
            'redirect_uri' => $redirectUri,
            'state' => $state
        ]);

        return $this->redirect($authUrl);
    }

    #[Route('/callback', name: 'github_auth_callback', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/github/callback',
        summary: 'Handle GitHub OAuth callback',
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
        $storedState = $request->getSession()->get('github_oauth_state');

        // Validate state (CSRF protection)
        if (!$state || $state !== $storedState) {
            $this->logger->error('GitHub OAuth state mismatch', [
                'received_state' => $state,
                'stored_state' => $storedState
            ]);
            return $this->json([
                'success' => false,
                'error' => 'Invalid state parameter'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Clear stored state
        $request->getSession()->remove('github_oauth_state');

        if (!$code) {
            return $this->json([
                'success' => false,
                'error' => 'Authorization code not received'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Exchange authorization code for access token
            $tokenResponse = $this->httpClient->request('POST', self::GITHUB_TOKEN_URL, [
                'headers' => [
                    'Accept' => 'application/json'
                ],
                'body' => [
                    'code' => $code,
                    'client_id' => $this->githubClientId,
                    'client_secret' => $this->githubClientSecret,
                    'redirect_uri' => $this->appUrl . '/api/v1/auth/github/callback'
                ]
            ]);

            $tokenData = $tokenResponse->toArray();
            $accessToken = $tokenData['access_token'] ?? null;
            $tokenType = $tokenData['token_type'] ?? 'bearer';

            if (!$accessToken) {
                throw new \Exception('Access token not received from GitHub');
            }

            // Fetch user info from GitHub
            $userInfoResponse = $this->httpClient->request('GET', self::GITHUB_USER_URL, [
                'headers' => [
                    'Authorization' => ucfirst($tokenType) . ' ' . $accessToken,
                    'Accept' => 'application/json'
                ]
            ]);

            $userInfo = $userInfoResponse->toArray();

            // Fetch primary email if not provided
            $email = $userInfo['email'] ?? null;
            if (!$email) {
                $emailsResponse = $this->httpClient->request('GET', self::GITHUB_EMAIL_URL, [
                    'headers' => [
                        'Authorization' => ucfirst($tokenType) . ' ' . $accessToken,
                        'Accept' => 'application/json'
                    ]
                ]);

                $emails = $emailsResponse->toArray();
                foreach ($emails as $emailData) {
                    if ($emailData['primary'] && $emailData['verified']) {
                        $email = $emailData['email'];
                        break;
                    }
                }

                if (!$email && !empty($emails)) {
                    $email = $emails[0]['email'] ?? null;
                }
            }

            $this->logger->info('GitHub user info retrieved', [
                'login' => $userInfo['login'] ?? 'unknown',
                'email' => $email ?? 'no_email',
                'id' => $userInfo['id'] ?? 'unknown'
            ]);

            // Find or create user
            $user = $this->findOrCreateUser($userInfo, $email, $accessToken);

            // Generate JWT token for our app
            $jwtToken = $this->jwtManager->create($user);

            // Redirect to frontend with token
            $callbackUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'token' => $jwtToken,
                'provider' => 'github'
            ]);

            $this->logger->info('GitHub OAuth successful, redirecting to frontend', [
                'user_id' => $user->getId(),
                'email' => $user->getMail()
            ]);

            return $this->redirect($callbackUrl);

        } catch (\Exception $e) {
            $this->logger->error('GitHub OAuth callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Redirect to frontend with error
            $errorUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Failed to authenticate with GitHub',
                'provider' => 'github'
            ]);

            return $this->redirect($errorUrl);
        }
    }

    private function findOrCreateUser(array $userInfo, ?string $email, string $accessToken): User
    {
        $githubId = $userInfo['id'] ?? null;
        $githubLogin = $userInfo['login'] ?? null;

        if (!$githubId) {
            throw new \Exception('GitHub user ID not provided');
        }

        // Try to find existing user by email or GitHub ID
        $user = null;
        if ($email) {
            $user = $this->userRepository->findOneBy(['mail' => $email]);
        }

        if (!$user) {
            // Try to find by GitHub ID in userDetails
            $users = $this->userRepository->findAll();
            foreach ($users as $existingUser) {
                $details = $existingUser->getUserDetails() ?? [];
                if (isset($details['github_id']) && $details['github_id'] == $githubId) {
                    $user = $existingUser;
                    break;
                }
            }
        }

        if ($user) {
            // User exists - verify they registered with GitHub
            if ($user->getProviderId() !== 'github') {
                throw new \Exception(sprintf(
                    'This email is already registered using %s. Please use the same login method.',
                    $user->getAuthProviderName()
                ));
            }
            
            $this->logger->info('Existing GitHub user logging in', [
                'user_id' => $user->getId(),
                'email' => $email
            ]);
        } else {
            // Create new user
            $user = new User();
            $user->setMail($email ?? $githubLogin . '@github.local');
            $user->setType('WEB'); // Always WEB for web-based logins
            $user->setProviderId('github'); // Provider tracked here
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true); // OAuth users are pre-verified
            $user->setCreated(date('Y-m-d H:i:s'));
            $user->setUserDetails([]); // Initialize with empty array
            $user->setPaymentDetails([]); // Initialize with empty array

            $this->logger->info('Creating new user from GitHub OAuth', [
                'email' => $email,
                'github_id' => $githubId,
                'github_login' => $githubLogin
            ]);
        }

        // Update user details with GitHub data
        $userDetails = $user->getUserDetails() ?? [];
        $userDetails['github_id'] = $githubId;
        $userDetails['github_login'] = $githubLogin;
        $userDetails['github_access_token'] = $accessToken;
        $userDetails['github_last_login'] = (new \DateTime())->format('Y-m-d H:i:s');

        if ($email) {
            $userDetails['github_email'] = $email;
        }

        // Parse name from GitHub name field (store in userDetails)
        $fullName = $userInfo['name'] ?? $githubLogin;
        $nameParts = explode(' ', $fullName, 2);
        $userDetails['first_name'] = $nameParts[0] ?? $githubLogin;
        $userDetails['last_name'] = $nameParts[1] ?? '';

        if (isset($userInfo['avatar_url'])) {
            $userDetails['github_avatar'] = $userInfo['avatar_url'];
        }
        if (isset($userInfo['bio'])) {
            $userDetails['github_bio'] = $userInfo['bio'];
        }
        if (isset($userInfo['company'])) {
            $userDetails['github_company'] = $userInfo['company'];
        }
        if (isset($userInfo['location'])) {
            $userDetails['github_location'] = $userInfo['location'];
        }

        $user->setUserDetails($userDetails);
        
        // Verify email if user logged in via GitHub (OAuth emails are trusted)
        if (!$user->isEmailVerified() && $email) {
            $user->setEmailVerified(true);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}

