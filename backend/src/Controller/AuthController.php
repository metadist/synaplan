<?php

namespace App\Controller;

use App\DTO\RegisterRequest;
use App\Entity\EmailVerificationAttempt;
use App\Entity\User;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\InternalEmailService;
use App\Service\OidcTokenService;
use App\Service\RecaptchaService;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth', name: 'api_auth_')]
#[OA\Tag(name: 'Authentication')]
class AuthController extends AbstractController
{
    private int $resendCooldownMinutes;
    private int $maxResendAttempts;

    public function __construct(
        private UserRepository $userRepository,
        private VerificationTokenRepository $tokenRepository,
        private EmailVerificationAttemptRepository $attemptRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private TokenService $tokenService,
        private OidcTokenService $oidcTokenService,
        private InternalEmailService $internalEmailService,
        private RecaptchaService $recaptchaService,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
    ) {
        $this->resendCooldownMinutes = (int) ($_ENV['EMAIL_VERIFICATION_COOLDOWN_MINUTES'] ?? 2);
        $this->maxResendAttempts = (int) ($_ENV['EMAIL_VERIFICATION_MAX_ATTEMPTS'] ?? 5);
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'Register a new user',
        description: 'Create a new user account and send verification email',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'SecurePass123!'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'User registered successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'User registered successfully'),
                new OA\Property(property: 'user_id', type: 'integer', example: 123),
            ]
        )
    )]
    #[OA\Response(response: 409, description: 'Email already registered')]
    #[OA\Response(response: 400, description: 'Validation error')]
    public function register(
        #[MapRequestPayload] RegisterRequest $dto,
        Request $request,
    ): JsonResponse {
        // Verify reCAPTCHA
        $recaptchaToken = $request->request->get('recaptchaToken') ?? $request->toArray()['recaptchaToken'] ?? '';
        if (!$this->recaptchaService->verify($recaptchaToken, 'register', $request->getClientIp())) {
            return $this->json([
                'error' => 'reCAPTCHA verification failed. Please try again.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if user exists
        $existingUser = $this->userRepository->findOneBy(['mail' => $dto->email]);

        if ($existingUser) {
            // Security: Don't reveal that email is already registered
            $this->logger->warning('Registration attempt with existing email', [
                'email' => $dto->email,
                'ip' => $request->getClientIp(),
            ]);

            return $this->json([
                'success' => true,
                'message' => 'If this email is not already registered, you will receive a verification email shortly.',
            ], Response::HTTP_OK);
        }

        // Create user
        $user = new User();
        $user->setMail($dto->email);
        $user->setPw($this->passwordHasher->hashPassword($user, $dto->password));
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setType('WEB');
        $user->setUserLevel('NEW');
        $user->setEmailVerified(false);
        $user->setProviderId('local');

        $this->em->persist($user);
        $this->em->flush();

        // Generate verification token
        $token = $this->tokenRepository->createToken($user, 'email_verification', 86400); // 24h

        // Send verification email
        try {
            $this->internalEmailService->sendVerificationEmail(
                $user->getMail(),
                $token->getToken(),
                $user->getLocale()
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('User registered', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'If this email is not already registered, you will receive a verification email shortly.',
        ], Response::HTTP_OK);
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'User login',
        description: 'Authenticate user and receive auth cookies (HttpOnly)',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'SecurePass123!'),
                new OA\Property(property: 'recaptchaToken', type: 'string', description: 'Google reCAPTCHA v3 token', example: '03AGdBq27...'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Login successful - cookies set',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'user', type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 123),
                    new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                    new OA\Property(property: 'level', type: 'string', example: 'NEW'),
                    new OA\Property(property: 'isAdmin', type: 'boolean', example: false),
                    new OA\Property(property: 'memoriesEnabled', type: 'boolean', example: true),
                ]),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Invalid credentials')]
    #[OA\Response(response: 403, description: 'Email not verified or external auth required')]
    public function login(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $recaptchaToken = $data['recaptchaToken'] ?? '';

        // Verify reCAPTCHA
        if (!$this->recaptchaService->verify($recaptchaToken, 'login', $request->getClientIp())) {
            return $this->json([
                'error' => 'reCAPTCHA verification failed. Please try again.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($email) || empty($password)) {
            return $this->json(['error' => 'Email and password required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findOneBy(['mail' => $email]);

        if (!$user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            usleep(100000); // Timing attack prevention

            return $this->json(['error' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        // Check if user registered with external auth (Google, GitHub, Keycloak)
        if ($user->isExternalAuth()) {
            $this->logger->warning('Login attempt with password for external auth user', [
                'email' => $email,
                'auth_provider' => $user->getAuthProviderName(),
            ]);

            return $this->json([
                'error' => sprintf('This account uses %s for authentication. Please use the "%s" button to sign in.',
                    $user->getAuthProviderName(),
                    $user->getAuthProviderName()
                ),
            ], Response::HTTP_FORBIDDEN);
        }

        // Check if user is an OAuth user (no password set)
        if (null === $user->getPw()) {
            return $this->json([
                'error' => 'OAuth account',
                'message' => 'This account uses social login. Please use Google, GitHub, or Keycloak to sign in.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Check email verification
        if (!$user->isEmailVerified()) {
            return $this->json([
                'error' => 'Email not verified',
                'message' => 'Please verify your email before logging in',
            ], Response::HTTP_FORBIDDEN);
        }

        // Generate tokens
        $accessToken = $this->tokenService->generateAccessToken($user);
        $refreshToken = $this->tokenService->generateRefreshToken($user, $request->getClientIp());

        $this->logger->info('User logged in', ['user_id' => $user->getId()]);

        // Create response with cookies
        $response = new JsonResponse([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getMail(),
                'level' => $user->getUserLevel(),
                'emailVerified' => $user->isEmailVerified(),
                'isAdmin' => $user->isAdmin(),
                'memoriesEnabled' => $user->isMemoriesEnabled(),
            ],
        ]);

        // Add HttpOnly cookies
        return $this->tokenService->addAuthCookies($response, $accessToken, $refreshToken);
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/refresh',
        summary: 'Refresh access token',
        description: 'Use refresh token cookie to get a new access token. For OIDC users, refreshes against the identity provider.',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 200,
        description: 'Token refreshed - new access token cookie set',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Invalid or expired refresh token')]
    public function refresh(Request $request): Response
    {
        // Check if this is an OIDC user (has OIDC refresh token cookie)
        $oidcRefreshToken = $request->cookies->get(OidcTokenService::OIDC_REFRESH_COOKIE);
        $oidcProvider = $request->cookies->get(OidcTokenService::OIDC_PROVIDER_COOKIE);

        if ($oidcRefreshToken && $oidcProvider) {
            // OIDC user - refresh against the identity provider (Keycloak)
            return $this->refreshOidcTokens($request, $oidcRefreshToken, $oidcProvider);
        }

        // Regular user - use our internal refresh token
        $refreshTokenString = $request->cookies->get(TokenService::REFRESH_COOKIE);

        if (!$refreshTokenString) {
            return $this->json([
                'error' => 'No refresh token',
                'code' => 'NO_REFRESH_TOKEN',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->tokenService->refreshTokens($refreshTokenString);

        if (!$result) {
            $response = new JsonResponse([
                'error' => 'Invalid or expired refresh token',
                'code' => 'INVALID_REFRESH_TOKEN',
            ], Response::HTTP_UNAUTHORIZED);

            return $this->tokenService->clearAuthCookies($response);
        }

        $this->logger->info('Token refreshed', ['user_id' => $result['user']->getId()]);

        $response = new JsonResponse([
            'success' => true,
            'user' => [
                'id' => $result['user']->getId(),
                'email' => $result['user']->getMail(),
                'level' => $result['user']->getUserLevel(),
                'emailVerified' => $result['user']->isEmailVerified(),
                'isAdmin' => $result['user']->isAdmin(),
            ],
        ]);

        $response->headers->setCookie(
            $this->tokenService->createAccessCookie($result['access_token'])
        );

        return $response;
    }

    /**
     * Refresh OIDC tokens against identity provider (Keycloak).
     * If Keycloak rejects the refresh (user logged out), session is terminated.
     */
    private function refreshOidcTokens(Request $request, string $oidcRefreshToken, string $provider): Response
    {
        $newTokens = $this->oidcTokenService->refreshOidcTokens($oidcRefreshToken, $provider);

        if (!$newTokens) {
            // Keycloak rejected the refresh - user was logged out from Keycloak
            $this->logger->info('OIDC refresh rejected - user logged out from provider', [
                'provider' => $provider,
            ]);

            $response = new JsonResponse([
                'error' => 'Session expired',
                'code' => 'OIDC_SESSION_EXPIRED',
                'message' => 'Your session has expired. Please log in again.',
            ], Response::HTTP_UNAUTHORIZED);

            // Clear all auth cookies
            $this->tokenService->clearAuthCookies($response);
            $this->oidcTokenService->clearOidcCookies($response);

            return $response;
        }

        // Get user from the new access token
        $user = $this->oidcTokenService->getUserFromOidcToken($newTokens['access_token'], $provider);

        if (!$user) {
            $response = new JsonResponse([
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND',
            ], Response::HTTP_UNAUTHORIZED);

            $this->tokenService->clearAuthCookies($response);
            $this->oidcTokenService->clearOidcCookies($response);

            return $response;
        }

        $this->logger->info('OIDC tokens refreshed', [
            'user_id' => $user->getId(),
            'provider' => $provider,
        ]);

        $response = new JsonResponse([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getMail(),
                'level' => $user->getUserLevel(),
                'emailVerified' => $user->isEmailVerified(),
                'isAdmin' => $user->isAdmin(),
            ],
        ]);

        // Update OIDC tokens
        $this->oidcTokenService->storeOidcTokens(
            $response,
            $newTokens['access_token'],
            $newTokens['refresh_token'],
            $newTokens['expires_in'],
            $provider
        );

        // Also update our internal tokens
        $appAccessToken = $this->tokenService->generateAccessToken($user);
        $response->headers->setCookie(
            $this->tokenService->createAccessCookie($appAccessToken)
        );

        return $response;
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'Logout user',
        description: 'Revoke tokens and clear auth cookies. For OIDC users, also revokes tokens at the identity provider and returns logout URL.',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 200,
        description: 'Logged out successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Logged out'),
                new OA\Property(property: 'oidc_logout_url', type: 'string', example: 'https://keycloak.com/logout?...', description: 'Optional: OIDC provider logout URL for frontend redirect'),
            ]
        )
    )]
    public function logout(Request $request): Response
    {
        $refreshTokenString = $request->cookies->get(TokenService::REFRESH_COOKIE);
        $oidcAccessToken = $request->cookies->get(OidcTokenService::OIDC_ACCESS_COOKIE);
        $oidcRefreshToken = $request->cookies->get(OidcTokenService::OIDC_REFRESH_COOKIE);
        $oidcProvider = $request->cookies->get(OidcTokenService::OIDC_PROVIDER_COOKIE);

        // Revoke internal refresh token
        if ($refreshTokenString) {
            $this->tokenService->revokeRefreshToken($refreshTokenString);
        }

        $responseData = [
            'success' => true,
            'message' => 'Logged out',
        ];

        // Handle OIDC logout (Keycloak, Google, etc.)
        if ($oidcAccessToken && $oidcRefreshToken && $oidcProvider) {
            $this->logger->info('OIDC user logout initiated', ['provider' => $oidcProvider]);

            // Revoke tokens at OIDC provider
            $revoked = $this->oidcTokenService->revokeOidcTokens(
                $oidcAccessToken,
                $oidcRefreshToken,
                $oidcProvider
            );

            if ($revoked) {
                $this->logger->info('OIDC tokens revoked successfully', ['provider' => $oidcProvider]);
            }

            // Get end session URL for frontend redirect
            // Must point to /logged-out (not /login) to avoid auto-redirect loop with OIDC_AUTO_REDIRECT
            $frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173', '/');
            $postLogoutRedirectUri = $frontendUrl.'/logged-out';
            $logoutUrl = $this->oidcTokenService->getEndSessionUrl($postLogoutRedirectUri, $oidcProvider);

            if ($logoutUrl) {
                $responseData['oidc_logout_url'] = $logoutUrl;
                $this->logger->debug('OIDC logout URL generated', ['url' => $logoutUrl]);
            }
        }

        $this->logger->info('User logged out');

        // Clear all auth cookies
        $response = new JsonResponse($responseData);
        $this->tokenService->clearAuthCookies($response);
        $this->oidcTokenService->clearOidcCookies($response);

        return $response;
    }

    #[Route('/verify-email', name: 'verify_email', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $tokenString = $data['token'] ?? '';

        if (empty($tokenString)) {
            return $this->json(['error' => 'Token required'], Response::HTTP_BAD_REQUEST);
        }

        $token = $this->tokenRepository->findValidToken($tokenString, 'email_verification');

        if (!$token) {
            return $this->json(['error' => 'Invalid or expired token'], Response::HTTP_BAD_REQUEST);
        }

        $user = $token->getUser();
        $user->setEmailVerified(true);
        $this->tokenRepository->markAsUsed($token);
        $this->em->flush();

        // Send welcome email
        try {
            $details = $user->getUserDetails();
            $userName = trim(($details['first_name'] ?? '').' '.($details['last_name'] ?? ''));
            if (empty($userName)) {
                $userName = $user->getMail();
            }

            $this->internalEmailService->sendWelcomeEmail(
                $user->getMail(),
                $userName,
                $user->getLocale()
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to send welcome email', ['user_id' => $user->getId()]);
        }

        $this->logger->info('Email verified', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Email verified successfully',
        ]);
    }

    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';

        if (empty($email)) {
            return $this->json(['error' => 'Email required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findOneBy(['mail' => $email]);

        if (!$user) {
            return $this->json([
                'success' => true,
                'message' => 'If email exists, reset instructions sent',
            ]);
        }

        // Block password reset for external auth users
        if (!$user->canChangePassword()) {
            return $this->json([
                'success' => true,
                'message' => 'If email exists, reset instructions sent',
            ]);
        }

        // Generate reset token (1 hour expiry)
        $token = $this->tokenRepository->createToken($user, 'password_reset', 3600);

        try {
            $this->internalEmailService->sendPasswordResetEmail(
                $user->getMail(),
                $token->getToken(),
                $user->getLocale()
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to send reset email', ['user_id' => $user->getId()]);
        }

        $this->logger->info('Password reset requested', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'If email exists, reset instructions sent',
        ]);
    }

    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $tokenString = $data['token'] ?? '';
        $newPassword = $data['password'] ?? '';

        if (empty($tokenString) || empty($newPassword)) {
            return $this->json(['error' => 'Token and password required'], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($newPassword) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters'], Response::HTTP_BAD_REQUEST);
        }

        $token = $this->tokenRepository->findValidToken($tokenString, 'password_reset');

        if (!$token) {
            return $this->json(['error' => 'Invalid or expired token'], Response::HTTP_BAD_REQUEST);
        }

        $user = $token->getUser();

        if (!$user->canChangePassword()) {
            $provider = $user->getAuthProviderName();

            return $this->json([
                'error' => "This account is managed by {$provider}. Please use {$provider} to manage your password.",
            ], Response::HTTP_FORBIDDEN);
        }

        $user->setPw($this->passwordHasher->hashPassword($user, $newPassword));
        $this->tokenRepository->markAsUsed($token);
        $this->em->flush();

        // Revoke all existing refresh tokens (force re-login)
        $this->tokenService->revokeAllUserTokens($user);

        $this->logger->info('Password reset', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Password reset successfully',
        ]);
    }

    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';

        if (empty($email)) {
            return $this->json(['error' => 'Email required'], Response::HTTP_BAD_REQUEST);
        }

        // Check rate limiting
        $attempt = $this->attemptRepository->findByEmail($email);

        if (!$attempt) {
            $attempt = new EmailVerificationAttempt();
            $attempt->setEmail($email);
            $attempt->setIpAddress($request->getClientIp());
            $this->em->persist($attempt);
        } else {
            if (!$attempt->canResend($this->resendCooldownMinutes, $this->maxResendAttempts)) {
                $remainingAttempts = $attempt->getRemainingAttempts($this->maxResendAttempts);

                if ($remainingAttempts <= 0) {
                    $this->logger->warning('Max resend attempts reached', [
                        'email' => $email,
                        'ip' => $request->getClientIp(),
                    ]);

                    return $this->json([
                        'error' => 'Maximum verification attempts reached',
                        'message' => 'You have reached the maximum number of verification email requests. Please contact support.',
                        'maxAttemptsReached' => true,
                    ], Response::HTTP_TOO_MANY_REQUESTS);
                }

                $nextAvailable = $attempt->getNextAvailableAt($this->resendCooldownMinutes);
                $waitSeconds = $nextAvailable->getTimestamp() - (new \DateTime())->getTimestamp();

                return $this->json([
                    'error' => 'Please wait before requesting another verification email',
                    'waitSeconds' => max(0, $waitSeconds),
                    'remainingAttempts' => $remainingAttempts,
                    'nextAvailableAt' => $nextAvailable->format('c'),
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }

            $attempt->incrementAttempts();
        }

        $user = $this->userRepository->findOneBy(['mail' => $email]);

        if (!$user || $user->isEmailVerified()) {
            try {
                $this->em->flush();
            } catch (\Exception $e) {
                $this->logger->error('Failed to save rate limit attempt', ['error' => $e->getMessage()]);
            }

            return $this->json([
                'success' => true,
                'message' => 'If your email is registered and unverified, you will receive a verification email.',
            ]);
        }

        try {
            $token = $this->tokenRepository->createToken($user, 'email_verification', 86400);
            $this->internalEmailService->sendVerificationEmail(
                $user->getMail(),
                $token->getToken(),
                $user->getLocale()
            );

            $this->em->flush();

            $this->logger->info('Verification email sent successfully', [
                'user_id' => $user->getId(),
                'attempt' => $attempt->getAttempts(),
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Verification email sent successfully',
                'remainingAttempts' => $attempt->getRemainingAttempts($this->maxResendAttempts),
                'cooldownMinutes' => $this->resendCooldownMinutes,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'user_id' => $user->getId(),
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Technical error',
                'message' => 'An error occurred while sending the verification email. Please try again later.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getMail(),
                'level' => $user->getUserLevel(),
                'emailVerified' => $user->isEmailVerified(),
                'created' => $user->getCreated(),
                'isAdmin' => $user->isAdmin(),
                'memoriesEnabled' => $user->isMemoriesEnabled(),
            ],
        ]);
    }

    #[Route('/token', name: 'get_token', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/auth/token',
        summary: 'Get current access token',
        description: 'Returns the current access token for SSE/EventSource (which cannot send cookies)',
        tags: ['Authentication']
    )]
    #[OA\Response(
        response: 200,
        description: 'Access token returned',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string', example: 'eyJ...'),
            ]
        )
    )]
    public function getToken(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Generate a fresh access token for SSE usage
        $accessToken = $this->tokenService->generateAccessToken($user);

        return $this->json([
            'token' => $accessToken,
        ]);
    }

    #[Route('/revoke-all', name: 'revoke_all', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/revoke-all',
        summary: 'Revoke all sessions',
        description: 'Logout from all devices by revoking all refresh tokens',
        tags: ['Authentication']
    )]
    public function revokeAll(#[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $count = $this->tokenService->revokeAllUserTokens($user);

        $this->logger->info('All sessions revoked', [
            'user_id' => $user->getId(),
            'sessions_revoked' => $count,
        ]);

        $response = new JsonResponse([
            'success' => true,
            'message' => "Logged out from {$count} session(s)",
            'sessions_revoked' => $count,
        ]);

        return $this->tokenService->clearAuthCookies($response);
    }
}
