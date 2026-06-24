<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\OAuthLoginResponder;
use App\Service\OAuthStateService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private OAuthStateService $oauthStateService,
        private OAuthLoginResponder $oauthLoginResponder,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
        private string $googleClientId,
        private string $googleClientSecret,
        private string $appUrl,
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

        // Native (mobile) clients open this URL in the system browser and need
        // the result delivered back via a deep link — remember that intent in
        // the signed state so the callback knows which flow to complete.
        $native = $request->query->getBoolean('native');

        // Generate signed state token (no session required)
        $state = $this->oauthStateService->generateState('google', $native ? ['native' => true] : []);

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

        // Validate signed state token (CSRF protection, no session required)
        $statePayload = $state ? $this->oauthStateService->validateState($state, 'google') : null;

        if (!$statePayload) {
            $this->logger->error('Google OAuth state validation failed', [
                'state_present' => !empty($state),
            ]);

            // Native flag lives inside the (here invalid) state, so default to
            // the web error page when we cannot trust it.
            return $this->oauthLoginResponder->error('google', 'Invalid state parameter', false);
        }

        $native = (bool) ($statePayload['native'] ?? false);

        if (!$code) {
            return $this->oauthLoginResponder->error('google', 'Authorization code not received', $native);
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

            $this->logger->info('Google OAuth successful', [
                'user_id' => $user->getId(),
                'email' => $user->getMail(),
                'native' => $native,
            ]);

            // Web → cookies + SPA redirect; native → deep-link handoff token.
            return $this->oauthLoginResponder->success($user, $request, 'google', $native);
        } catch (\Exception $e) {
            $this->logger->error('Google OAuth callback error', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 1000),
            ]);

            return $this->oauthLoginResponder->error('google', 'Failed to authenticate with Google', $native);
        }
    }

    private function findOrCreateUser(array $userInfo, ?string $refreshToken): User
    {
        $email = $userInfo['email'] ?? null;
        $googleId = $userInfo['id'] ?? null;

        if (!$email) {
            throw new \Exception('Email not provided by Google');
        }

        $user = $this->userRepository->findOneBy(['mail' => $email]);
        $isNewUser = false;

        if ($user) {
            if ($user->isManagedExternally()) {
                $this->logger->warning('Google OAuth blocked for Keycloak-managed user', [
                    'user_id' => $user->getId(),
                    'email' => $email,
                ]);
                throw new \Exception('Google OAuth not allowed for organization-managed account');
            }

            $this->logger->info('Existing user logging in via Google', [
                'user_id' => $user->getId(),
                'email' => $email,
                'original_provider' => $user->getProviderId(),
            ]);
        } else {
            $isNewUser = true;
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

        if ($isNewUser) {
            $this->modelConfigService->initializeNewUserDefaults($user->getId());
        }

        return $user;
    }
}
