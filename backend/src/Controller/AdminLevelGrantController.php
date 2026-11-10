<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\LevelChangeNotice;
use App\DTO\LevelGrantView;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\AdminPeopleAccountService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Removes the administrator grant on an account. Billing, a sign-up code
 * and the directory keep their own grants. Lives outside AdminController
 * so that class does not grow.
 */
#[Route('/api/v1/admin')]
final class AdminLevelGrantController extends AbstractController
{
    public function __construct(
        private UserRepository $users,
        private AdminPeopleAccountService $people,
    ) {
    }

    #[Route('/users/{id}/grants/admin', name: 'admin_remove_admin_grant', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/admin/users/{id}/grants/admin',
        summary: 'Remove the administrator grant',
        description: 'Removes only the grant an administrator set. The account keeps every other grant, and its level becomes the highest of those.',
        security: [['Bearer' => []]],
        tags: ['Admin']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'The administrator grant was removed',
        content: new OA\JsonContent(
            required: ['success', 'user', 'notice'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'user',
                    type: 'object',
                    required: ['id', 'level', 'grants'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 2),
                        new OA\Property(property: 'level', type: 'string', enum: ['ANONYMOUS', 'NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'], example: 'BUSINESS'),
                        new OA\Property(property: 'grants', type: 'array', items: new OA\Items(ref: new Model(type: LevelGrantView::class))),
                    ]
                ),
                new OA\Property(property: 'notice', ref: new Model(type: LevelChangeNotice::class)),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Not authorized or cannot change own level')]
    #[OA\Response(response: 404, description: 'User not found, or no administrator grant')]
    #[OA\Response(response: 409, description: 'Cannot demote the last administrator')]
    public function remove(
        int $id,
        Request $request,
        #[CurrentUser] ?User $actor,
    ): JsonResponse {
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $target = $this->users->find($id);
        if (!$target instanceof User) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $result = $this->people->removeAdminGrant($actor, $target, (string) $request->getClientIp());
        if ('missing' === $result['outcome']) {
            return $this->json(['error' => 'No administrator grant'], Response::HTTP_NOT_FOUND);
        }
        if ('conflict' === $result['outcome']) {
            return $this->json(['error' => 'Cannot demote the last administrator'], Response::HTTP_CONFLICT);
        }
        if ('forbidden' === $result['outcome']) {
            return $this->json(['error' => 'Cannot change your own level'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'user' => [
                'id' => $target->getId(),
                'level' => $target->getUserLevel(),
                'grants' => $result['grants'],
            ],
            'notice' => $result['notice'],
        ]);
    }
}
