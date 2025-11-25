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

#[Route('/api/v1/auth/google')]
class GoogleAuthController extends AbstractController
{
    private const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    public function __construct(
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private JWTTokenManagerInterface $jwtManager,
        private LoggerInterface $logger,
        private string $googleClientId,
        private string $googleClientSecret,
        private string $appUrl,
        private string $frontendUrl
    ) {
    }

    #[Route('/login', name: 'google_auth_login', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/google/login',
        summary: 'Initiate Google OAuth login',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 302,
        description: 'Redirect to Google OAuth consent screen'
    )]
    public function login(Request $request): Response
    {
        $redirectUri = $this->appUrl . '/api/v1/auth/google/callback';
        $state = bin2hex(random_bytes(16));
        
        // Store state in session for CSRF protection
        $request->getSession()->set('google_oauth_state', $state);

        $params = [
            'client_id' => $this->googleClientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'offline', // Request refresh token
            'prompt' => 'consent' // Force consent to get refresh token
        ];

        $authUrl = self::GOOGLE_AUTH_URL . '?' . http_build_query($params);

        $this->logger->info('Google OAuth login initiated', [
            'redirect_uri' => $redirectUri,
            'state' => $state
        ]);

        return $this->redirect($authUrl);
    }

    #[Route('/callback', name: 'google_auth_callback', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/google/callback',
        summary: 'Handle Google OAuth callback',
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
        $storedState = $request->getSession()->get('google_oauth_state');

        // Validate state (CSRF protection)
        if (!$state || $state !== $storedState) {
            $this->logger->error('Google OAuth state mismatch', [
                'received_state' => $state,
                'stored_state' => $storedState
            ]);
            return $this->json([
                'success' => false,
                'error' => 'Invalid state parameter'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Clear stored state
        $request->getSession()->remove('google_oauth_state');

        if (!$code) {
            return $this->json([
                'success' => false,
                'error' => 'Authorization code not received'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Exchange authorization code for access token
            $tokenResponse = $this->httpClient->request('POST', self::GOOGLE_TOKEN_URL, [
                'body' => [
                    'code' => $code,
                    'client_id' => $this->googleClientId,
                    'client_secret' => $this->googleClientSecret,
                    'redirect_uri' => $this->appUrl . '/api/v1/auth/google/callback',
                    'grant_type' => 'authorization_code'
                ]
            ]);

            $tokenData = $tokenResponse->toArray();
            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $expiresIn = $tokenData['expires_in'] ?? 3600;

            if (!$accessToken) {
                throw new \Exception('Access token not received from Google');
            }

            // Fetch user info from Google
            $userInfoResponse = $this->httpClient->request('GET', self::GOOGLE_USERINFO_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);

            $userInfo = $userInfoResponse->toArray();

            $this->logger->info('Google user info retrieved', [
                'email' => $userInfo['email'] ?? 'unknown',
                'verified' => $userInfo['verified_email'] ?? false
            ]);

            // Find or create user
            $user = $this->findOrCreateUser($userInfo, $refreshToken);

            // Generate JWT token for our app
            $jwtToken = $this->jwtManager->create($user);

            // Redirect to frontend with token
            $callbackUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'token' => $jwtToken,
                'provider' => 'google'
            ]);

            $this->logger->info('Google OAuth successful, redirecting to frontend', [
                'user_id' => $user->getId(),
                'email' => $user->getMail()
            ]);

            return $this->redirect($callbackUrl);

        } catch (\Exception $e) {
            $this->logger->error('Google OAuth callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Redirect to frontend with error
            $errorUrl = $this->frontendUrl . '/auth/callback?' . http_build_query([
                'error' => 'Failed to authenticate with Google',
                'provider' => 'google'
            ]);

            return $this->redirect($errorUrl);
        }
    }

    private function findOrCreateUser(array $userInfo, ?string $refreshToken): User
    {
        $email = $userInfo['email'] ?? null;
        $googleId = $userInfo['id'] ?? null;

        if (!$email) {
            throw new \Exception('Email not provided by Google');
        }

        // Try to find existing user by email
        $user = $this->userRepository->findOneBy(['mail' => $email]);

        if (!$user) {
            // Create new user
            $user = new User();
            $user->setMail($email);
            $user->setType('GOOGLE');
            $user->setProviderId('google');
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true); // OAuth users are pre-verified
            $user->setCreated(date('Y-m-d H:i:s'));

            $this->logger->info('Creating new user from Google OAuth', [
                'email' => $email,
                'google_id' => $googleId
            ]);
        }

        // Update user details with Google data
        $userDetails = $user->getUserDetails() ?? [];
        $userDetails['google_id'] = $googleId;
        $userDetails['google_email'] = $email;
        $userDetails['google_verified_email'] = $userInfo['verified_email'] ?? false;
        $userDetails['google_refresh_token'] = $refreshToken;
        $userDetails['google_last_login'] = (new \DateTime())->format('Y-m-d H:i:s');

        // Update name if provided (store in userDetails)
        if (isset($userInfo['given_name'])) {
            $userDetails['first_name'] = $userInfo['given_name'];
        }
        if (isset($userInfo['family_name'])) {
            $userDetails['last_name'] = $userInfo['family_name'];
        }
        if (isset($userInfo['picture'])) {
            $userDetails['google_picture'] = $userInfo['picture'];
        }

        $user->setUserDetails($userDetails);
        
        // Verify email if user logged in via Google (OAuth emails are trusted)
        if (!$user->isEmailVerified() && ($userInfo['verified_email'] ?? false)) {
            $user->setEmailVerified(true);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}

