<?php

namespace App\Controller;

use App\Entity\User;
use App\Model\AccountLanguage;
use App\Repository\UserRepository;
use App\Service\Auth\OAuthLoginResponder;
use App\Service\Auth\OAuthSignupGate;
use App\Service\Auth\SignupBlockedException;
use App\Service\OAuth\OAuthStateService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private OAuthStateService $oauthStateService,
        private OAuthLoginResponder $oauthLoginResponder,
        private OAuthSignupGate $signupGate,
        private LoggerInterface $logger,
        private string $githubClientId,
        private string $githubClientSecret,
        private string $appUrl,
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
        $redirectUri = $this->appUrl.'/api/v1/auth/github/callback';

        // Native (mobile) clients open this in the system browser and need the
        // result via a deep link — remember that intent in the signed state.
        $native = $request->query->getBoolean('native');

        // Signed state (no session required); also records the passed human
        // check the callback requires before creating a NEW account.
        $state = $this->oauthStateService->generateState('github', AccountLanguage::withSignupLanguage(
            ($native ? ['native' => true] : []) + $this->signupGate->stateExtras($request, 'github'),
            $request->query->get('language'),
        ));

        $params = [
            'client_id' => $this->githubClientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'user:email read:user',
            'state' => $state,
        ];

        $authUrl = self::GITHUB_AUTH_URL.'?'.http_build_query($params);

        $this->logger->info('GitHub OAuth login initiated', [
            'redirect_uri' => $redirectUri,
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
        description: 'Redirects to frontend with auth cookies set'
    )]
    public function callback(Request $request): Response
    {
        $code = $request->query->get('code');
        $state = $request->query->get('state');

        // Validate signed state token (CSRF protection, no session required)
        $statePayload = $state ? $this->oauthStateService->validateState($state, 'github') : null;

        if (!$statePayload) {
            $this->logger->error('GitHub OAuth state validation failed', [
                'state_present' => !empty($state),
            ]);

            return $this->oauthLoginResponder->error('github', 'Invalid state parameter', false);
        }

        $native = (bool) ($statePayload['native'] ?? false);

        if (!$code) {
            return $this->oauthLoginResponder->error('github', 'Authorization code not received', $native);
        }

        try {
            // Exchange authorization code for access token
            $tokenResponse = $this->httpClient->request('POST', self::GITHUB_TOKEN_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'code' => $code,
                    'client_id' => $this->githubClientId,
                    'client_secret' => $this->githubClientSecret,
                    'redirect_uri' => $this->appUrl.'/api/v1/auth/github/callback',
                ],
            ]);

            $tokenData = $tokenResponse->toArray();
            $githubAccessToken = $tokenData['access_token'] ?? null;
            $tokenType = $tokenData['token_type'] ?? 'bearer';

            if (!$githubAccessToken) {
                throw new \Exception('Access token not received from GitHub');
            }

            // Fetch user info from GitHub
            $userInfoResponse = $this->httpClient->request('GET', self::GITHUB_USER_URL, [
                'headers' => [
                    'Authorization' => ucfirst($tokenType).' '.$githubAccessToken,
                    'Accept' => 'application/json',
                ],
            ]);

            $userInfo = $userInfoResponse->toArray();

            // Fetch primary email if not provided
            $email = $userInfo['email'] ?? null;
            if (!$email) {
                $emailsResponse = $this->httpClient->request('GET', self::GITHUB_EMAIL_URL, [
                    'headers' => [
                        'Authorization' => ucfirst($tokenType).' '.$githubAccessToken,
                        'Accept' => 'application/json',
                    ],
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

            // A new account needs the human check from the start of the flow
            // and a free signup slot; an existing account signs in as before.
            $this->signupGate->assertGithubSignup($email, $userInfo['id'] ?? null, $statePayload, $request->getClientIp());
            $user = $this->findOrCreateUser(
                $userInfo,
                $email,
                $githubAccessToken,
                AccountLanguage::normalize($statePayload['language'] ?? null), $statePayload,
            );

            $this->logger->info('GitHub OAuth successful', [
                'user_id' => $user->getId(),
                'email' => $user->getMail(),
                'native' => $native,
            ]);

            // Web → cookies + SPA redirect; native → deep-link handoff token.
            return $this->oauthLoginResponder->success($user, $request, 'github', $native);
        } catch (SignupBlockedException $e) {
            return $this->oauthLoginResponder->error('github', $e->getMessage(), $native, ['error_code' => $e->getErrorCodeParam()]);
        } catch (\Exception $e) {
            $this->logger->error('GitHub OAuth callback error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return $this->oauthLoginResponder->error('github', 'Failed to authenticate with GitHub', $native);
        }
    }

    private function findOrCreateUser(array $userInfo, ?string $email, string $accessToken, ?string $signupLanguage = null, array $admissionState = []): User
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

        if (!$user && (is_int($githubId) || is_string($githubId))) {
            $user = $this->userRepository->findByGithubId($githubId);
        }

        $isNewUser = false;

        if ($user) {
            if ($user->isManagedExternally()) {
                $this->logger->warning('GitHub OAuth blocked for Keycloak-managed user', [
                    'user_id' => $user->getId(),
                    'email' => $email,
                ]);
                throw new \Exception('GitHub OAuth not allowed for organization-managed account');
            }

            $this->logger->info('Existing user logging in via GitHub', [
                'user_id' => $user->getId(),
                'email' => $email,
                'original_provider' => $user->getProviderId(),
            ]);
        } else {
            $isNewUser = true;
            $user = new User();
            $user->setMail($email ?? $githubLogin.'@github.local');
            $user->setType('WEB');
            $user->setProviderId('github');
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true);
            $user->setCreated(date('Y-m-d H:i:s'));
            $user->setUserDetails([]);
            $user->setPaymentDetails([]);

            $this->logger->info('Creating new user from GitHub OAuth', [
                'email' => $email,
                'github_id' => $githubId,
                'github_login' => $githubLogin,
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
        if ($isNewUser) {
            $user->applySignupLanguage($signupLanguage);
        }

        if (!$user->isEmailVerified() && $email) {
            $user->setEmailVerified(true);
        }

        $this->signupGate->finishSignIn($user, $isNewUser, $admissionState);

        return $user;
    }
}
