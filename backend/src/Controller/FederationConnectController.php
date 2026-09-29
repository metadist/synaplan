<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Federation\FederationException;
use App\Service\Federation\FederationLinkService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/federation', name: 'api_federation_')]
#[OA\Tag(name: 'Federation')]
final class FederationConnectController extends AbstractController
{
    public function __construct(
        private readonly FederationLinkService $links,
    ) {
    }

    #[Route('/connect', name: 'connect', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/federation/connect',
        summary: 'Signed partner handshake: accept, pause, resume or end',
        tags: ['Federation'],
        responses: [
            new OA\Response(response: 200, description: 'Accepted'),
            new OA\Response(response: 400, description: 'Rejected'),
        ]
    )]
    public function connect(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['message' => 'The request is not valid JSON.', 'code' => 'bad_request'], 400);
        }
        try {
            /* @var array<string, mixed> $body */
            return $this->json($this->links->handleConnect($body));
        } catch (FederationException $e) {
            return $this->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }
}
