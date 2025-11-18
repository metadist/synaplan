<?php

namespace App\Controller;

use App\Entity\User;
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
        private LoggerInterface $logger
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
                        new OA\Property(property: 'invoiceEmail', type: 'string', example: 'billing@example.com')
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

        return $this->json([
            'success' => true,
            'profile' => [
                'email' => $user->getMail(),
                'firstName' => $details['firstName'] ?? '',
                'lastName' => $details['lastName'] ?? '',
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
                new OA\Property(property: 'invoiceEmail', type: 'string', example: 'billing@example.com')
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
}

