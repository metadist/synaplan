<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ImpersonationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Admin → user impersonation HTTP surface.
 *
 * Thin controller: argument unwrapping, role check, and delegation to
 * {@see ImpersonationService}, which owns the cookie-stash logic and all
 * security invariants. The 403 / 404 / 409 mapping below mirrors the typed
 * exceptions raised by the service.
 */
#[Route('/api/v1/admin/impersonate', name: 'api_admin_impersonate_')]
#[OA\Tag(name: 'Admin')]
final class AdminImpersonationController extends AbstractController
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route('/{userId}', name: 'start', requirements: ['userId' => '\d+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/impersonate/{userId}',
        summary: 'Start impersonating a user',
        description: 'Admin-only. Mints a fresh access token scoped to the target user with an `impersonator_id` claim, moves the admin\'s refresh token aside into a single HttpOnly stash cookie, and clears the regular refresh cookie. No additional refresh token is persisted for the target. The original admin session is restored via `/exit`. Admins may impersonate any other user, including other admins. Refused for self-impersonation, OIDC sessions, and nested impersonations.',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'userId',
        in: 'path',
        description: 'ID of the user to impersonate',
        required: true,
        schema: new OA\Schema(type: 'integer', minimum: 1)
    )]
    #[OA\Response(
        response: 200,
        description: 'Impersonation started',
        content: new OA\JsonContent(
            required: ['success', 'user', 'impersonator'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'user',
                    type: 'object',
                    description: 'The impersonated (target) user — every authenticated request from now on is attributed to this account.',
                    required: ['id', 'email', 'level'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 42),
                        new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                        new OA\Property(property: 'level', type: 'string', example: 'PRO'),
                    ]
                ),
                new OA\Property(
                    property: 'impersonator',
                    type: 'object',
                    description: 'The original admin, surfaced so the UI can render the impersonation banner.',
                    required: ['id', 'email', 'level'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'email', type: 'string', example: 'admin@example.com'),
                        new OA\Property(property: 'level', type: 'string', example: 'ADMIN'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Caller is not an admin, or rule violated (self/nested/OIDC)')]
    #[OA\Response(response: 404, description: 'Target user not found')]
    public function start(
        int $userId,
        Request $request,
        #[CurrentUser] ?User $admin,
    ): JsonResponse {
        if (!$admin || !$admin->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $target = $this->userRepository->find($userId);
        if (!$target) {
            return $this->json(['error' => 'User not found', 'userId' => $userId], Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse([
            'success' => true,
            'user' => $this->summariseUser($target),
            'impersonator' => $this->summariseUser($admin),
        ]);

        try {
            $this->impersonationService->startImpersonation($admin, $target, $request, $response);
        } catch (AccessDeniedException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $response;
    }

    #[Route('/exit', name: 'stop', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/impersonate/exit',
        summary: 'Stop the active impersonation',
        description: 'Restores the admin session by validating the stashed refresh token, minting a fresh admin access token, and swapping the stashed refresh back into the regular slot. Returns 403 when no impersonation is active or the stash is no longer valid (e.g. admin demoted, refresh revoked).',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Response(
        response: 200,
        description: 'Impersonation ended; admin session restored',
        content: new OA\JsonContent(
            required: ['success', 'user'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'user',
                    type: 'object',
                    description: 'The restored admin user.',
                    required: ['id', 'email', 'level'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'email', type: 'string', example: 'admin@example.com'),
                        new OA\Property(property: 'level', type: 'string', example: 'ADMIN'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'No active impersonation, or stash invalid')]
    public function stop(Request $request): JsonResponse
    {
        // We deliberately do NOT require ROLE_ADMIN on this endpoint: while
        // impersonating, the authenticated principal is the target user, not
        // the admin. The impersonation service authorises the call by reading
        // the stash cookie itself.
        $response = new JsonResponse(['success' => true]);

        try {
            $admin = $this->impersonationService->stopImpersonation($request, $response);
        } catch (AccessDeniedException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        $response->setData([
            'success' => true,
            'user' => $this->summariseUser($admin),
        ]);

        return $response;
    }

    /**
     * @return array{id: int, email: string, level: string}
     */
    private function summariseUser(User $user): array
    {
        return [
            'id' => (int) $user->getId(),
            'email' => $user->getMail(),
            'level' => $user->getUserLevel(),
        ];
    }
}
