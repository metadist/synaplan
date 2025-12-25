<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * OIDC Discovery Controller.
 *
 * Provides OIDC discovery configuration for frontend.
 * Token refresh is handled by AuthController::refresh()
 */
#[Route('/api/v1/oidc', name: 'api_oidc_')]
class OidcController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get OIDC discovery configuration (without client secret).
     */
    #[Route('/discovery', name: 'discovery', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/oidc/discovery',
        summary: 'Get OIDC discovery configuration',
        description: 'Returns OIDC endpoints for frontend. Client secret is never exposed.',
        tags: ['OIDC Authentication']
    )]
    #[OA\Response(
        response: 200,
        description: 'Discovery configuration with OIDC endpoints',
        content: new OA\JsonContent(
            required: ['success', 'discovery_url', 'issuer', 'client_id'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true, description: 'Whether OIDC is configured'),
                new OA\Property(property: 'discovery_url', type: 'string', example: 'https://keycloak.example.com/realms/synaplan/.well-known/openid-configuration', description: 'Full URL to OIDC discovery document'),
                new OA\Property(property: 'issuer', type: 'string', example: 'https://keycloak.example.com/realms/synaplan', description: 'OIDC issuer URL'),
                new OA\Property(property: 'client_id', type: 'string', example: 'synaplan-client', description: 'Public client ID (safe to expose to frontend)'),
            ]
        )
    )]
    #[OA\Response(
        response: 503,
        description: 'OIDC not configured on server',
        content: new OA\JsonContent(
            required: ['success', 'error'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'OIDC not configured'),
            ]
        )
    )]
    public function discovery(): JsonResponse
    {
        $discoveryUrl = $_ENV['OIDC_DISCOVERY_URL'] ?? null;
        $clientId = $_ENV['OIDC_CLIENT_ID'] ?? null;

        if (!$discoveryUrl || !$clientId) {
            return $this->json([
                'success' => false,
                'error' => 'OIDC not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'success' => true,
            'discovery_url' => rtrim($discoveryUrl, '/').'/.well-known/openid-configuration',
            'issuer' => $discoveryUrl,
            'client_id' => $clientId,
            // Client secret is NEVER exposed!
        ]);
    }
}
