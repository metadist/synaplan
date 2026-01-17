<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\UserMemoryService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/user/memories')]
#[OA\Tag(name: 'User Memories')]
class UserMemoryController extends AbstractController
{
    public function __construct(
        private readonly UserMemoryService $memoryService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/memories',
        summary: 'Get all user memories',
        description: 'Returns list of user memories, optionally filtered by category',
        parameters: [
            new OA\Parameter(
                name: 'category',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'preferences')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of memories',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'memories',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 123),
                                    new OA\Property(property: 'category', type: 'string', example: 'preferences'),
                                    new OA\Property(property: 'key', type: 'string', example: 'tech_stack'),
                                    new OA\Property(property: 'value', type: 'string', example: 'Prefers TypeScript with Vue 3'),
                                    new OA\Property(property: 'source', type: 'string', example: 'auto_detected'),
                                    new OA\Property(property: 'messageId', type: 'integer', nullable: true, example: 456),
                                    new OA\Property(property: 'created', type: 'integer', example: 1705234567),
                                    new OA\Property(property: 'updated', type: 'integer', example: 1705234567),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'total', type: 'integer', example: 42),
                    ]
                )
            ),
        ]
    )]
    public function getMemories(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $category = $request->query->get('category');

        $memories = $this->memoryService->getUserMemories($user->getId(), $category);

        return $this->json([
            'memories' => array_map(fn ($m) => $m->toArray(), $memories),
            'total' => count($memories),
        ]);
    }

    #[Route('/categories', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/memories/categories',
        summary: 'Get categories with memory counts',
        description: 'Returns list of categories and how many memories exist in each',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Categories with counts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'categories',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'category', type: 'string', example: 'preferences'),
                                    new OA\Property(property: 'count', type: 'integer', example: 15),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            ),
        ]
    )]
    public function getCategories(#[CurrentUser] User $user): JsonResponse
    {
        $categories = $this->memoryService->getCategoriesWithCounts($user);

        return $this->json([
            'categories' => $categories,
        ]);
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/memories',
        summary: 'Create a new memory',
        description: 'Manually create a user memory',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['category', 'key', 'value'],
                properties: [
                    new OA\Property(property: 'category', type: 'string', example: 'preferences'),
                    new OA\Property(property: 'key', type: 'string', example: 'tech_stack'),
                    new OA\Property(property: 'value', type: 'string', example: 'Prefers TypeScript with Vue 3'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Memory created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'memory',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 123),
                                new OA\Property(property: 'category', type: 'string', example: 'preferences'),
                                new OA\Property(property: 'key', type: 'string', example: 'tech_stack'),
                                new OA\Property(property: 'value', type: 'string', example: 'Prefers TypeScript with Vue 3'),
                                new OA\Property(property: 'source', type: 'string', example: 'user_created'),
                                new OA\Property(property: 'created', type: 'integer', example: 1705234567),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Memory key must be at least 3 characters'),
                    ]
                )
            ),
        ]
    )]
    public function createMemory(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $data = $request->toArray();

        $category = $data['category'] ?? null;
        $key = $data['key'] ?? null;
        $value = $data['value'] ?? null;

        if (!$category || !$key || !$value) {
            return $this->json([
                'error' => 'Missing required fields: category, key, value',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $memory = $this->memoryService->createMemory(
                $user,
                $category,
                $key,
                $value,
                'user_created'
            );

            return $this->json([
                'memory' => $memory->toArray(),
            ], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/memories/{id}',
        summary: 'Update a memory',
        description: 'Update memory value',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 123)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['value'],
                properties: [
                    new OA\Property(property: 'value', type: 'string', example: 'Updated value'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Memory updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'memory',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 123),
                                new OA\Property(property: 'value', type: 'string', example: 'Updated value'),
                                new OA\Property(property: 'source', type: 'string', example: 'user_edited'),
                                new OA\Property(property: 'updated', type: 'integer', example: 1705234567),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error or not found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Memory not found or access denied'),
                    ]
                )
            ),
        ]
    )]
    public function updateMemory(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $data = $request->toArray();
        $value = $data['value'] ?? null;

        if (!$value) {
            return $this->json([
                'error' => 'Missing required field: value',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $memory = $this->memoryService->updateMemory($id, $user, $value);

            return $this->json([
                'memory' => $memory->toArray(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/memories/{id}',
        summary: 'Delete a memory',
        description: 'Soft-delete a memory',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 123)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Memory deleted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Memory deleted'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Not found or access denied',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Memory not found or access denied'),
                    ]
                )
            ),
        ]
    )]
    public function deleteMemory(
        int $id,
        #[CurrentUser] User $user,
    ): JsonResponse {
        try {
            $this->memoryService->deleteMemory($id, $user);

            return $this->json([
                'success' => true,
                'message' => 'Memory deleted',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/search', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/memories/search',
        summary: 'Search memories semantically',
        description: 'Search memories using vector similarity (via Qdrant) or keyword fallback',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['query'],
                properties: [
                    new OA\Property(property: 'query', type: 'string', example: 'TypeScript preferences'),
                    new OA\Property(property: 'category', type: 'string', nullable: true, example: 'preferences'),
                    new OA\Property(property: 'limit', type: 'integer', example: 5),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Search results',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'memories',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 123),
                                    new OA\Property(property: 'category', type: 'string', example: 'preferences'),
                                    new OA\Property(property: 'key', type: 'string', example: 'tech_stack'),
                                    new OA\Property(property: 'value', type: 'string', example: 'Prefers TypeScript with Vue 3'),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            ),
        ]
    )]
    public function searchMemories(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $data = $request->toArray();

        $query = $data['query'] ?? '';
        $category = $data['category'] ?? null;
        $limit = isset($data['limit']) ? (int) $data['limit'] : 5;

        if (empty($query)) {
            return $this->json([
                'error' => 'Query parameter is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $memories = $this->memoryService->searchMemories($user, $query, $category, $limit);

        return $this->json([
            'memories' => array_map(fn ($m) => $m->toArray(), $memories),
        ]);
    }
}
