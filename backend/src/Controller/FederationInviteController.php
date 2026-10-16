<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Federation\FederationException;
use App\Service\Federation\FederationLinkService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/federation', name: 'api_federation_')]
#[OA\Tag(name: 'Federation')]
final class FederationInviteController extends AbstractController
{
    public function __construct(
        private readonly FederationLinkService $links,
    ) {
    }

    #[Route('/invites/{token}', name: 'invite_preview', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/federation/invites/{token}',
        summary: 'Preview an invite without accepting it',
        tags: ['Federation'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invite preview',
                content: new OA\JsonContent(
                    required: ['name', 'domain', 'expiresAt', 'pasteUrl'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'domain', type: 'string'),
                        new OA\Property(property: 'expiresAt', type: 'integer'),
                        new OA\Property(property: 'pasteUrl', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Invite is not valid'),
        ]
    )]
    public function preview(string $token): JsonResponse
    {
        try {
            return $this->json($this->links->previewInvite($token));
        } catch (FederationException $e) {
            return $this->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }
}
