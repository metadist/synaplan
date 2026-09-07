<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Iam\GroupService;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Policy\GroupPolicyService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/groups', name: 'admin_group_config_')]
#[OA\Tag(name: 'IAM Group Policies')]
final class AdminGroupConfigController extends AbstractController
{
    public function __construct(
        private readonly IamConfig $iamConfig,
        private readonly GroupService $groupService,
        private readonly GroupPolicyService $groupPolicyService,
    ) {
    }

    #[Route('/{id}/config', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/admin/groups/{id}/config',
        operationId: 'getAdminGroupConfig',
        summary: 'Get allow-listed policy settings for a group',
        tags: ['IAM Group Policies'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Allow-listed keys with values and effective source',
                content: new OA\JsonContent(
                    required: ['settings', 'conflicts'],
                    properties: [
                        new OA\Property(
                            property: 'settings',
                            type: 'object',
                            additionalProperties: new OA\AdditionalProperties(
                                type: 'object',
                                required: ['value', 'source', 'locked'],
                                properties: [
                                    new OA\Property(property: 'value', nullable: true),
                                    new OA\Property(property: 'source', type: 'string', nullable: true, enum: ['group', 'admin']),
                                    new OA\Property(property: 'locked', type: 'boolean'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'conflicts',
                            type: 'object',
                            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
                            description: 'DEFAULTMODEL keys where two groups set different catalog keys'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Admin access required'),
            new OA\Response(response: 404, description: 'Feature disabled or group not found'),
        ]
    )]
    public function get(int $id, #[CurrentUser] ?User $user): JsonResponse
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

        return $this->json($this->groupPolicyService->getGroupConfig($id, $user));
    }

    #[Route('/{id}/config', name: 'put', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[OA\Put(
        path: '/api/v1/admin/groups/{id}/config',
        operationId: 'putAdminGroupConfig',
        summary: 'Set allow-listed policy settings for a group',
        tags: ['IAM Group Policies'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                example: ['DEFAULTMODEL.CHAT' => 'openai:gpt-4o:chat', 'MODELS.ALLOWED' => ['openai:gpt-4o:chat']],
                additionalProperties: true
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated settings',
                content: new OA\JsonContent(
                    required: ['settings', 'conflicts'],
                    properties: [
                        new OA\Property(
                            property: 'settings',
                            type: 'object',
                            additionalProperties: new OA\AdditionalProperties(
                                type: 'object',
                                required: ['value', 'source', 'locked'],
                                properties: [
                                    new OA\Property(property: 'value', nullable: true),
                                    new OA\Property(property: 'source', type: 'string', nullable: true, enum: ['group', 'admin']),
                                    new OA\Property(property: 'locked', type: 'boolean'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'conflicts',
                            type: 'object',
                            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Admin access required'),
            new OA\Response(response: 404, description: 'Feature disabled or group not found'),
            new OA\Response(response: 422, description: 'Unknown or invalid key'),
        ]
    )]
    public function put(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $payload = $this->groupPolicyService->putGroupConfig(
                $group,
                $data,
                $user,
                (string) ($request->getClientIp() ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'iam.unknownPolicyKey'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($payload);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->iamConfig->isGroupsEnabled((int) $user->getId())
            || !$this->iamConfig->isGroupPoliciesEnabled((int) $user->getId())
        ) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
