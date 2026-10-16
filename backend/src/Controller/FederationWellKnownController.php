<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Federation\FederationException;
use App\Service\Federation\FederationLinkService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Federation')]
final class FederationWellKnownController extends AbstractController
{
    public function __construct(
        private readonly FederationLinkService $links,
    ) {
    }

    #[Route('/.well-known/synaplan-federation', name: 'federation_well_known', methods: ['GET'])]
    #[OA\Get(
        path: '/.well-known/synaplan-federation',
        summary: 'Public partner identity of this server',
        tags: ['Federation'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Identity',
                content: new OA\JsonContent(
                    required: ['protocol', 'domain', 'key', 'api', 'name', 'software'],
                    properties: [
                        new OA\Property(property: 'protocol', type: 'integer', example: 0),
                        new OA\Property(property: 'domain', type: 'string', example: 'contoso.example'),
                        new OA\Property(property: 'key', type: 'string', example: 'ed25519:abc'),
                        new OA\Property(property: 'api', type: 'string', example: 'https://contoso.example/api/v1/federation'),
                        new OA\Property(property: 'name', type: 'string', example: 'Contoso GmbH'),
                        new OA\Property(property: 'software', type: 'string', example: 'synaplan'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Partners is closed'),
        ]
    )]
    public function show(): JsonResponse
    {
        try {
            return $this->json($this->links->wellKnown());
        } catch (FederationException $e) {
            return $this->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }
}
