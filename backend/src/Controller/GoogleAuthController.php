<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
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
        private TokenService $tokenService,
        private LoggerInterface $logger,
        private string $googleClientId,
        private string $googleClientSecret,
        private string $appUrl,
        private string $frontendUrl,
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
        $redirectUri = $this->appUrl.'/api/v1/auth/google/callback';
        $state = bin2hex(random_bytes(16));

        // Store state in session for CSRF protection
        $request->getSession()->set('google_oauth_state', $state);

        $params = [
            'client_id' => $this->googleClientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        $authUrl = self::GOOGLE_AUTH_URL.'?'.http_build_query($params);

        $this->logger->info('Google OAuth login initiated', [
            'redirect_uri' => $redirectUri,
            'state' => $state,
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
        description: 'Redirects to frontend with auth cookies set'
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
                'stored_state' => $storedState,
            ]);

            $errorUrl = $this->frontendUrl.'/auth/callback?'.http_build_query([
                'error' => 'Invalid state parameter',
                'provider' => 'google',
            ]);

            return $this->redirect($errorUrl);
        }

        // Clear stored state
        $request->getSession()->remove('google_oauth_state');

        if (!$code) {
            $errorUrl = $this->frontendUrl.'/auth/callback?'.http_build_query([
                'error' => 'Authorization code not received',
                'provider' => 'google',
            ]);

            return $this->redirect($errorUrl);
        }

        try {
            // Exchange authorization code for access token
            $tokenResponse = $this->httpClient->request('POST', self::GOOGLE_TOKEN_URL, [
                'body' => [
                    'code' => $code,
                    'client_id' => $this->googleClientId,
                    'client_secret' => $this->googleClientSecret,
                    'redirect_uri' => $this->appUrl.'/api/v1/auth/google/callback',
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $tokenData = $tokenResponse->toArray();
            $googleAccessToken = $tokenData['access_token'] ?? null;
            $googleRefreshToken = $tokenData['refresh_token'] ?? null;

            if (!$googleAccessToken) {
                throw new \Exception('Access token not received from Google');
            }

            // Fetch user info from Google
            $userInfoResponse = $this->httpClient->request('GET', self::GOOGLE_USERINFO_URL, [
                'headers' => [
                    'Authorization' => 'Bearer '.$googleAccessToken,
                ],
            ]);

            $userInfo = $userInfoResponse->toArray();

            $this->logger->info('Google user info retrieved', [
                'email' => $userInfo['email'] ?? 'unknown',
                'verified' => $userInfo['verified_email'] ?? false,
            ]);

            // Find or create user
            $user = $this->findOrCreateUser($userInfo, $googleRefreshToken);

            // Generate our tokens
            $accessToken = $this->tokenService->generateAccessToken($user);
            $refreshToken = $this->tokenService->generateRefreshToken($user, $request->getClientIp());

            // Create redirect response with cookies
            $callbackUrl = $this->frontendUrl.'/auth/callback?'.http_build_query([
                'success' => 'true',
                'provider' => 'google',
            ]);

            $response = new RedirectResponse($callbackUrl);

            // Add auth cookies
            $this->tokenService->addAuthCookies($response, $accessToken, $refreshToken);

            $this->logger->info('Google OAuth successful, redirecting with cookies', [
                'user_id' => $user->getId(),
                'email' => $user->getMail(),
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Google OAuth callback error', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 1000),
            ]);

            $errorUrl = $this->frontendUrl.'/auth/callback?'.http_build_query([
                'error' => 'Failed to authenticate with Google',
                'provider' => 'google',
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

        if ($user) {
            // User exists - verify they registered with Google
            if ('google' !== $user->getProviderId()) {
                throw new \Exception(sprintf('This email is already registered using %s. Please use the same login method.', $user->getAuthProviderName()));
            }

            $this->logger->info('Existing Google user logging in', [
                'user_id' => $user->getId(),
                'email' => $email,
            ]);
        } else {
            // Create new user
            $user = new User();
            $user->setMail($email);
            $user->setType('WEB');
            $user->setProviderId('google');
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true);
            $user->setCreated(date('Y-m-d H:i:s'));
            $user->setUserDetails([]);
            $user->setPaymentDetails([]);

            $this->logger->info('Creating new user from Google OAuth', [
                'email' => $email,
                'google_id' => $googleId,
            ]);
        }

        // Update user details with Google data
        $userDetails = $user->getUserDetails() ?? [];
        $userDetails['google_id'] = $googleId;
        $userDetails['google_email'] = $email;
        $userDetails['google_verified_email'] = $userInfo['verified_email'] ?? false;
        $userDetails['google_refresh_token'] = $refreshToken;
        $userDetails['google_last_login'] = (new \DateTime())->format('Y-m-d H:i:s');

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

        if (!$user->isEmailVerified() && ($userInfo['verified_email'] ?? false)) {
            $user->setEmailVerified(true);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
