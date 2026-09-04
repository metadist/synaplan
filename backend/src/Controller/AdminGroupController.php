<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Iam\Exception\DirectoryGroupReadOnlyException;
use App\Service\Iam\GroupService;
use App\Service\Iam\IamConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/groups', name: 'admin_groups_')]
#[OA\Tag(name: 'IAM Groups')]
final class AdminGroupController extends AbstractController
{
    public function __construct(
        private readonly IamConfig $iamConfig,
        private readonly GroupService $groupService,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/groups',
        operationId: 'listAdminGroups',
        summary: 'List all groups',
        tags: ['IAM Groups'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Groups',
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
            new OA\Response(response: 403, description: 'Admin access required'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $groups = $this->groupService->listAll();
        $counts = $this->groupService->memberCounts(array_map(
            static fn ($g): int => (int) $g->getId(),
            $groups,
        ));

        return $this->json([
            'groups' => array_map(
                fn ($group) => $this->groupService->serializeGroup($group, $counts[(int) $group->getId()] ?? 0),
                $groups,
            ),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/groups',
        operationId: 'createAdminGroup',
        summary: 'Create a manual group',
        tags: ['IAM Groups'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Sales'),
                    new OA\Property(property: 'description', type: 'string', example: ''),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(
                    required: ['group'],
                    properties: [
                        new OA\Property(
                            property: 'group',
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
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $data = $this->jsonBody($request);

        try {
            $group = $this->groupService->create(
                (string) ($data['name'] ?? ''),
                (string) ($data['description'] ?? ''),
                $user,
                (string) ($request->getClientIp() ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['group' => $this->groupService->serializeGroup($group, 0)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/admin/groups/{id}',
        operationId: 'updateAdminGroup',
        summary: 'Rename a manual group',
        tags: ['IAM Groups'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(
                    required: ['group'],
                    properties: [
                        new OA\Property(
                            property: 'group',
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
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
            new OA\Response(response: 409, description: 'Directory group is read-only'),
        ]
    )]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $group = $this->groupService->get($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->jsonBody($request);
        $name = array_key_exists('name', $data) ? (string) $data['name'] : $group->getName();
        $description = array_key_exists('description', $data) ? (string) $data['description'] : $group->getDescription();

        try {
            $group = $this->groupService->rename(
                $group,
                $name,
                $description,
                $user,
                (string) ($request->getClientIp() ?? ''),
            );
        } catch (DirectoryGroupReadOnlyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['group' => $this->groupService->serializeGroup($group)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/admin/groups/{id}',
        operationId: 'deleteAdminGroup',
        summary: 'Delete a manual group',
        tags: ['IAM Groups'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Deleted',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
            new OA\Response(response: 409, description: 'Directory group is read-only'),
        ]
    )]
    public function delete(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $group = $this->groupService->get($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->groupService->delete($group, $user, (string) ($request->getClientIp() ?? ''));
        } catch (DirectoryGroupReadOnlyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/members', name: 'members', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/admin/groups/{id}/members',
        operationId: 'listAdminGroupMembers',
        summary: 'List members of a group',
        tags: ['IAM Groups'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Members',
                content: new OA\JsonContent(
                    required: ['members'],
                    properties: [
                        new OA\Property(
                            property: 'members',
                            type: 'array',
                            items: new OA\Items(
                                required: ['userId', 'email', 'role', 'source', 'created'],
                                properties: [
                                    new OA\Property(property: 'userId', type: 'integer', example: 4),
                                    new OA\Property(property: 'email', type: 'string', example: 'ada@example.com'),
                                    new OA\Property(property: 'role', type: 'string', enum: ['member', 'manager'], example: 'member'),
                                    new OA\Property(property: 'source', type: 'string', enum: ['manual', 'directory'], example: 'manual'),
                                    new OA\Property(property: 'created', type: 'integer', format: 'int64'),
                                ]
                            ),
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function members(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $group = $this->groupService->get($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found'], Response::HTTP_NOT_FOUND);
        }

        $members = $this->groupService->membersOf($group);
        $userIds = array_map(static fn ($m): int => $m->getUserId(), $members);
        $users = [];
        if ([] !== $userIds) {
            foreach ($this->userRepository->findBy(['id' => $userIds]) as $row) {
                $users[(int) $row->getId()] = $row;
            }
        }

        $payload = [];
        foreach ($members as $member) {
            $memberUser = $users[$member->getUserId()] ?? null;
            if (null === $memberUser) {
                continue;
            }
            $payload[] = $this->groupService->serializeMember($member, $memberUser);
        }

        return $this->json(['members' => $payload]);
    }

    #[Route('/{id}/members/{userId}', name: 'member_set', methods: ['PUT'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    #[OA\Put(
        path: '/api/v1/admin/groups/{id}/members/{userId}',
        operationId: 'putAdminGroupMember',
        summary: 'Add or update a group member',
        tags: ['IAM Groups'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'role', type: 'string', enum: ['member', 'manager'], example: 'member'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Member set',
                content: new OA\JsonContent(
                    required: ['member'],
                    properties: [
                        new OA\Property(
                            property: 'member',
                            required: ['userId', 'email', 'role', 'source', 'created'],
                            properties: [
                                new OA\Property(property: 'userId', type: 'integer', example: 4),
                                new OA\Property(property: 'email', type: 'string', example: 'ada@example.com'),
                                new OA\Property(property: 'role', type: 'string', enum: ['member', 'manager'], example: 'member'),
                                new OA\Property(property: 'source', type: 'string', enum: ['manual', 'directory'], example: 'manual'),
                                new OA\Property(property: 'created', type: 'integer', format: 'int64'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function setMember(int $id, int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $group = $this->groupService->get($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->jsonBody($request);
        $role = (string) ($data['role'] ?? 'member');

        try {
            $member = $this->groupService->setMember(
                $group,
                $userId,
                $role,
                $user,
                (string) ($request->getClientIp() ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $target = $this->userRepository->find($userId);
        if (!$target instanceof User) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['member' => $this->groupService->serializeMember($member, $target)]);
    }

    #[Route('/{id}/members/{userId}', name: 'member_remove', methods: ['DELETE'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/admin/groups/{id}/members/{userId}',
        operationId: 'deleteAdminGroupMember',
        summary: 'Remove a member from a group',
        tags: ['IAM Groups'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Removed',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function removeMember(int $id, int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $group = $this->groupService->get($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found'], Response::HTTP_NOT_FOUND);
        }

        $this->groupService->removeMember($group, $userId, $user, (string) ($request->getClientIp() ?? ''));

        return $this->json(['success' => true]);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->iamConfig->isGroupsEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
