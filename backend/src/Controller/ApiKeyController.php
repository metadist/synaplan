<?php

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * API Key Management
 * 
 * Allows users to create, list, and revoke API keys for external integrations
 */
#[Route('/api/v1/apikeys', name: 'api_keys_')]
class ApiKeyController extends AbstractController
{
    public function __construct(
        private ApiKeyRepository $apiKeyRepository
    ) {}

    /**
     * List all API keys for current user
     * 
     * GET /api/v1/apikeys
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/apikeys',
        summary: 'List all API keys for current user',
        tags: ['API Keys'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of API keys',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'api_keys',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'key_prefix', type: 'string', example: 'sk_1234...'),
                                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'revoked']),
                                    new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string')),
                                    new OA\Property(property: 'last_used', type: 'string', format: 'date-time', nullable: true),
                                    new OA\Property(property: 'created', type: 'string', format: 'date-time')
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated')
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $apiKeys = $this->apiKeyRepository->findByOwner($user->getId());

        return $this->json([
            'success' => true,
            'api_keys' => array_map(function (ApiKey $key) {
                return [
                    'id' => $key->getId(),
                    'name' => $key->getName(),
                    'key_prefix' => substr($key->getKey(), 0, 8) . '...',
                    'status' => $key->getStatus(),
                    'scopes' => $key->getScopes(),
                    'last_used' => $key->getLastUsed(),
                    'created' => $key->getCreated()
                ];
            }, $apiKeys)
        ]);
    }

    /**
     * Create new API key
     * 
     * POST /api/v1/apikeys
     * Body: {
     *   "name": "Email Integration",
     *   "scopes": ["webhooks:email", "webhooks:whatsapp"]
     * }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/apikeys',
        summary: 'Create a new API key',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Email Integration'),
                    new OA\Property(
                        property: 'scopes',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['webhooks:email', 'webhooks:whatsapp']
                    )
                ]
            )
        ),
        tags: ['API Keys'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'API key created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'api_key',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'key', type: 'string', description: 'Full API key - only shown once!'),
                                new OA\Property(property: 'status', type: 'string'),
                                new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string')),
                                new OA\Property(property: 'created', type: 'string', format: 'date-time')
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Not authenticated')
        ]
    )]
    public function create(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (empty($data['name'])) {
            return $this->json([
                'success' => false,
                'error' => 'Name is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Generate secure API key (max 64 chars total)
        // sk_ (3) + 58 hex chars = 61 chars (fits in VARCHAR(64))
        $apiKeyValue = 'sk_' . bin2hex(random_bytes(29));

        $apiKey = new ApiKey();
        $apiKey->setOwner($user);
        $apiKey->setKey($apiKeyValue);
        $apiKey->setName($data['name']);
        $apiKey->setStatus('active');
        $apiKey->setScopes($data['scopes'] ?? ['webhooks:*']);

        $this->apiKeyRepository->save($apiKey);

        return $this->json([
            'success' => true,
            'api_key' => [
                'id' => $apiKey->getId(),
                'name' => $apiKey->getName(),
                'key' => $apiKeyValue, // Only shown once!
                'scopes' => $apiKey->getScopes(),
                'created' => $apiKey->getCreated()
            ],
            'message' => 'API key created successfully. Store it securely - it will not be shown again!'
        ], Response::HTTP_CREATED);
    }

    /**
     * Revoke (delete) API key
     * 
     * DELETE /api/v1/apikeys/{id}
     */
    #[Route('/{id}', name: 'revoke', methods: ['DELETE'])]
    public function revoke(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $apiKey = $this->apiKeyRepository->find($id);

        if (!$apiKey) {
            return $this->json([
                'success' => false,
                'error' => 'API key not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Check ownership
        if ($apiKey->getOwnerId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Access denied'
            ], Response::HTTP_FORBIDDEN);
        }

        $this->apiKeyRepository->remove($apiKey);

        return $this->json([
            'success' => true,
            'message' => 'API key revoked successfully'
        ]);
    }

    /**
     * Update API key (e.g., deactivate)
     * 
     * PATCH /api/v1/apikeys/{id}
     * Body: { "status": "inactive", "scopes": [...] }
     */
    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $apiKey = $this->apiKeyRepository->find($id);

        if (!$apiKey) {
            return $this->json([
                'success' => false,
                'error' => 'API key not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Check ownership
        if ($apiKey->getOwnerId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Access denied'
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['status'])) {
            if (!in_array($data['status'], ['active', 'inactive'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid status. Must be: active, inactive'
                ], Response::HTTP_BAD_REQUEST);
            }
            $apiKey->setStatus($data['status']);
        }

        if (isset($data['name'])) {
            $apiKey->setName($data['name']);
        }

        if (isset($data['scopes']) && is_array($data['scopes'])) {
            $apiKey->setScopes($data['scopes']);
        }

        $this->apiKeyRepository->save($apiKey);

        return $this->json([
            'success' => true,
            'api_key' => [
                'id' => $apiKey->getId(),
                'name' => $apiKey->getName(),
                'key_prefix' => substr($apiKey->getKey(), 0, 8) . '...',
                'status' => $apiKey->getStatus(),
                'scopes' => $apiKey->getScopes(),
                'last_used' => $apiKey->getLastUsed()
            ]
        ]);
    }
}

