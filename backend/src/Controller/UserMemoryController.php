<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Exception\ProviderException;
use App\Entity\User;
use App\Service\Digest\LongTermMemoryService;
use App\Service\Exception\MemoryServiceUnavailableException;
use App\Service\Memory\MemoryInputParser;
use App\Service\Memory\MemoryParseResult;
use App\Service\Memory\UserMemoryService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/user/memories')]
#[OA\Tag(name: 'User Memories')]
class UserMemoryController extends AbstractController
{
    /**
     * Stable machine-readable error codes for the memory parse endpoint.
     *
     * The frontend's MemoryFormDialog inspects these codes (via ApiError.details.code)
     * to decide whether a graceful fallback is possible:
     *  - AI_PARSER_UNAVAILABLE / AI_NO_CHAT_MODEL: storage works, so we can still save
     *    the input as a plain note (create) or fall back to manual edit (update).
     *  - MEMORY_STORAGE_UNAVAILABLE: nothing can be persisted, show a hard error.
     *
     * Keep these constants in sync with the codes consumed in
     * frontend/src/components/MemoryFormDialog.vue (see handleEasySubmit).
     */
    public const ERROR_CODE_MEMORY_STORAGE_UNAVAILABLE = 'memory_storage_unavailable';
    public const ERROR_CODE_AI_PARSER_UNAVAILABLE = 'ai_parser_unavailable';
    public const ERROR_CODE_AI_NO_CHAT_MODEL = 'ai_no_chat_model';
    public const ERROR_CODE_AI_PARSE_FAILED = 'ai_parse_failed';
    public const ERROR_CODE_INPUT_REQUIRED = 'input_required';

