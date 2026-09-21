<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Group;
use App\Entity\User;
use App\Service\Iam\Exception\DirectoryGroupReadOnlyException;
use App\Service\Iam\Exception\GroupMembershipNotFoundException;
use App\Service\Iam\GroupService;
use App\Service\Iam\IamConfig;
use App\Service\Iam\ShareService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly ShareService $shareService,
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
                                    new OA\Property(property: 'externalSource', type: 'string', nullable: true, example: 'oidc:https://idp.example/realms/synaplan'),
                                    new OA\Property(property: 'memberCount', type: 'integer', example: 3),
                                    new OA\Property(property: 'role', type: 'string', enum: ['member', 'manager'], nullable: true),
                                    new OA\Property(property: 'membershipSource', type: 'string', enum: ['manual', 'directory'], nullable: true),
                                    new OA\Property(property: 'canLeave', type: 'boolean', example: true),
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
                fn (array $row) => $this->groupService->serializeGroup(
                    $row['group'],
                    null,
                    $row['role'],
                    $row['source'],
                ),
                $rows,
            ),
        ]);
    }

    #[Route('/{id}/granted-shares', name: 'granted_shares', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/groups/{id}/granted-shares',
        operationId: 'countMyGrantsToGroup',
        summary: 'Count items the current user shared with a group',
        description: 'Counts share rows this user granted to the group. Grants made by other people are not included. Leaving the group does not delete these rows unless the user explicitly chooses to stop sharing.',
        tags: ['IAM Groups'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'How many items this user shared with the group',
                content: new OA\JsonContent(
                    required: ['count'],
                    properties: [
                        new OA\Property(property: 'count', type: 'integer', example: 2),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not a member, or feature disabled'),
            new OA\Response(response: 409, description: 'Directory membership cannot be left here'),
        ]
    )]
    public function grantedShares(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $group = $this->requireLeavableGroup($id, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        \assert($user instanceof User);

        return $this->json([
            'count' => $this->shareService->countOwnGrantsToGroup($user, (int) $group->getId()),
        ]);
    }

    #[Route('/{id}/membership', name: 'leave', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/groups/{id}/membership',
        operationId: 'leaveMyGroup',
        summary: 'Leave a group you were added to',
        description: 'Removes the current user from a manual membership. Shares this user granted to the group stay in place unless withdrawShares is true. Directory-synced memberships cannot be left here; they return at the next sign-in.',
        tags: ['IAM Groups'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(
                name: 'withdrawShares',
                in: 'query',
                required: false,
                description: 'When true, also delete shares this user granted to the group. Omit it to leave those shares in place.',
                schema: new OA\Schema(type: 'boolean', default: false),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Left the group',
                content: new OA\JsonContent(
                    required: ['success', 'withdrawnShares'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'withdrawnShares', type: 'integer', example: 0),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not a member, or feature disabled'),
            new OA\Response(response: 409, description: 'Directory membership cannot be left here'),
        ]
    )]
    public function leave(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $group = $this->requireLeavableGroup($id, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        \assert($user instanceof User);

        $ip = (string) ($request->getClientIp() ?? '');
        $withdraw = $request->query->getBoolean('withdrawShares');
        $withdrawn = 0;
        if ($withdraw) {
            $withdrawn = $this->shareService->withdrawOwnGrantsToGroup($user, (int) $group->getId(), $ip);
        }

        try {
            $this->groupService->leave($group, $user, $ip, $withdrawn);
        } catch (GroupMembershipNotFoundException) {
            return $this->json(['error' => 'You are not a member of this group.'], Response::HTTP_NOT_FOUND);
        } catch (DirectoryGroupReadOnlyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['success' => true, 'withdrawnShares' => $withdrawn]);
    }

    private function requireLeavableGroup(int $id, ?User $user): Group|JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->iamConfig->isGroupsEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $group = $this->groupService->get($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->groupService->assertCanLeave($group, $user);
        } catch (GroupMembershipNotFoundException) {
            return $this->json(['error' => 'You are not a member of this group.'], Response::HTTP_NOT_FOUND);
        } catch (DirectoryGroupReadOnlyException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $group;
    }
}
