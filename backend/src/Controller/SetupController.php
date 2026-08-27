<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\SetupAdminRequest;
use App\DTO\SetupCompleteRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Client\ClientContextResolver;
use App\Service\GuestChatConfig;
use App\Service\MailerConfig;
use App\Service\RegistrationConfig;
use App\Service\Setup\SetupStateService;
use App\Service\TokenService;
use App\Service\UserLifecycleService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * First-run setup for an installation that has no administrator yet.
 *
 * Exists for the deployments that would otherwise be a dead end: a production
 * container (`APP_ENV=prod`, so no demo fixtures) started without
 * `BOOTSTRAP_ADMIN_*` has no account to sign in with and no way to create one,
 * because self-registration would only ever produce a non-admin user.
 *
 * The window is narrow by construction. `/state` and `/admin` are public, but
 * only while {@see SetupStateService::isSetupRequired()} holds — which needs an
 * empty BUSER table and a missing SETUP.COMPLETED flag. The first successful
 * POST /admin creates a user and thereby closes the window for every later
 * request; {@see \App\EventSubscriber\SetupLockdownSubscriber} keeps the rest of
 * the API shut in the meantime, so nothing else can create the BUSER row that
 * would strand the instance.
 */
#[Route('/api/v1/setup', name: 'api_setup_')]
#[OA\Tag(name: 'Setup')]
final class SetupController extends AbstractController
{
    private const LOCK_TTL_SECONDS = 30.0;

    public function __construct(
        private readonly SetupStateService $setupState,
        private readonly UserRepository $userRepository,
        private readonly UserLifecycleService $userLifecycleService,
        private readonly TokenService $tokenService,
        private readonly ClientContextResolver $clientContextResolver,
        private readonly RegistrationConfig $registrationConfig,
        private readonly GuestChatConfig $guestChatConfig,
        private readonly LockFactory $lockFactory,
        private readonly RateLimiterFactoryInterface $setupAttemptLimiter,
        private readonly LoggerInterface $logger,
        private readonly MailerConfig $mailerConfig,
    ) {
    }