    public function __construct(
        private readonly UserMemoryService $memoryService,
        private readonly MemoryInputParser $memoryParser,
        private readonly LongTermMemoryService $longTermMemory,
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
            new OA\Response(response: 503, description: 'Memory service unavailable'),
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

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/user/memories/{id}',
        summary: 'Get a single memory by ID',
        description: 'Returns a single memory if it exists and belongs to the current user',
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
                description: 'Memory found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 123),
                        new OA\Property(property: 'category', type: 'string', example: 'preferences'),
                        new OA\Property(property: 'key', type: 'string', example: 'tech_stack'),
                        new OA\Property(property: 'value', type: 'string', example: 'Prefers TypeScript with Vue 3'),
                        new OA\Property(property: 'source', type: 'string', example: 'auto_detected'),
                        new OA\Property(property: 'messageId', type: 'integer', nullable: true, example: 456),
                        new OA\Property(property: 'created', type: 'integer', example: 1705234567),
                        new OA\Property(property: 'updated', type: 'integer', example: 1705234567),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Memory not found'),
            new OA\Response(response: 503, description: 'Memory service unavailable'),
        ]
    )]
    public function getMemory(
        int $id,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $memory = $this->memoryService->getMemoryById($id, $user);

        if (!$memory) {
            return $this->json(['error' => 'Memory not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($memory->toArray());
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
            new OA\Response(response: 503, description: 'Memory service unavailable'),
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
            new OA\Response(response: 503, description: 'Memory service unavailable'),
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

        if (
            !is_string($category) || '' === trim($category)
            || !is_string($key) || '' === trim($key)
            || !is_string($value) || '' === trim($value)
        ) {
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
        } catch (MemoryServiceUnavailableException $e) {
            return $this->memoryServiceUnavailableResponse($user, $e);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (ProviderException $e) {
            // AI provider not available (e.g., Ollama not running, model not loaded)
            // Only show technical details to admins
            $response = ['error' => 'Memory service temporarily unavailable'];
            if ($user->isAdmin()) {
                $response['debug'] = $e->getMessage();
            }

            return $this->json($response, Response::HTTP_SERVICE_UNAVAILABLE);
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
            new OA\Response(response: 503, description: 'Memory service unavailable'),
        ]
    )]
    public function updateMemory(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $data = $request->toArray();
        $value = $data['value'] ?? null;
        $category = $data['category'] ?? null;
        $key = $data['key'] ?? null;

        if (!is_string($value) || '' === trim($value)) {
            return $this->json([
                'error' => 'Missing required field: value',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate optional category and key if provided
        if (null !== $category && (!is_string($category) || '' === trim($category))) {
            return $this->json([
                'error' => 'Category must be a non-empty string',
            ], Response::HTTP_BAD_REQUEST);
        }
        if (null !== $key && (!is_string($key) || '' === trim($key))) {
            return $this->json([
                'error' => 'Key must be a non-empty string',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $memory = $this->memoryService->updateMemory(
                $id,
                $user,
                $value,
                'user_edited',
                null,
                $key,
                $category
            );

            return $this->json([
                'memory' => $memory->toArray(),
            ]);
        } catch (MemoryServiceUnavailableException $e) {
            return $this->memoryServiceUnavailableResponse($user, $e);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (ProviderException $e) {
            // Only show technical details to admins
            $response = ['error' => 'Memory service temporarily unavailable'];
            if ($user->isAdmin()) {
                $response['debug'] = $e->getMessage();
            }

            return $this->json($response, Response::HTTP_SERVICE_UNAVAILABLE);
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
            new OA\Response(response: 503, description: 'Memory service unavailable'),
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
        } catch (MemoryServiceUnavailableException $e) {
            return $this->memoryServiceUnavailableResponse($user, $e);
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
            new OA\Response(response: 503, description: 'Memory service unavailable'),
        ]
    )]
    public function searchMemories(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        if (null !== $response = $this->assertMemoryServiceAvailable($user)) {
            return $response;
        }

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
            'memories' => $memories,
        ]);
    }

    #[Route('/export', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/memories/export',
        summary: 'Export all user memories',
        description: 'Downloads every memory and every active long-term memory entry stored for the current user as JSON for data portability',
        tags: ['User Memories'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Memory export',
                content: new OA\JsonContent(
                    required: ['exportedAt', 'memories', 'longTermMemory'],
                    properties: [
                        new OA\Property(property: 'exportedAt', type: 'string', format: 'date-time'),
                        new OA\Property(
                            property: 'memories',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 123),
                                    new OA\Property(property: 'userId', type: 'integer', example: 42),
                                    new OA\Property(property: 'category', type: 'string', example: 'preferences'),
                                    new OA\Property(property: 'key', type: 'string', example: 'ui_theme'),
                                    new OA\Property(property: 'value', type: 'string', example: 'Prefers dark mode'),
                                    new OA\Property(property: 'source', type: 'string', example: 'user_created'),
                                    new OA\Property(property: 'messageId', type: 'integer', nullable: true, example: 456),
                                    new OA\Property(property: 'created', type: 'integer', example: 1705234567),
                                    new OA\Property(property: 'updated', type: 'integer', example: 1705234567),
                                    new OA\Property(property: 'active', type: 'boolean', example: true),
                                    new OA\Property(property: 'namespace', type: 'string', nullable: true, example: null),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(
                            property: 'longTermMemory',
                            type: 'array',
                            description: 'Active long-term memory entries, newest source message first',
                            items: new OA\Items(
                                required: ['title', 'messageId', 'chatId', 'channel', 'sourceDate'],
                                properties: [
                                    new OA\Property(property: 'title', type: 'string', example: 'office rent letter to realtor about the increase of payments'),
                                    new OA\Property(property: 'messageId', type: 'integer', example: 5678),
                                    new OA\Property(property: 'chatId', type: 'integer', nullable: true, example: 42),
                                    new OA\Property(property: 'channel', type: 'string', example: 'web'),
                                    new OA\Property(property: 'sourceDate', type: 'integer', example: 1747216800, description: 'Unix timestamp of the source message, in seconds'),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            ),
        ]
    )]
    public function exportMemories(#[CurrentUser] User $user): JsonResponse
    {
        $userId = $user->getId();
        if (!is_int($userId)) {
            throw new \LogicException('Authenticated user has no id.');
        }

        $response = $this->json([
            'exportedAt' => date(\DATE_ATOM),
            'memories' => $this->memoryService->exportUserMemories($userId),
            'longTermMemory' => $this->longTermMemory->exportEntries($userId),
        ]);
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="synaplan-memories-%s.json"', date('Y-m-d')),
        );

        return $response;
    }

    #[Route('/parse', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/memories/parse',
        summary: 'Parse natural language into structured memory',
        description: 'Uses AI to structure natural language input. Returns action (create/update/delete) and structured memory with similar memories for context.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['input'],
                properties: [
                    new OA\Property(property: 'input', type: 'string', example: 'I prefer dark mode in all applications'),
                    new OA\Property(property: 'suggestedCategory', type: 'string', example: 'preferences'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Parsed memory with suggested action',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'action', type: 'string', enum: ['create', 'update', 'delete'], example: 'create'),
                        new OA\Property(
                            property: 'memory',
                            properties: [
                                new OA\Property(property: 'category', type: 'string', example: 'preferences'),
                                new OA\Property(property: 'key', type: 'string', example: 'ui_theme'),
                                new OA\Property(property: 'value', type: 'string', example: 'Prefers dark mode'),
                            ],
                            type: 'object'
                        ),
                        new OA\Property(property: 'existingId', type: 'integer', example: 123, nullable: true),
                        new OA\Property(property: 'reason', type: 'string', example: 'Updating existing preference', nullable: true),
                        new OA\Property(property: 'similarMemories', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 503, description: 'Memory service unavailable'),
        ]
    )]
    public function parseMemory(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $data = $request->toArray();
        $input = $data['input'] ?? '';

        if (empty(trim($input))) {
            return $this->json([
                'error' => 'Input is required',
                'code' => self::ERROR_CODE_INPUT_REQUIRED,
            ], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->memoryParser->parse($user, $input, $data['suggestedCategory'] ?? null);

        return match ($result->outcome) {
            MemoryParseResult::NO_MODEL => $this->parseFailure($user, 'No memory model configured for AI structuring', self::ERROR_CODE_AI_NO_CHAT_MODEL, 'No default MEM or CHAT model configured for this user. Set one under Profile → AI Models, or as global default.', Response::HTTP_SERVICE_UNAVAILABLE),
            MemoryParseResult::NOT_UNDERSTOOD => $this->parseFailure($user, 'AI could not parse the input', self::ERROR_CODE_AI_PARSE_FAILED, 'AI response missing valid actions: '.$result->answer, Response::HTTP_UNPROCESSABLE_ENTITY),
            // The frontend uses the code to offer a fallback (save as a plain
            // note, or edit by hand): the memory store is still reachable.
            MemoryParseResult::PROVIDER_UNAVAILABLE => $this->parseFailure($user, 'AI service unavailable', self::ERROR_CODE_AI_PARSER_UNAVAILABLE, $result->providerError, Response::HTTP_SERVICE_UNAVAILABLE),
            default => $this->json([
                'actions' => $result->actions,
                'similarMemories' => $result->similarMemories,
            ] + ($user->isAdmin() ? ['_debug' => [
                'similarMemoriesCount' => count($result->similarMemories),
                'qdrantError' => $result->searchError,
                'aiResponse' => $result->answer,
            ]] : [])),
        };
    }

    /**
     * A parse that produced no actions: the error and its code, and for an
     * admin what went wrong.
     */
    private function parseFailure(User $user, string $error, string $code, string $debug, int $status): JsonResponse
    {
        $response = ['error' => $error, 'code' => $code];
        if ($user->isAdmin()) {
            $response['debug'] = $debug;
        }

        return $this->json($response, $status);
    }

    /**
     * Guard for endpoints that require a reachable memory backend.
     *
     * Returns null when the service is available and the caller should
     * continue, or a ready-to-return 503 JsonResponse otherwise. The
     * response payload matches {@see memoryServiceUnavailableResponse}
     * so the frontend's existing "memory service unavailable" UI triggers
     * consistently regardless of which endpoint produced the error.
     */
    private function assertMemoryServiceAvailable(User $user): ?JsonResponse
    {
        if ($this->memoryService->isAvailable()) {
            return null;
        }

        return $this->memoryServiceUnavailableResponse($user);
    }

    /**
     * Build the 503 response used whenever the Qdrant backend is unreachable.
     *
     * Regular users see a short, neutral message. Admins additionally receive
     * the raw technical detail in `debug`. The frontend httpClient already
     * renders the `debug` field with a "[Debug] " prefix, so the backend must
     * NOT add its own prefix — otherwise the user sees "[Debug] [Debug] …".
     * Matches the convention used by the {@see ProviderException} branches
     * in this controller.
     */
    private function memoryServiceUnavailableResponse(User $user, ?\Throwable $e = null): JsonResponse
    {
        $payload = [
            'error' => 'Memory service temporarily unavailable',
            'code' => self::ERROR_CODE_MEMORY_STORAGE_UNAVAILABLE,
        ];

        if ($user->isAdmin()) {
            $payload['debug'] = null !== $e
                ? $e->getMessage()
                : 'Qdrant client reports isAvailable() === false. Check QDRANT_URL and that the Qdrant container is reachable.';
        }

        return $this->json($payload, Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
