<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\PlatformLinkExchangeService;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/me/platform-links', name: 'me_platform_links_')]
#[OA\Tag(name: 'Platform Links')]
final class MePlatformLinkController extends AbstractController
{
    public function __construct(
        private readonly PlatformLinksConfig $platformLinksConfig,
        private readonly PlatformLinkExchangeService $exchangeService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/me/platform-links',
        operationId: 'listMyPlatformLinks',
        summary: 'List platforms linked to the signed-in account',
        tags: ['Platform Links'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Linked platforms',
                content: new OA\JsonContent(
                    required: ['success', 'links'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'links',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'client', 'host', 'external_id', 'key_id', 'key_label', 'created', 'last_seen'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                                    new OA\Property(property: 'host', type: 'string', example: 'files.example.org'),
                                    new OA\Property(property: 'external_id', type: 'string', example: 'jdoe'),
                                    new OA\Property(property: 'key_id', type: 'integer', nullable: true),
                                    new OA\Property(property: 'key_label', type: 'string'),
                                    new OA\Property(property: 'created', type: 'integer', format: 'int64'),
                                    new OA\Property(property: 'last_seen', type: 'integer', format: 'int64'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'links' => $this->exchangeService->listForUser((int) $user->getId()),
        ]);
    }

    #[Route('/{id}', name: 'disconnect', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/me/platform-links/{id}',
        operationId: 'disconnectMyPlatformLink',
        summary: 'Disconnect a linked platform and revoke its key',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Disconnected',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled or unknown link'),
        ]
    )]
    public function disconnect(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->exchangeService->disconnect((int) $user->getId(), $id, $request->getClientIp() ?? '');
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Link not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true]);
    }

    private function guard(?int $userId): void
    {
        if (!$this->platformLinksConfig->isEnabled($userId)) {
            throw new NotFoundHttpException();
        }
    }
}
