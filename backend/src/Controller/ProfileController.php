<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ChatRepository;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\FileRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\MessageRepository;
use App\Repository\RagDocumentRepository;
use App\Repository\SessionRepository;
use App\Repository\TokenRepository;
use App\Repository\UseLogRepository;
use App\Repository\VerificationTokenRepository;
use App\Repository\WidgetRepository;
use App\Service\EmailChatService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/profile', name: 'api_profile_')]
#[OA\Tag(name: 'Profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailChatService $emailChatService,
        private LoggerInterface $logger,
        private VerificationTokenRepository $verificationTokenRepository,
        private TokenRepository $tokenRepository,
        private ApiKeyRepository $apiKeyRepository,
        private SessionRepository $sessionRepository,
        private RagDocumentRepository $ragDocumentRepository,
        private UseLogRepository $useLogRepository,
        private WidgetRepository $widgetRepository,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private EmailVerificationAttemptRepository $emailVerificationAttemptRepository,
        private FileRepository $fileRepository,
        private InboundEmailHandlerRepository $inboundEmailHandlerRepository
    ) {}

    #[Route('', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/profile',
        summary: 'Get user profile',
        description: 'Returns authenticated user profile information',
        security: [['Bearer' => []]],
        tags: ['Profile']
    )]
    #[OA\Response(
        response: 200,
        description: 'User profile',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'profile',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                        new OA\Property(property: 'firstName', type: 'string', example: 'John'),
                        new OA\Property(property: 'lastName', type: 'string', example: 'Doe'),
                        new OA\Property(property: 'phone', type: 'string', example: '+49123456789'),
                        new OA\Property(property: 'companyName', type: 'string', example: 'Acme Inc'),
                        new OA\Property(property: 'vatId', type: 'string', example: 'DE123456789'),
                        new OA\Property(property: 'street', type: 'string', example: 'Main St 123'),
                        new OA\Property(property: 'zipCode', type: 'string', example: '12345'),
                        new OA\Property(property: 'city', type: 'string', example: 'Berlin'),
                        new OA\Property(property: 'country', type: 'string', example: 'Germany'),
                        new OA\Property(property: 'language', type: 'string', example: 'en'),
                        new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Berlin'),
                        new OA\Property(property: 'invoiceEmail', type: 'string', example: 'billing@example.com'),
                        new OA\Property(property: 'emailKeyword', type: 'string', nullable: true, example: 'myproject'),
                        new OA\Property(property: 'personalEmailAddress', type: 'string', example: 'smart+myproject@synaplan.com')
                    ]
                )
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getProfile(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $details = $user->getUserDetails();
        $emailKeyword = $this->emailChatService->getUserEmailKeyword($user);
        $personalEmailAddress = $this->emailChatService->getUserPersonalEmailAddress($user);

        // Get external auth info if applicable
        $externalAuthInfo = null;
        if ($user->isExternalAuth()) {
            $type = $user->getType();
            $lastLoginKey = match($type) {
                'GOOGLE' => 'google_last_login',
                'GITHUB' => 'github_last_login',
                'OIDC' => 'oidc_last_login',
                default => null
            };
            
            if ($lastLoginKey && isset($details[$lastLoginKey])) {
                $externalAuthInfo = [
                    'lastLogin' => $details[$lastLoginKey]
                ];
            }
        }

        return $this->json([
            'success' => true,
            'profile' => [
                'email' => $user->getMail(),
                'firstName' => $details['firstName'] ?? $details['first_name'] ?? '',
                'lastName' => $details['lastName'] ?? $details['last_name'] ?? '',
                'phone' => $details['phone'] ?? '',
                'companyName' => $details['companyName'] ?? '',
                'vatId' => $details['vatId'] ?? '',
                'street' => $details['street'] ?? '',
                'zipCode' => $details['zipCode'] ?? '',
                'city' => $details['city'] ?? '',
                'country' => $details['country'] ?? '',
                'language' => $details['language'] ?? 'en',
                'timezone' => $details['timezone'] ?? '',
                'invoiceEmail' => $details['invoiceEmail'] ?? '',
                'emailKeyword' => $emailKeyword,
                'personalEmailAddress' => $personalEmailAddress,
                'canChangePassword' => $user->canChangePassword(),
                'authProvider' => $user->getAuthProviderName(),
                'isExternalAuth' => $user->isExternalAuth(),
                'externalAuthInfo' => $externalAuthInfo,
                'isAdmin' => $user->isAdmin()
            ]
        ]);
    }

    #[Route('', name: 'update', methods: ['PUT', 'PATCH'])]
    #[OA\Put(
        path: '/api/v1/profile',
        summary: 'Update user profile',
        description: 'Update authenticated user profile information',
        security: [['Bearer' => []]],
        tags: ['Profile']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'firstName', type: 'string', example: 'John'),
                new OA\Property(property: 'lastName', type: 'string', example: 'Doe'),
                new OA\Property(property: 'phone', type: 'string', example: '+49123456789'),
                new OA\Property(property: 'companyName', type: 'string', example: 'Acme Inc'),
                new OA\Property(property: 'vatId', type: 'string', example: 'DE123456789'),
                new OA\Property(property: 'street', type: 'string', example: 'Main St 123'),
                new OA\Property(property: 'zipCode', type: 'string', example: '12345'),
                new OA\Property(property: 'city', type: 'string', example: 'Berlin'),
                new OA\Property(property: 'country', type: 'string', example: 'Germany'),
                new OA\Property(property: 'language', type: 'string', example: 'en'),
                new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Berlin'),
                new OA\Property(property: 'invoiceEmail', type: 'string', example: 'billing@example.com'),
                new OA\Property(property: 'emailKeyword', type: 'string', nullable: true, example: 'myproject')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Profile updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Profile updated successfully')
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 400, description: 'Invalid JSON')]
    public function updateProfile(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Get current details
        $details = $user->getUserDetails();

        // Update allowed fields
        $allowedFields = [
            'firstName', 'lastName', 'phone', 'companyName', 'vatId',
            'street', 'zipCode', 'city', 'country', 'language', 'timezone', 'invoiceEmail'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $details[$field] = $data[$field];
            }
        }

        // Handle email keyword separately (uses EmailChatService)
        if (isset($data['emailKeyword'])) {
            $keyword = $data['emailKeyword'];
            if (empty($keyword) || trim($keyword) === '') {
                // Remove keyword if empty
                $details['email_keyword'] = null;
                $user->setUserDetails($details);
            } else {
                try {
                    $this->emailChatService->setUserEmailKeyword($user, $keyword);
                } catch (\InvalidArgumentException $e) {
                    return $this->json([
                        'error' => 'Invalid email keyword format. Only lowercase letters, numbers, hyphens, and underscores are allowed.'
                    ], Response::HTTP_BAD_REQUEST);
                }
            }
        }

        $user->setUserDetails($details);
        $this->em->flush();

        $this->logger->info('Profile updated', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Profile updated successfully'
        ]);
    }

    #[Route('/password', name: 'change_password', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/profile/password',
        summary: 'Change user password',
        description: 'Change authenticated user password (requires current password)',
        security: [['Bearer' => []]],
        tags: ['Profile']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['currentPassword', 'newPassword'],
            properties: [
                new OA\Property(property: 'currentPassword', type: 'string', format: 'password', example: 'OldPass123!'),
                new OA\Property(property: 'newPassword', type: 'string', format: 'password', example: 'NewSecurePass123!')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Password changed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Password changed successfully')
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Current password is incorrect')]
    #[OA\Response(response: 400, description: 'Invalid password format or missing fields')]
    public function changePassword(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Block password change for external authentication users (OAuth, OIDC)
        if (!$user->canChangePassword()) {
            $provider = $user->getAuthProviderName();
            return $this->json([
                'error' => "Password cannot be changed for {$provider} accounts. Please manage your password through {$provider}."
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        $currentPassword = $data['currentPassword'] ?? '';
        $newPassword = $data['newPassword'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            return $this->json([
                'error' => 'Current password and new password required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verify current password
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json([
                'error' => 'Current password is incorrect'
            ], Response::HTTP_FORBIDDEN);
        }

        // Validate new password
        if (strlen($newPassword) < 8) {
            return $this->json([
                'error' => 'New password must be at least 8 characters'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $newPassword)) {
            return $this->json([
                'error' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Update password
        $user->setPw($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->flush();

        $this->logger->info('Password changed', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    #[Route('/email-keyword', name: 'get_email_keyword', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/profile/email-keyword',
        summary: 'Get user email keyword',
        description: 'Returns the user\'s email keyword for smart+keyword@synaplan.com',
        security: [['Bearer' => []]],
        tags: ['Profile']
    )]
    #[OA\Response(
        response: 200,
        description: 'Email keyword',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'keyword', type: 'string', nullable: true, example: 'myproject'),
                new OA\Property(property: 'emailAddress', type: 'string', example: 'smart+myproject@synaplan.com')
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getEmailKeyword(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $keyword = $this->emailChatService->getUserEmailKeyword($user);
        $emailAddress = $this->emailChatService->getUserPersonalEmailAddress($user);

        return $this->json([
            'success' => true,
            'keyword' => $keyword,
            'emailAddress' => $emailAddress
        ]);
    }

    #[Route('/email-keyword', name: 'set_email_keyword', methods: ['PUT', 'POST'])]
    #[OA\Put(
        path: '/api/v1/profile/email-keyword',
        summary: 'Set user email keyword',
        description: 'Set or update the user\'s email keyword for smart+keyword@synaplan.com',
        security: [['Bearer' => []]],
        tags: ['Profile']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['keyword'],
            properties: [
                new OA\Property(property: 'keyword', type: 'string', example: 'myproject', description: 'Keyword (lowercase letters, numbers, hyphens, underscores only). Empty string to remove.')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Email keyword updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Email keyword updated successfully'),
                new OA\Property(property: 'keyword', type: 'string', nullable: true, example: 'myproject'),
                new OA\Property(property: 'emailAddress', type: 'string', example: 'smart+myproject@synaplan.com')
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 400, description: 'Invalid keyword format')]
    public function setEmailKeyword(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['keyword'])) {
            return $this->json(['error' => 'Keyword is required'], Response::HTTP_BAD_REQUEST);
        }

        $keyword = trim($data['keyword']);

        // If empty, remove keyword
        if (empty($keyword)) {
            $details = $user->getUserDetails();
            unset($details['email_keyword']);
            $user->setUserDetails($details);
            $this->em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Email keyword removed successfully',
                'keyword' => null,
                'emailAddress' => $this->emailChatService->getUserPersonalEmailAddress($user)
            ]);
        }

        try {
            $this->emailChatService->setUserEmailKeyword($user, $keyword);
            $emailAddress = $this->emailChatService->getUserPersonalEmailAddress($user);

            return $this->json([
                'success' => true,
                'message' => 'Email keyword updated successfully',
                'keyword' => $keyword,
                'emailAddress' => $emailAddress
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => 'Invalid keyword format. Only lowercase letters, numbers, hyphens, and underscores are allowed.'
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'delete_account', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/profile',
        summary: 'Delete user account',
        description: 'Permanently delete the authenticated user account (requires password confirmation)',
        security: [['Bearer' => []]],
        tags: ['Profile']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['password'],
            properties: [
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'CurrentPassword123!', description: 'Current password for confirmation')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Account deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Account deleted successfully')
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Incorrect password or external auth user')]
    #[OA\Response(response: 400, description: 'Password required')]
    public function deleteAccount(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? '';

        if (empty($password)) {
            return $this->json([
                'error' => 'Password confirmation required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // For external auth users (OAuth, OIDC), we cannot verify password
        if ($user->isExternalAuth()) {
            // External auth users don't have a password
            // We could allow deletion without password check, or require special confirmation
            // For security, let's allow it but log it
            $this->logger->warning('External auth user deleted account', [
                'user_id' => $user->getId(),
                'email' => $user->getMail(),
                'provider' => $user->getAuthProviderName()
            ]);
        } else {
            // Verify password for local auth users
            if (!$this->passwordHasher->isPasswordValid($user, $password)) {
                usleep(100000); // Timing attack prevention
                return $this->json([
                    'error' => 'Incorrect password'
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Log account deletion
        $this->logger->info('User account deletion initiated', [
            'user_id' => $user->getId(),
            'email' => $user->getMail(),
            'type' => $user->getType()
        ]);

        $userId = $user->getId();

        try {
            // Delete all related entities to avoid foreign key constraint violations
            
            // 1. Delete verification tokens
            $verificationTokens = $this->verificationTokenRepository->findBy(['userId' => $userId]);
            foreach ($verificationTokens as $token) {
                $this->em->remove($token);
            }

            // 2. Delete authentication tokens
            $tokens = $this->tokenRepository->findBy(['userId' => $userId]);
            foreach ($tokens as $token) {
                $this->em->remove($token);
            }

            // 3. Delete API keys
            $apiKeys = $this->apiKeyRepository->findBy(['ownerId' => $userId]);
            foreach ($apiKeys as $apiKey) {
                $this->em->remove($apiKey);
            }

            // 4. Delete sessions
            $sessions = $this->sessionRepository->findBy(['userId' => $userId]);
            foreach ($sessions as $session) {
                $this->em->remove($session);
            }

            // 5. Delete RAG documents
            $ragDocs = $this->ragDocumentRepository->findBy(['userId' => $userId]);
            foreach ($ragDocs as $ragDoc) {
                $this->em->remove($ragDoc);
            }

            // 6. Delete use logs
            $useLogs = $this->useLogRepository->findBy(['userId' => $userId]);
            foreach ($useLogs as $useLog) {
                $this->em->remove($useLog);
            }

            // 7. Delete widgets
            $widgets = $this->widgetRepository->findBy(['ownerId' => $userId]);
            foreach ($widgets as $widget) {
                $this->em->remove($widget);
            }

            // 8. Delete chats (this will cascade to messages)
            $chats = $this->chatRepository->findBy(['userId' => $userId]);
            foreach ($chats as $chat) {
                $this->em->remove($chat);
            }

            // 9. Delete messages (in case there are orphaned messages)
            $messages = $this->messageRepository->findBy(['userId' => $userId]);
            foreach ($messages as $message) {
                $this->em->remove($message);
            }

            // 10. Delete email verification attempts
            $emailAttempts = $this->emailVerificationAttemptRepository->findBy(['email' => $user->getMail()]);
            foreach ($emailAttempts as $attempt) {
                $this->em->remove($attempt);
            }

            // 11. Delete files
            $files = $this->fileRepository->findBy(['userId' => $userId]);
            foreach ($files as $file) {
                // TODO: Also delete physical files from storage
                $this->em->remove($file);
            }

            // 12. Delete inbound email handlers
            $emailHandlers = $this->inboundEmailHandlerRepository->findBy(['userId' => $userId]);
            foreach ($emailHandlers as $handler) {
                $this->em->remove($handler);
            }

            // Finally, delete the user account
            $this->em->remove($user);
            $this->em->flush();

            $this->logger->info('User account and all related data deleted successfully', [
                'user_id' => $userId,
                'email' => $user->getMail()
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Account deleted successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete user account', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'error' => 'Failed to delete account. Please contact support.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

