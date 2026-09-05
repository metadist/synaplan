<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Iam\GroupService;
use App\Service\Iam\IamConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/groups', name: 'groups_')]
#[OA\Tag(name: 'IAM Groups')]
final class GroupController extends AbstractController
{
    public function __construct(
        private readonly IamConfig $iamConfig,
        private readonly GroupService $groupService,
    ) {
    }

    #[Route('/mine', name: 'mine', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/groups/mine',
        operationId: 'listMyGroups',
        summary: 'List groups the current user belongs to',
        tags: ['IAM Groups'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Groups I belong to',
                content: new OA\JsonContent(
                    required: ['groups'],
                    properties: [
                        new OA\Property(
                            property: 'groups',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'name', 'slug', 'description', 'kind', 'memberCount', 'created', 'updated'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: 'Sales'),
                                    new OA\Property(property: 'slug', type: 'string', example: 'sales'),
                                    new OA\Property(property: 'description', type: 'string', example: ''),
                                    new OA\Property(property: 'kind', type: 'string', enum: ['manual', 'directory'], example: 'manual'),
                                    new OA\Property(property: 'memberCount', type: 'integer', example: 3),
                                    new OA\Property(property: 'role', type: 'string', enum: ['member', 'manager'], nullable: true),
                                    new OA\Property(property: 'created', type: 'integer', format: 'int64'),
                                    new OA\Property(property: 'updated', type: 'integer', format: 'int64'),
                                ]
                            ),
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function mine(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->iamConfig->isGroupsEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $rows = $this->groupService->groupsOf((int) $user->getId());

        return $this->json([
            'groups' => array_map(
                fn (array $row) => $this->groupService->serializeGroup($row['group'], null, $row['role']),
                $rows,
            ),
        ]);
    }
}
