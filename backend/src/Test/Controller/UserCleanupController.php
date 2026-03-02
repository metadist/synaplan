<?php

declare(strict_types=1);

namespace App\Test\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserDeletionService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Cleanup user data but keep user account (admin only, for idempotent E2E tests).
 * Only registered in dev and test; not available in prod.
 */
#[Route('/api/v1/admin')]
#[OA\Tag(name: 'Admin')]
class UserCleanupController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private UserDeletionService $userDeletionService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/users/{id}/cleanup', name: 'admin_cleanup_user_data', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/users/{id}/cleanup',
        summary: 'Cleanup user data (test only)',
        description: 'Delete all user data but keep the user account (admin only, for idempotent tests). Only available in dev/test.',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'User ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'User data cleaned up')]
    #[OA\Response(response: 403, description: 'Not authorized')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function cleanupUserData(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $targetUser = $this->userRepository->find($id);
        if (!$targetUser) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $this->logger->info('Admin initiated user data cleanup', [
            'admin_id' => $user->getId(),
            'target_user_id' => $id,
            'target_email' => $targetUser->getMail(),
        ]);

        try {
            $this->userDeletionService->cleanupUserData($targetUser);

            return $this->json(['success' => true, 'message' => 'User data cleaned up']);
        } catch (\Throwable $e) {
            $this->logger->error('Admin user data cleanup failed', [
                'admin_id' => $user->getId(),
                'target_user_id' => $id,
                'exception' => $e,
            ]);

            return $this->json([
                'error' => 'Failed to cleanup user data. Please contact support.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
