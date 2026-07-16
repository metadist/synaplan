<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\AppleClientSecretGenerator;
use App\Service\Auth\AppleIdentityTokenVerifier;
use App\Service\ModelConfigService;
use App\Service\OAuthLoginResponder;
use App\Service\OAuthStateService;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sign in with Apple.
 *
 * Mirrors {@see GoogleAuthController} for the web/Android browser flow, with two
 * Apple-specific differences:
 *  - Apple returns the callback via `response_mode=form_post` (an HTTP POST),
 *    and the user's name is delivered only on the FIRST authorization in a
 *    `user` form field — so we persist it opportunistically.
 *  - iOS uses the native Sign-in-with-Apple system UI (Capacitor plugin) rather
 *    than a browser round-trip; {@see native()} accepts the resulting identity
 *    token directly and issues Bearer tokens without a deep-link handoff.
 */
#[Route('/api/v1/auth/apple')]
class AppleAuthController extends AbstractController
{
    private const APPLE_AUTH_URL = 'https://appleid.apple.com/auth/authorize';
    private const APPLE_TOKEN_URL = 'https://appleid.apple.com/auth/token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly OAuthStateService $oauthStateService,
        private readonly OAuthLoginResponder $oauthLoginResponder,
        private readonly ModelConfigService $modelConfigService,
        private readonly AppleClientSecretGenerator $clientSecretGenerator,
        private readonly AppleIdentityTokenVerifier $identityTokenVerifier,
        private readonly TokenService $tokenService,
        private readonly LoggerInterface $logger,
        private readonly string $appleClientId,
        private readonly string $appUrl,
    ) {
    }

    #[Route('/login', name: 'apple_auth_login', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/apple/login',
        summary: 'Initiate Sign in with Apple (web/Android)',
        tags: ['Authentication']
    )]
    #[OA\Response(response: 302, description: 'Redirect to the Apple authorization screen')]
    public function login(Request $request): Response
    {
        $native = $request->query->getBoolean('native');

        if ('' === $this->appleClientId) {
            return $this->oauthLoginResponder->error('apple', 'Apple Sign-In is not configured', $native);
        }

        $state = $this->oauthStateService->generateState('apple', $native ? ['native' => true] : []);

        $params = [
            'client_id' => $this->appleClientId,
            'redirect_uri' => $this->appUrl.'/api/v1/auth/apple/callback',
            'response_type' => 'code',
            // Requesting name/email requires the response to be POSTed back.
            'response_mode' => 'form_post',
            'scope' => 'name email',
            'state' => $state,
        ];

        return $this->redirect(self::APPLE_AUTH_URL.'?'.http_build_query($params));
    }

    #[Route('/callback', name: 'apple_auth_callback', methods: ['GET', 'POST'])]
    #[OA\Post(
        path: '/api/v1/auth/apple/callback',
        summary: 'Handle the Apple OAuth callback (form_post)',
        tags: ['Authentication']
    )]
    #[OA\Response(response: 302, description: 'Redirects to the SPA with auth cookies, or to the app deep link for native clients')]
    public function callback(Request $request): Response
    {
        $code = (string) ($request->request->get('code') ?? $request->query->get('code') ?? '');
        $state = (string) ($request->request->get('state') ?? $request->query->get('state') ?? '');
        $userField = (string) ($request->request->get('user') ?? '');

        $statePayload = '' !== $state ? $this->oauthStateService->validateState($state, 'apple') : null;
        if (null === $statePayload) {
            $this->logger->error('Apple OAuth state validation failed', ['state_present' => '' !== $state]);

            return $this->oauthLoginResponder->error('apple', 'Invalid state parameter', false);
        }

        $native = (bool) ($statePayload['native'] ?? false);

        if ('' === $code) {
            return $this->oauthLoginResponder->error('apple', 'Authorization code not received', $native);
        }

        try {
            $clientSecret = $this->clientSecretGenerator->generate();

            $tokenResponse = $this->httpClient->request('POST', self::APPLE_TOKEN_URL, [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->appUrl.'/api/v1/auth/apple/callback',
                    'client_id' => $this->appleClientId,
                    'client_secret' => $clientSecret,
                ],
            ]);

            $tokenData = $tokenResponse->toArray();
            $identityToken = $tokenData['id_token'] ?? null;
            if (!is_string($identityToken) || '' === $identityToken) {
                throw new \RuntimeException('Apple token response did not contain an id_token');
            }

            $claims = $this->identityTokenVerifier->verify($identityToken);
            $profile = $this->parseUserField($userField);
            $user = $this->findOrCreateUser($claims, $profile);

            $this->logger->info('Apple OAuth successful', [
                'user_id' => $user->getId(),
                'native' => $native,
            ]);

            return $this->oauthLoginResponder->success($user, $request, 'apple', $native);
        } catch (\Throwable $e) {
            $this->logger->error('Apple OAuth callback error', ['error' => $e->getMessage()]);

            return $this->oauthLoginResponder->error('apple', 'Failed to authenticate with Apple', $native);
        }
    }

    #[Route('/native', name: 'apple_auth_native', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/apple/native',
        summary: 'Sign in with Apple from the native iOS app',
        description: 'The iOS app obtains an identity token from the native Sign-in-with-Apple UI and posts it here. The token is verified against Apple and Bearer tokens are returned in the body (no cookies).',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['identityToken'],
            properties: [
                new OA\Property(property: 'identityToken', type: 'string', description: 'Apple identity token (JWT) from the native sign-in'),
                new OA\Property(property: 'firstName', type: 'string', nullable: true, description: 'Given name, only present on the first authorization'),
                new OA\Property(property: 'lastName', type: 'string', nullable: true, description: 'Family name, only present on the first authorization'),
                new OA\Property(property: 'email', type: 'string', nullable: true, description: 'Email, only present on the first authorization'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Bearer tokens issued')]
    #[OA\Response(response: 400, description: 'Missing identity token')]
    #[OA\Response(response: 401, description: 'Invalid identity token')]
    public function native(Request $request): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        $body = is_array($decoded) ? $decoded : [];
        $identityToken = isset($body['identityToken']) && is_string($body['identityToken'])
            ? $body['identityToken']
            : null;

        if (null === $identityToken || '' === $identityToken) {
            return new JsonResponse(['success' => false, 'error' => 'Missing identity token'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $claims = $this->identityTokenVerifier->verify($identityToken);
        } catch (\Throwable $e) {
            $this->logger->warning('Apple native identity token rejected', ['error' => $e->getMessage()]);

            return new JsonResponse(['success' => false, 'error' => 'Invalid identity token'], Response::HTTP_UNAUTHORIZED);
        }

        $profile = [
            'firstName' => isset($body['firstName']) && is_string($body['firstName']) ? $body['firstName'] : null,
            'lastName' => isset($body['lastName']) && is_string($body['lastName']) ? $body['lastName'] : null,
            'email' => isset($body['email']) && is_string($body['email']) ? $body['email'] : null,
        ];

        $user = $this->findOrCreateUser($claims, $profile);

        $accessToken = $this->tokenService->generateAccessToken($user);
        $refreshToken = $this->tokenService->generateRefreshToken($user, $request->getClientIp());

        $this->logger->info('Apple native sign-in successful', ['user_id' => $user->getId()]);

        return new JsonResponse([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getMail(),
                'level' => $user->getUserLevel(),
                'emailVerified' => $user->isEmailVerified(),
                'isAdmin' => $user->isAdmin(),
                'memoriesEnabled' => $user->isMemoriesEnabled(),
                'firstName' => $this->extractFirstName($user),
            ],
            'tokens' => [
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'tokenType' => 'Bearer',
                'expiresIn' => TokenService::ACCESS_TOKEN_TTL,
            ],
        ]);
    }

    /**
     * Apple sends the user's name only on the very first authorization, as a
     * JSON string in a `user` field: {"name":{"firstName":..,"lastName":..},"email":..}.
     *
     * @return array{firstName: ?string, lastName: ?string, email: ?string}
     */
    private function parseUserField(string $userField): array
    {
        $out = ['firstName' => null, 'lastName' => null, 'email' => null];

        if ('' === $userField) {
            return $out;
        }

        $data = json_decode($userField, true);
        if (!is_array($data)) {
            return $out;
        }

        $name = $data['name'] ?? null;
        if (is_array($name)) {
            $out['firstName'] = isset($name['firstName']) && is_string($name['firstName']) ? $name['firstName'] : null;
            $out['lastName'] = isset($name['lastName']) && is_string($name['lastName']) ? $name['lastName'] : null;
        }
        $out['email'] = isset($data['email']) && is_string($data['email']) ? $data['email'] : null;

        return $out;
    }

    /**
     * @param array{sub: string, email: ?string, emailVerified: bool, isPrivateEmail: bool} $claims
     * @param array{firstName: ?string, lastName: ?string, email: ?string}                  $profile
     */
    private function findOrCreateUser(array $claims, array $profile): User
    {
        $appleSub = $claims['sub'];
        $email = $claims['email'] ?? $profile['email'];

        // The stable key is Apple's `sub` (survives private-relay email changes);
        // fall back to email only for accounts created via another provider.
        $user = $this->userRepository->findByAppleSub($appleSub);
        if (!$user && null !== $email && '' !== $email) {
            $user = $this->userRepository->findOneBy(['mail' => $email]);
        }

        $isNewUser = false;

        if ($user) {
            if ($user->isManagedExternally()) {
                throw new \RuntimeException('Apple Sign-In is not allowed for an organization-managed account');
            }
        } else {
            // Apple may withhold the email on later logins; when it never gave us
            // one, synthesize a stable placeholder from the immutable subject.
            if (null === $email || '' === $email) {
                $email = $appleSub.'@privaterelay.appleid.local';
            }

            $isNewUser = true;
            $user = new User();
            $user->setMail($email);
            $user->setType('WEB');
            $user->setProviderId('apple');
            $user->setUserLevel('NEW');
            $user->setEmailVerified($claims['emailVerified']);
            $user->setCreated(date('Y-m-d H:i:s'));
            $user->setUserDetails([]);
            $user->setPaymentDetails([]);
        }

        $details = $user->getUserDetails();
        $details['apple_sub'] = $appleSub;
        if (null !== $email) {
            $details['apple_email'] = $email;
        }
        $details['apple_is_private_email'] = $claims['isPrivateEmail'];
        $details['apple_last_login'] = (new \DateTime())->format('Y-m-d H:i:s');

        // Name arrives only on first authorization — never overwrite a name the
        // user may have since edited in their profile.
        if (null !== $profile['firstName'] && !isset($details['first_name'])) {
            $details['first_name'] = $profile['firstName'];
        }
        if (null !== $profile['lastName'] && !isset($details['last_name'])) {
            $details['last_name'] = $profile['lastName'];
        }

        $user->setUserDetails($details);

        if (!$user->isEmailVerified() && $claims['emailVerified']) {
            $user->setEmailVerified(true);
        }

        $this->em->persist($user);
        $this->em->flush();

        if ($isNewUser) {
            $this->modelConfigService->initializeNewUserDefaults((int) $user->getId());
        }

        return $user;
    }

    private function extractFirstName(User $user): ?string
    {
        $details = $user->getUserDetails();
        $firstName = trim((string) ($details['first_name'] ?? $details['firstName'] ?? ''));

        return '' !== $firstName ? $firstName : null;
    }
}