    #[Route('/state', name: 'state', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/setup/state',
        summary: 'First-run setup state',
        description: 'Public. Tells the frontend whether this installation still needs its first administrator, and which access-policy switches the wizard may actually change. Carries no secrets and no version details.',
        tags: ['Setup']
    )]
    #[OA\Response(
        response: 200,
        description: 'Setup state',
        content: new OA\JsonContent(
            required: ['wizardRequired', 'adminExists', 'access'],
            properties: [
                new OA\Property(property: 'wizardRequired', type: 'boolean', example: true, description: 'True only on a virgin installation: no SETUP.COMPLETED flag, no BUSER row, wizard not disabled via SETUP_WIZARD_ENABLED.'),
                new OA\Property(property: 'adminExists', type: 'boolean', example: false, description: 'True when at least one BUSERLEVEL=ADMIN account exists. The wizard shows a "sign in instead" hint when this is true while wizardRequired is false.'),
                new OA\Property(property: 'mailerConfigured', type: 'boolean', example: false, description: 'False when MAILER_DSN is unset or the null transport. Open self-registration without a mailer means new users cannot confirm their email address, so the wizard warns about that combination.'),
                new OA\Property(
                    property: 'access',
                    type: 'object',
                    description: 'Current access policy and whether an environment variable pins it. A pinned switch is shown read-only in the wizard instead of pretending it can be changed.',
                    required: ['registrationEnabled', 'guestChatEnabled', 'registrationLocked', 'guestChatLocked'],
                    properties: [
                        new OA\Property(property: 'registrationEnabled', type: 'boolean', example: true),
                        new OA\Property(property: 'guestChatEnabled', type: 'boolean', example: true),
                        new OA\Property(property: 'registrationLocked', type: 'boolean', example: false, description: 'REGISTRATION_ENABLED is set in the environment and wins over anything stored.'),
                        new OA\Property(property: 'guestChatLocked', type: 'boolean', example: false, description: 'GUEST_CHAT_ENABLED is set in the environment and wins over anything stored.'),
                    ]
                ),
            ]
        )
    )]
    public function state(): JsonResponse
    {
        return $this->json([
            'wizardRequired' => $this->setupState->isSetupRequired(),
            'adminExists' => $this->userRepository->hasAdmin(),
            'mailerConfigured' => $this->mailerConfig->isConfigured(),
            'access' => [
                'registrationEnabled' => $this->registrationConfig->isEnabled(),
                'guestChatEnabled' => $this->guestChatConfig->isEnabled(),
                'registrationLocked' => $this->registrationConfig->isLockedByEnv(),
                'guestChatLocked' => $this->guestChatConfig->isLockedByEnv(),
            ],
        ]);
    }

    #[Route('/admin', name: 'admin', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/setup/admin',
        summary: 'Create the first administrator',
        description: 'Public, but only while setup is required. Creates an ADMIN account with a verified email address (there is no mailbox to confirm through yet, and no second account that could be hijacked) and signs it in, so the wizard can continue against the admin API. Refuses with 409 as soon as the installation has any user.',
        tags: ['Setup']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', description: '8-64 characters with at least one uppercase letter, one lowercase letter and one digit.', example: 'SecurePass123'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Administrator created and signed in (auth cookies set; the native app additionally receives Bearer tokens in the body)',
        content: new OA\JsonContent(
            required: ['success', 'user'],
            properties: [
                new OA\Property(
                    property: 'success',
                    type: 'boolean',
                    example: true
                ),
                new OA\Property(
                    property: 'user',
                    type: 'object',
                    required: ['id', 'email', 'level', 'isAdmin'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'email', type: 'string', example: 'admin@example.com'),
                        new OA\Property(property: 'level', type: 'string', example: 'ADMIN'),
                        new OA\Property(property: 'isAdmin', type: 'boolean', example: true),
                    ]
                ),
                new OA\Property(
                    property: 'tokens',
                    type: 'object',
                    nullable: true,
                    description: 'Only for the native app, whose cross-origin WebView cannot rely on cookies.',
                    properties: [
                        new OA\Property(property: 'accessToken', type: 'string'),
                        new OA\Property(property: 'refreshToken', type: 'string'),
                        new OA\Property(property: 'tokenType', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'expiresIn', type: 'integer', example: 3600),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Validation error (email format, password rules)')]
    #[OA\Response(response: 409, description: 'Setup is already complete — code SETUP_ALREADY_COMPLETED; the wizard is switched off through SETUP_WIZARD_ENABLED=false — code SETUP_WIZARD_DISABLED; or another caller is creating the administrator right now — code SETUP_IN_PROGRESS, retriable')]
    #[OA\Response(response: 429, description: 'Too many setup attempts from this IP')]
    public function createFirstAdmin(
        #[MapRequestPayload] SetupAdminRequest $dto,
        Request $request,
    ): JsonResponse {
        if (!$this->setupAttemptLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['error' => 'too_many_requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!$this->setupState->isSetupRequired()) {
            return $this->setupState->isWizardEnabled()
                ? $this->alreadyCompleted()
                : $this->wizardDisabled();
        }

        // Two containers starting at once (Compose scale, a Kubernetes rollout),
        // or simply a second browser tab, would otherwise both pass the check
        // above and create two "first" administrators. Same lock discipline as
        // BootstrapAdminService, which solves the identical race on the headless
        // path.
        //
        // Non-blocking on purpose: the caller is a person waiting on a form, and
        // the loser of this race has nothing to wait for — the request holding
        // the lock is about to create the one administrator there will be. An
        // answer it can act on beats holding the connection open for the lock's
        // whole lifetime.
        $lock = $this->lockFactory->createLock('first-run-setup-admin', self::LOCK_TTL_SECONDS, false);
        if (!$lock->acquire()) {
            return $this->setupInProgress();
        }

        try {
            // Re-checked under the lock: the loser of the race must not create a
            // second administrator on the strength of a stale read.
            if (0 !== $this->userRepository->countAll()) {
                return $this->alreadyCompleted();
            }

            $user = $this->userLifecycleService->createUser(
                email: $dto->email,
                plainPassword: $dto->password,
                userLevel: 'ADMIN',
                // No mailbox round trip is possible yet — the mailer may well be
                // one of the things this administrator is about to configure —
                // and there is no other account this could grant access to.
                emailVerified: true,
            );

            $this->logger->notice('First administrator created through the setup wizard', [
                'user_id' => $user->getId(),
            ]);
        } finally {
            $lock->release();
        }

        return $this->signIn($user, $request);
    }

    #[Route('/complete', name: 'complete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(
        path: '/api/v1/setup/complete',
        summary: 'Store the access policy and close the setup window',
        description: 'Requires ROLE_ADMIN — by this point the wizard is signed in as the administrator it created. Stores the two access switches in BCONFIG and sets SETUP.COMPLETED, which lifts the API lockdown. A switch pinned by an environment variable is still stored (so the value survives the variable being removed) but has no immediate effect; GET /state reports which ones those are.',
        tags: ['Setup']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['registrationEnabled', 'guestChatEnabled'],
            properties: [
                new OA\Property(property: 'registrationEnabled', type: 'boolean', example: false, description: 'Allow visitors to create their own account.'),
                new OA\Property(property: 'guestChatEnabled', type: 'boolean', example: false, description: 'Allow visitors to try the chat without signing in.'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Setup completed',
        content: new OA\JsonContent(
            required: ['success', 'registrationEnabled', 'guestChatEnabled'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'registrationEnabled', type: 'boolean', example: false, description: 'The value now in force, which differs from the request when an environment variable pins it.'),
                new OA\Property(property: 'guestChatEnabled', type: 'boolean', example: false),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Not an administrator')]
    public function complete(#[MapRequestPayload] SetupCompleteRequest $dto): JsonResponse
    {
        $this->registrationConfig->store($dto->registrationEnabled);
        $this->guestChatConfig->store($dto->guestChatEnabled);
        $this->setupState->markCompleted();

        $this->logger->notice('First-run setup completed', [
            'registration_enabled' => $dto->registrationEnabled,
            'guest_chat_enabled' => $dto->guestChatEnabled,
        ]);

        return $this->json([
            'success' => true,
            'registrationEnabled' => $this->registrationConfig->isEnabled(),
            'guestChatEnabled' => $this->guestChatConfig->isEnabled(),
        ]);
    }

    private function alreadyCompleted(): JsonResponse
    {
        return $this->json([
            'error' => 'Setup is already complete',
            'code' => 'SETUP_ALREADY_COMPLETED',
            'message' => 'This instance already has accounts. Sign in, or reset an administrator password with `php bin/console app:admin:reset-password`.',
        ], Response::HTTP_CONFLICT);
    }

    /**
     * The only retriable of the three conflicts: a second tab, or a second
     * container in a rollout, arriving while the administrator is being created.
     * Nothing is wrong and nothing is lost — the account the other request is
     * creating is the one to sign in with a moment later.
     */
    private function setupInProgress(): JsonResponse
    {
        return $this->json([
            'error' => 'Setup is already in progress',
            'code' => 'SETUP_IN_PROGRESS',
            'message' => 'The first administrator is already being created. Wait a moment, then reload this page.',
        ], Response::HTTP_CONFLICT);
    }

    /**
     * The kill switch also lands here, but "already has accounts" would be a lie
     * on the installation it is meant for: an SSO/OIDC instance runs with an
     * empty user table on purpose, and an operator reading this needs to know
     * which of the two situations they are in.
     */
    private function wizardDisabled(): JsonResponse
    {
        return $this->json([
            'error' => 'Setup wizard is disabled',
            'code' => 'SETUP_WIZARD_DISABLED',
            'message' => 'SETUP_WIZARD_ENABLED=false on this instance. Administrators come from the identity provider (OIDC_ADMIN_ROLES) or from `php bin/console app:admin:reset-password --promote`.',
        ], Response::HTTP_CONFLICT);
    }

    /**
     * Sign the new administrator in immediately. Without this the wizard would
     * have to bounce through the login page between step 1 and step 2, and step 2
     * writes a provider key through the admin API.
     */
    private function signIn(User $user, Request $request): JsonResponse
    {
        $accessToken = $this->tokenService->generateAccessToken($user);
        $refreshToken = $this->tokenService->generateRefreshToken($user, $request->getClientIp());

        $payload = [
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getMail(),
                'level' => $user->getUserLevel(),
                'isAdmin' => $user->isAdmin(),
            ],
        ];

        // Mirrors AuthController::login(): the native shell cannot rely on
        // cross-origin cookies and authenticates with Authorization: Bearer.
        if ($this->clientContextResolver->fromRequest($request)->isMobileApp) {
            $payload['tokens'] = [
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'tokenType' => 'Bearer',
                'expiresIn' => TokenService::ACCESS_TOKEN_TTL,
            ];
        }

        $response = new JsonResponse($payload, Response::HTTP_CREATED);
        $this->tokenService->addAuthCookies($response, $accessToken, $refreshToken);

        return $response;
    }
}
