<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformManagedByAdministratorException;
use App\Service\PlatformLink\Exception\PlatformSsoException;
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
                                required: ['id', 'client', 'host', 'external_id', 'key_id', 'key_label', 'created', 'last_seen', 'mode', 'status', 'can_copy'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                                    new OA\Property(property: 'host', type: 'string', example: 'files.example.org'),
                                    new OA\Property(property: 'external_id', type: 'string', example: 'jdoe'),
                                    new OA\Property(property: 'key_id', type: 'integer', nullable: true),
                                    new OA\Property(property: 'key_label', type: 'string'),
                                    new OA\Property(property: 'created', type: 'integer', format: 'int64'),
                                    new OA\Property(property: 'last_seen', type: 'integer', format: 'int64'),
                                    new OA\Property(property: 'mode', type: 'string', nullable: true, example: 'link'),
                                    new OA\Property(property: 'status', type: 'string', example: 'active'),
                                    new OA\Property(property: 'can_copy', type: 'boolean', example: true),
                                    new OA\Property(property: 'can_read', type: 'boolean', example: true, description: 'The cloud app hands files to a run when asked'),
                                    new OA\Property(
                                        property: 'read_scope',
                                        description: 'What this person lets Synaplan read, as the cloud last reported it',
                                        required: ['mode', 'folders'],
                                        properties: [
                                            new OA\Property(property: 'mode', type: 'string', enum: ['watched', 'allowed', 'all'], example: 'allowed'),
                                            new OA\Property(property: 'folders', type: 'array', items: new OA\Items(type: 'string'), example: ['Invoices/2026', 'Specs']),
                                        ],
                                        type: 'object',
                                    ),
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
        } catch (PlatformManagedByAdministratorException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage(), 'code' => PlatformManagedByAdministratorException::CODE], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/turn-off', name: 'turn_off', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/me/platform-links/{id}/turn-off',
        operationId: 'turnOffMyPlatformLink',
        summary: 'Turn an organisation-login connection off',
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Turned off'),
            new OA\Response(response: 403, description: 'Not organisation login'),
            new OA\Response(response: 404, description: 'Unknown link'),
        ]
    )]
    public function turnOff(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->change($user, $id, $request, false);
    }

    #[Route('/{id}/resume', name: 'resume', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/me/platform-links/{id}/resume',
        operationId: 'resumeMyPlatformLink',
        summary: 'Turn an organisation-login connection back on',
        tags: ['Platform Links'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Turned back on'),
            new OA\Response(response: 403, description: 'Not organisation login'),
            new OA\Response(response: 404, description: 'Unknown link'),
        ]
    )]
    public function resume(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->change($user, $id, $request, true);
    }

    private function change(?User $user, int $id, Request $request, bool $resume): JsonResponse
    {
        $this->guard($user?->getId());
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            if ($resume) {
                $this->exchangeService->resume((int) $user->getId(), $id, $request->getClientIp() ?? '');
            } else {
                $this->exchangeService->turnOff((int) $user->getId(), $id, $request->getClientIp() ?? '');
            }
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Link not found.'], Response::HTTP_NOT_FOUND);
        } catch (PlatformSsoException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
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
