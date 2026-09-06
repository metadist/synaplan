<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\IamConfig;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/users', name: 'admin_user_resources_')]
#[OA\Tag(name: 'IAM Audit')]
final class AdminUserResourcesController extends AbstractController
{
    public function __construct(
        private readonly IamConfig $iamConfig,
        private readonly UserRepository $userRepository,
        private readonly ResourceKindRegistry $registry,
        private readonly ShareRepository $shareRepository,
        private readonly AuditLogWriter $auditLogWriter,
    ) {
    }

    #[Route('/{id}/resources', name: 'list', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/users/{id}/resources',
        operationId: 'listAdminUserResources',
        summary: 'Metadata-only list of a user\'s shareable resources',
        tags: ['IAM Audit'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resource cards (name, icon, share count — never content)',
                content: new OA\JsonContent(
                    required: ['resources'],
                    properties: [
                        new OA\Property(
                            property: 'resources',
                            type: 'array',
                            items: new OA\Items(
                                required: ['kind', 'id', 'name', 'icon', 'shareCount'],
                                properties: [
                                    new OA\Property(property: 'kind', type: 'string'),
                                    new OA\Property(property: 'id', type: 'string'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'icon', type: 'string'),
                                    new OA\Property(property: 'shareCount', type: 'integer'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Admin access required'),
            new OA\Response(response: 404, description: 'Feature disabled or user missing'),
        ]
    )]
    public function list(int $id, Request $request, #[CurrentUser] ?User $admin): JsonResponse
    {
        if (!$admin instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->iamConfig->isGroupsEnabled((int) $admin->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$admin->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }
        $target = $this->userRepository->find($id);
        if (!$target instanceof User) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $resources = [];
        foreach ($this->registry->keys() as $kindKey) {
            $kind = $this->registry->get($kindKey);
            foreach ($kind->listOwnedBy($id) as $card) {
                $resources[] = [
                    'kind' => $kindKey,
                    'id' => $card->id,
                    'name' => $card->name,
                    'icon' => $card->icon,
                    'shareCount' => count($this->shareRepository->findForResource($kindKey, $card->id)),
                ];
            }
        }

        $this->auditLogWriter->record(
            (int) $admin->getId(),
            'admin.metadata_view',
            'user',
            (string) $id,
            ['targetUserId' => $id, 'count' => count($resources)],
            (string) ($request->getClientIp() ?? ''),
        );

        return $this->json(['resources' => $resources]);
    }
}
