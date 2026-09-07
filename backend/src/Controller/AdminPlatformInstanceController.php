<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use App\Service\PlatformLink\PlatformInstanceService;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/platform-links/instances', name: 'admin_platform_link_instances_')]
#[OA\Tag(name: 'Platform Links')]
final class AdminPlatformInstanceController extends AbstractController
{
    public function __construct(
        private readonly PlatformLinksConfig $platformLinksConfig,
        private readonly PlatformInstanceService $instanceService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/platform-links/instances',
        operationId: 'listAdminPlatformInstances',
        summary: 'List every registered partner instance',
        tags: ['Platform Links'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Instances',
                content: new OA\JsonContent(
                    required: ['success', 'instances'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'instances',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'client', 'host', 'status', 'registeredBy', 'created', 'lastSeen'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'string', example: 'pi_ab12cd34ef56'),
                                    new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                                    new OA\Property(property: 'host', type: 'string', example: 'files.example.org'),
                                    new OA\Property(property: 'status', type: 'string', example: 'pending'),
                                    new OA\Property(property: 'registeredBy', type: 'integer', example: 1),
                                    new OA\Property(property: 'created', type: 'integer', format: 'int64'),
                                    new OA\Property(property: 'lastSeen', type: 'integer', format: 'int64'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$user->isAdmin()) {
            return $this->json(['success' => false, 'error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['success' => true, 'instances' => $this->instanceService->listForAdmin()]);
    }

    #[Route('/{instanceId}/approve', name: 'approve', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/platform-links/instances/{instanceId}/approve',
        operationId: 'approvePlatformInstance',
        summary: 'Approve a pending partner instance',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'instanceId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Approved',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Not pending'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Feature disabled or unknown instance'),
        ]
    )]
    public function approve(string $instanceId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user);
        if (!$user instanceof User || !$user->isAdmin()) {
            return $this->json(['success' => false, 'error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->instanceService->approve($instanceId, $user, $request->getClientIp() ?? '');
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        } catch (PlatformLinkValidationException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/{instanceId}', name: 'revoke', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/admin/platform-links/instances/{instanceId}',
        operationId: 'revokePlatformInstance',
        summary: 'Revoke a partner instance and every linked key',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'instanceId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Revoked',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Feature disabled or unknown instance'),
        ]
    )]
    public function revoke(string $instanceId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user);
        if (!$user instanceof User || !$user->isAdmin()) {
            return $this->json(['success' => false, 'error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->instanceService->revoke($instanceId, $user, $request->getClientIp() ?? '');
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        } catch (PlatformLinkValidationException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true]);
    }

    private function guard(?User $user): void
    {
        if (!$this->platformLinksConfig->isEnabled($user?->getId())) {
            throw new NotFoundHttpException();
        }
    }
}
