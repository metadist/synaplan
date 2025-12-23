<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\EmailChatService;
use App\Service\UserDeletionService;
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
        private UserDeletionService $userDeletionService,
        private LoggerInterface $logger,
    ) {
    }

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
                        new OA\Property(property: 'personalEmailAddress', type: 'string', example: 'smart+myproject@synaplan.net'),
                    ]
                ),
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
            $lastLoginKey = match ($type) {
                'GOOGLE' => 'google_last_login',
                'GITHUB' => 'github_last_login',
                'OIDC' => 'oidc_last_login',
                default => null,
            };

            if ($lastLoginKey && isset($details[$lastLoginKey])) {
                $externalAuthInfo = [
                    'lastLogin' => $details[$lastLoginKey],
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
                'isAdmin' => $user->isAdmin(),
            ],
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
                new OA\Property(property: 'emailKeyword', type: 'string', nullable: true, example: 'myproject'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Profile updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Profile updated successfully'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 400, description: 'Invalid JSON')]
    public function updateProfile(
        Request $request,
        #[CurrentUser] ?User $user,
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
            'street', 'zipCode', 'city', 'country', 'language', 'timezone', 'invoiceEmail',
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $details[$field] = $data[$field];
            }
        }

        // Handle email keyword separately (uses EmailChatService)
        if (isset($data['emailKeyword'])) {
            $keyword = $data['emailKeyword'];
            if (empty($keyword) || '' === trim($keyword)) {
                // Remove keyword if empty
                $details['email_keyword'] = null;
                $user->setUserDetails($details);
            } else {
                try {
                    $this->emailChatService->setUserEmailKeyword($user, $keyword);
                } catch (\InvalidArgumentException $e) {
                    return $this->json([
                        'error' => 'Invalid email keyword format. Only lowercase letters, numbers, hyphens, and underscores are allowed.',
                    ], Response::HTTP_BAD_REQUEST);
                }
            }
        }

        $user->setUserDetails($details);
        $this->em->flush();

        $this->logger->info('Profile updated', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Profile updated successfully',
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
                new OA\Property(property: 'newPassword', type: 'string', format: 'password', example: 'NewSecurePass123!'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Password changed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Password changed successfully'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Current password is incorrect')]
    #[OA\Response(response: 400, description: 'Invalid password format or missing fields')]
    public function changePassword(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Block password change for external authentication users (OAuth, OIDC)
        if (!$user->canChangePassword()) {
            $provider = $user->getAuthProviderName();

            return $this->json([
                'error' => "Password cannot be changed for {$provider} accounts. Please manage your password through {$provider}.",
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        $currentPassword = $data['currentPassword'] ?? '';
        $newPassword = $data['newPassword'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            return $this->json([
                'error' => 'Current password and new password required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verify current password
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json([
                'error' => 'Current password is incorrect',
            ], Response::HTTP_FORBIDDEN);
        }

        // Validate new password
        if (strlen($newPassword) < 8) {
            return $this->json([
                'error' => 'New password must be at least 8 characters',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $newPassword)) {
            return $this->json([
                'error' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Update password
        $user->setPw($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->flush();

        $this->logger->info('Password changed', ['user_id' => $user->getId()]);

        return $this->json([
            'success' => true,
            'message' => 'Password changed successfully',
        ]);
    }

    #[Route('/email-keyword', name: 'get_email_keyword', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/profile/email-keyword',
        summary: 'Get user email keyword',
        description: 'Returns the user\'s email keyword for smart+keyword@synaplan.net',
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
                new OA\Property(property: 'emailAddress', type: 'string', example: 'smart+myproject@synaplan.net'),
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
            'emailAddress' => $emailAddress,
        ]);
    }

    #[Route('/email-keyword', name: 'set_email_keyword', methods: ['PUT', 'POST'])]
    #[OA\Put(
        path: '/api/v1/profile/email-keyword',
        summary: 'Set user email keyword',
        description: 'Set or update the user\'s email keyword for smart+keyword@synaplan.net',
        security: [['Bearer' => []]],
        tags: ['Profile']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['keyword'],
            properties: [
                new OA\Property(property: 'keyword', type: 'string', example: 'myproject', description: 'Keyword (lowercase letters, numbers, hyphens, underscores only). Empty string to remove.'),
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
                new OA\Property(property: 'emailAddress', type: 'string', example: 'smart+myproject@synaplan.net'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 400, description: 'Invalid keyword format')]
    public function setEmailKeyword(
        Request $request,
        #[CurrentUser] ?User $user,
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
                'emailAddress' => $this->emailChatService->getUserPersonalEmailAddress($user),
            ]);
        }

        try {
            $this->emailChatService->setUserEmailKeyword($user, $keyword);
            $emailAddress = $this->emailChatService->getUserPersonalEmailAddress($user);

            return $this->json([
                'success' => true,
                'message' => 'Email keyword updated successfully',
                'keyword' => $keyword,
                'emailAddress' => $emailAddress,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => 'Invalid keyword format. Only lowercase letters, numbers, hyphens, and underscores are allowed.',
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
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'CurrentPassword123!', description: 'Current password for confirmation'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Account deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Account deleted successfully'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Incorrect password or external auth user')]
    #[OA\Response(response: 400, description: 'Password required')]
    public function deleteAccount(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? '';

        if (empty($password)) {
            return $this->json([
                'error' => 'Password confirmation required',
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
                'provider' => $user->getAuthProviderName(),
            ]);
        } else {
            // Verify password for local auth users
            if (!$this->passwordHasher->isPasswordValid($user, $password)) {
                usleep(100000); // Timing attack prevention

                return $this->json([
                    'error' => 'Incorrect password',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Log account deletion
        $this->logger->info('User self-deletion initiated', [
            'user_id' => $user->getId(),
            'email' => $user->getMail(),
            'type' => $user->getType(),
        ]);

        try {
            $this->userDeletionService->deleteUser($user);

            return $this->json([
                'success' => true,
                'message' => 'Account deleted successfully',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('User self-deletion failed', [
                'user_id' => $user->getId(),
                'exception' => $e,
            ]);

            return $this->json([
                'error' => 'Failed to delete account. Please contact support.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
