<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Policy\GroupPolicyService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/config', name: 'admin_config_lock_')]
#[OA\Tag(name: 'IAM Group Policies')]
final class AdminConfigLockController extends AbstractController
{
    public function __construct(
        private readonly IamConfig $iamConfig,
        private readonly GroupPolicyService $groupPolicyService,
    ) {
    }

    #[Route('/locks', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/config/locks',
        operationId: 'getAdminConfigLocks',
        summary: 'List locked global policy settings',
        tags: ['IAM Group Policies'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Allow-listed keys and whether the global row is locked',
                content: new OA\JsonContent(
                    required: ['locks'],
                    properties: [
                        new OA\Property(
                            property: 'locks',
                            type: 'object',
                            additionalProperties: new OA\AdditionalProperties(type: 'boolean')
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

        return $this->json(['locks' => $this->groupPolicyService->listLocks((int) $user->getId())]);
    }

    #[Route('/locks', name: 'patch', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/admin/config/locks',
        operationId: 'patchAdminConfigLocks',
        summary: 'Lock or unlock global policy settings',
        tags: ['IAM Group Policies'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                example: ['DEFAULTMODEL.CHAT' => true],
                additionalProperties: new OA\AdditionalProperties(type: 'boolean')
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated locks',
                content: new OA\JsonContent(
                    required: ['locks'],
                    properties: [
                        new OA\Property(
                            property: 'locks',
                            type: 'object',
                            additionalProperties: new OA\AdditionalProperties(type: 'boolean')
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Admin access required'),
            new OA\Response(response: 404, description: 'Feature disabled'),
            new OA\Response(response: 422, description: 'Unknown key'),
        ]
    )]
    public function patch(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $locks = $this->groupPolicyService->setLocks(
                $data,
                $user,
                (string) ($request->getClientIp() ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'iam.unknownPolicyKey'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['locks' => $locks]);
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
