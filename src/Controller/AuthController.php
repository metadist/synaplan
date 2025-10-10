<?php

namespace App\Controller;

use App\DTO\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\MailerService;
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

#[Route('/api/v1/auth', name: 'api_auth_')]
class AuthController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private VerificationTokenRepository $tokenRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private JWTTokenManagerInterface $jwtManager,
        private MailerService $mailerService,
        private ValidatorInterface $validator,
        private LoggerInterface $logger
    ) {}

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterRequest $dto
    ): JsonResponse {
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
            $this->mailerService->sendVerificationEmail($user->getMail(), $token->getToken());
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
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

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
            $this->mailerService->sendWelcomeEmail($user->getMail(), $user->getMail());
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
            $this->mailerService->sendPasswordResetEmail($user->getMail(), $token->getToken());
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

        $user = $this->userRepository->findOneBy(['mail' => $email]);

        if (!$user || $user->isEmailVerified()) {
            return $this->json([
                'success' => true,
                'message' => 'Verification email sent if account exists'
            ]);
        }

        $token = $this->tokenRepository->createToken($user, 'email_verification', 86400);

        try {
            $this->mailerService->sendVerificationEmail($user->getMail(), $token->getToken());
        } catch (\Exception $e) {
            $this->logger->error('Failed to resend verification', ['user_id' => $user->getId()]);
        }

        return $this->json([
            'success' => true,
            'message' => 'Verification email sent if account exists'
        ]);
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
