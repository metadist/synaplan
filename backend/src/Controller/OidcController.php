<?php

namespace App\Controller;

use App\Security\OidcTokenHandler;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/oidc', name: 'api_oidc_')]
class OidcController extends AbstractController
{
    public function __construct(
        private OidcTokenHandler $oidcHandler,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Refresh OIDC access token using refresh token.
     *
     * Client Secret is kept server-side for security
     */
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/oidc/refresh',
        summary: 'Refresh OIDC access token',
        description: 'Use refresh token to get a new access token. Client secret is kept server-side.',
        tags: ['OIDC Authentication']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['refresh_token'],
            properties: [
                new OA\Property(property: 'refresh_token', type: 'string', example: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Token refreshed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'access_token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string'),
                new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Invalid refresh token'
    )]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$refreshToken) {
            return $this->json([
                'success' => false,
                'error' => 'refresh_token is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $tokenData = $this->oidcHandler->refreshAccessToken($refreshToken);

            return $this->json([
                'success' => true,
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'expires_in' => $tokenData['expires_in'],
                'token_type' => $tokenData['token_type'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Token refresh failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to refresh token',
            ], Response::HTTP_BAD_REQUEST);
        }
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
        description: 'Discovery configuration',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'issuer', type: 'string'),
                new OA\Property(property: 'authorization_endpoint', type: 'string'),
                new OA\Property(property: 'token_endpoint', type: 'string'),
                new OA\Property(property: 'userinfo_endpoint', type: 'string'),
                new OA\Property(property: 'end_session_endpoint', type: 'string'),
                new OA\Property(property: 'client_id', type: 'string', description: 'Public client ID'),
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
