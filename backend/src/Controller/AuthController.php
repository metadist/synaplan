<?php

namespace App\Controller;

use App\DTO\RegisterRequest;
use App\Entity\EmailVerificationAttempt;
use App\Entity\User;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\InternalEmailService;
use App\Service\RecaptchaService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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
use OpenApi\Attributes as OA;

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
        private JWTTokenManagerInterface $jwtManager,
        private InternalEmailService $internalEmailService,
        private RecaptchaService $recaptchaService,
        private ValidatorInterface $validator,
        private LoggerInterface $logger
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
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'SecurePass123!')
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'User registered successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'User registered successfully'),
                new OA\Property(property: 'user_id', type: 'integer', example: 123)
            ]
        )
    )]
    #[OA\Response(response: 409, description: 'Email already registered')]
    #[OA\Response(response: 400, description: 'Validation error')]
    public function register(
        #[MapRequestPayload] RegisterRequest $dto,
        Request $request
    ): JsonResponse {
        // Verify reCAPTCHA
        $recaptchaToken = $request->request->get('recaptchaToken') ?? $request->toArray()['recaptchaToken'] ?? '';
        if (!$this->recaptchaService->verify($recaptchaToken, 'register', $request->getClientIp())) {
            return $this->json([
                'error' => 'reCAPTCHA verification failed. Please try again.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if user exists
        if ($this->userRepository->findOneBy(['mail' => $dto->email])) {
            return $this->json([
                'error' => 'Email already registered'
            ], Response::HTTP_CONFLICT);
        }

        // Create user
        $user = new User();
        $user->setMail($dto->email);
        $user->setPw($this->passwordHasher->hashPassword($user, $dto->password));
        $user->setCreated(date('YmdHis'));
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
            $this->internalEmailService->sendVerificationEmail($user->getMail(), $token->getToken());
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
        }

        $this->logger->info('User registered', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Registration successful. Please check your email to verify your account.',
            'userId' => $user->getId()
        ], Response::HTTP_CREATED);
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'User login',
        description: 'Authenticate user and receive JWT token',
        tags: ['Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'SecurePass123!'),
                new OA\Property(property: 'recaptchaToken', type: 'string', description: 'Google reCAPTCHA v3 token (required in production)', example: '03AGdBq27...')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Login successful',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGc...'),
                new OA\Property(property: 'user', type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 123),
                    new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                    new OA\Property(property: 'email_verified', type: 'boolean', example: true)
                ])
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Invalid credentials')]
    #[OA\Response(response: 403, description: 'Email not verified')]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $recaptchaToken = $data['recaptchaToken'] ?? '';

        // Verify reCAPTCHA
        if (!$this->recaptchaService->verify($recaptchaToken, 'login', $request->getClientIp())) {
            return $this->json([
                'error' => 'reCAPTCHA verification failed. Please try again.'
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

        // Check email verification
        if (!$user->isEmailVerified()) {
            return $this->json([
                'error' => 'Email not verified',
                'message' => 'Please verify your email before logging in'
            ], Response::HTTP_FORBIDDEN);
        }

        $token = $this->jwtManager->create($user);

        $this->logger->info('User logged in', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getMail(),
                'level' => $user->getUserLevel(),
                'emailVerified' => $user->isEmailVerified(),
            ]
        ]);
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
            $this->internalEmailService->sendWelcomeEmail($user->getMail(), $user->getMail());
        } catch (\Exception $e) {
            $this->logger->error('Failed to send welcome email', ['user_id' => $user->getId()]);
        }

        $this->logger->info('Email verified', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Email verified successfully'
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
            // Don't reveal if user exists
            return $this->json([
                'success' => true,
                'message' => 'If email exists, reset instructions sent'
            ]);
        }

        // Generate reset token (1 hour expiry)
        $token = $this->tokenRepository->createToken($user, 'password_reset', 3600);

        try {
            $this->internalEmailService->sendPasswordResetEmail($user->getMail(), $token->getToken());
        } catch (\Exception $e) {
            $this->logger->error('Failed to send reset email', ['user_id' => $user->getId()]);
        }

        $this->logger->info('Password reset requested', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'If email exists, reset instructions sent'
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

        // Validate password
        if (strlen($newPassword) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters'], Response::HTTP_BAD_REQUEST);
        }

        $token = $this->tokenRepository->findValidToken($tokenString, 'password_reset');

        if (!$token) {
            return $this->json(['error' => 'Invalid or expired token'], Response::HTTP_BAD_REQUEST);
        }

        $user = $token->getUser();
        $user->setPw($this->passwordHasher->hashPassword($user, $newPassword));
        $this->tokenRepository->markAsUsed($token);
        $this->em->flush();

        $this->logger->info('Password reset', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Password reset successfully'
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

        // ALWAYS check rate limiting first (prevent spam with any email)
        $attempt = $this->attemptRepository->findByEmail($email);
        
        if (!$attempt) {
            // First attempt - create tracking
            $attempt = new EmailVerificationAttempt();
            $attempt->setEmail($email);
            $attempt->setIpAddress($request->getClientIp());
            $this->em->persist($attempt);
        } else {
            // Check if can resend (applies to ALL requests, not just valid users)
            if (!$attempt->canResend($this->resendCooldownMinutes, $this->maxResendAttempts)) {
                $remainingAttempts = $attempt->getRemainingAttempts($this->maxResendAttempts);
                
                if ($remainingAttempts <= 0) {
                    $this->logger->warning('Max resend attempts reached', [
                        'email' => $email,
                        'ip' => $request->getClientIp()
                    ]);
                    
                    return $this->json([
                        'error' => 'Maximum verification attempts reached',
                        'message' => 'You have reached the maximum number of verification email requests. Please contact support if you need assistance.',
                        'maxAttemptsReached' => true
                    ], Response::HTTP_TOO_MANY_REQUESTS);
                }
                
                $nextAvailable = $attempt->getNextAvailableAt($this->resendCooldownMinutes);
                $waitSeconds = $nextAvailable->getTimestamp() - (new \DateTime())->getTimestamp();
                
                return $this->json([
                    'error' => 'Please wait before requesting another verification email',
                    'waitSeconds' => max(0, $waitSeconds),
                    'remainingAttempts' => $remainingAttempts,
                    'nextAvailableAt' => $nextAvailable->format('c')
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }
            
            // Increment attempts
            $attempt->incrementAttempts();
        }

        // NOW check if user exists and is unverified
        $user = $this->userRepository->findOneBy(['mail' => $email]);

        // User doesn't exist or is already verified - increment attempt but return generic message
        if (!$user || $user->isEmailVerified()) {
            // Save attempt tracking even for invalid requests
            try {
                $this->em->flush();
            } catch (\Exception $e) {
                $this->logger->error('Failed to save rate limit attempt', ['error' => $e->getMessage()]);
            }
            
            $this->logger->info('Resend verification requested for non-existent or verified user', [
                'email' => $email,
                'ip' => $request->getClientIp()
            ]);
            
            // Security: Don't reveal if user exists or is verified
            return $this->json([
                'success' => true,
                'message' => 'If your email is registered and unverified, you will receive a verification email.'
            ]);
        }

        // User exists and is unverified - create token and send email
        try {
            $token = $this->tokenRepository->createToken($user, 'email_verification', 86400);
            $this->internalEmailService->sendVerificationEmail($user->getMail(), $token->getToken());
            
            // Only flush after successful email send
            $this->em->flush();
            
            $this->logger->info('Verification email sent successfully', [
                'user_id' => $user->getId(),
                'attempt' => $attempt->getAttempts()
            ]);
            
            return $this->json([
                'success' => true,
                'message' => 'Verification email sent successfully',
                'remainingAttempts' => $attempt->getRemainingAttempts($this->maxResendAttempts),
                'cooldownMinutes' => $this->resendCooldownMinutes
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'user_id' => $user->getId(),
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            
            return $this->json([
                'error' => 'Technical error',
                'message' => 'An error occurred while sending the verification email. Please try again later.'
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
            ]
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        return $this->json(['success' => true, 'message' => 'Logged out']);
    }
}
