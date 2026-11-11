<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\PlatformInstanceService;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/platform-links/instances', name: 'platform_link_instances_')]
#[OA\Tag(name: 'Platform Links')]
final class PlatformInstanceController extends AbstractController
{
    public function __construct(
        private readonly PlatformLinksConfig $platformLinksConfig,
        private readonly PlatformInstanceService $instanceService,
    ) {
    }

    #[Route('/{instanceId}/public', name: 'public', methods: ['GET'], requirements: ['instanceId' => 'pi_[a-f0-9]+|outlook-builtin|oidc-cloud'])]
    #[OA\Get(
        path: '/api/v1/platform-links/instances/{instanceId}/public',
        operationId: 'getPlatformInstancePublic',
        summary: 'Public host and client for the confirm card',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'instanceId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active instance',
                content: new OA\JsonContent(
                    required: ['success', 'client', 'host'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                        new OA\Property(property: 'host', type: 'string', example: 'files.example.org'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Feature disabled or instance not active'),
        ]
    )]
    public function public(string $instanceId): JsonResponse
    {
        $this->guard(null);

        try {
            $result = $this->instanceService->describePublic($instanceId);
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, ...$result]);
    }

    private function guard(?int $userId): void
    {
        if (!$this->platformLinksConfig->isEnabled($userId)) {
            throw new NotFoundHttpException();
        }
    }
}
