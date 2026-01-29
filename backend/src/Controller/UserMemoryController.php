<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\UserMemoryService;
use OpenApi\Attributes as OA;
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
        private readonly AiFacade $aiFacade,
        private readonly PromptService $promptService,
        private readonly ModelConfigService $modelConfigService,
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
                $category,
                $key
            );

            return $this->json([
                'memory' => $memory->toArray(),
            ]);
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
            'memories' => $memories,
        ]);
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
        ]
    )]
    public function parseMemory(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $data = $request->toArray();
        $input = $data['input'] ?? '';
        $suggestedCategory = $data['suggestedCategory'] ?? null;

        if (empty(trim($input))) {
            return $this->json([
                'error' => 'Input is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Search for similar memories using Qdrant
        $similarMemories = [];
        $qdrantError = null;
        try {
            $similarMemories = $this->memoryService->searchMemories($user, $input, null, 10);
        } catch (\Exception $e) {
            $qdrantError = $e->getMessage();
            // Continue without context but log for debugging
        }

        // Load prompt from database
        $promptData = $this->promptService->getPromptWithMetadata('tools:memory_parse', $user->getId());
        $promptEntity = $promptData['prompt'] ?? null;

        if (!$promptEntity) {
            $response = ['error' => 'Memory parse prompt not configured'];
            if ($user->isAdmin()) {
                $response['debug'] = 'Prompt "tools:memory_parse" not found in database. Run: php bin/console doctrine:fixtures:load --group=PromptFixtures --append';
            }

            return $this->json($response, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $systemPrompt = $promptEntity->getPrompt();

        // Build user message with context
        $userMessage = "User input: \"{$input}\"";

        if ($suggestedCategory) {
            $userMessage .= "\nSuggested category: {$suggestedCategory}";
        }

        if (!empty($similarMemories)) {
            $userMessage .= "\n\nExisting memories that might be related:\n";
            foreach ($similarMemories as $mem) {
                $userMessage .= sprintf(
                    "- ID: %d, Key: %s, Value: %s\n",
                    $mem['id'] ?? 0,
                    $mem['key'] ?? '',
                    $mem['value'] ?? ''
                );
            }
        } else {
            $userMessage .= "\n\nNo existing memories found.";
        }

        // Get user's default chat model
        $modelName = $this->getUserChatModel($user->getId());

        try {
            $response = $this->aiFacade->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                userId: $user->getId(),
                options: [
                    'json_mode' => true,
                    'model' => $modelName,
                    'temperature' => 0.3, // Low temperature for consistent JSON output
                ]
            );

            $content = $response['content'] ?? '';

            // Get ALL valid memory IDs for this user to validate AI suggestions
            // We need all user memories, not just similar ones, because AI might reference any
            $allUserMemories = $this->memoryService->getUserMemories($user->getId());
            $validMemoryIds = array_map(fn ($m) => $m->id, $allUserMemories);

            // Parse AI response - support multiple formats
            $actions = $this->parseAiResponse($content, $input, $validMemoryIds);

            if (empty($actions)) {
                $response = ['error' => 'AI could not parse the input'];
                if ($user->isAdmin()) {
                    $response['debug'] = 'AI response missing valid actions: '.$content;
                }

                return $this->json($response, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $result = [
                'actions' => $actions,
                'similarMemories' => $similarMemories,
            ];

            // Add debug info for admins
            if ($user->isAdmin()) {
                $result['_debug'] = [
                    'similarMemoriesCount' => count($similarMemories),
                    'qdrantError' => $qdrantError,
                    'aiResponse' => $content,
                ];
            }

            return $this->json($result);
        } catch (ProviderException $e) {
            // AI unavailable - return error, no fallback
            $response = ['error' => 'AI service unavailable'];
            if ($user->isAdmin()) {
                $response['debug'] = $e->getMessage();
            }

            return $this->json($response, Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * Parse AI response supporting multiple formats.
     *
     * Supports:
     * 1. Standard format: {"actions": [...]}
     * 2. Legacy single action: {"action": "create", "memory": {...}}
     * 3. NDJSON format: Multiple JSON objects on separate lines
     *
     * @param string $content        Raw AI response content
     * @param string $input          Original user input
     * @param int[]  $validMemoryIds Valid memory IDs for validation
     *
     * @return array Parsed actions
     */
    private function parseAiResponse(string $content, string $input, array $validMemoryIds): array
    {
        $actions = [];

        // Clean up the content - extract JSON from markdown code blocks if present
        $cleanContent = $this->extractJsonFromResponse($content);

        // Try standard JSON parse first
        $parsed = json_decode($cleanContent, true);

        if (null !== $parsed && \JSON_ERROR_NONE === json_last_error()) {
            // Format 1: {"actions": [...]}
            if (isset($parsed['actions']) && is_array($parsed['actions'])) {
                foreach ($parsed['actions'] as $actionData) {
                    $action = $this->parseActionData($actionData, $input, $validMemoryIds);
                    if ($action) {
                        $actions[] = $action;
                    }
                }

                return $actions;
            }

            // Format 2: Single action {"action": "create", "memory": {...}}
            if (isset($parsed['action'])) {
                $action = $this->parseActionData($parsed, $input, $validMemoryIds);
                if ($action) {
                    $actions[] = $action;
                }

                return $actions;
            }
        }

        // Try to repair common JSON errors and parse again
        $repairedContent = $this->repairJson($cleanContent);
        if ($repairedContent !== $cleanContent) {
            $parsed = json_decode($repairedContent, true);

            if (null !== $parsed && \JSON_ERROR_NONE === json_last_error()) {
                if (isset($parsed['actions']) && is_array($parsed['actions'])) {
                    foreach ($parsed['actions'] as $actionData) {
                        $action = $this->parseActionData($actionData, $input, $validMemoryIds);
                        if ($action) {
                            $actions[] = $action;
                        }
                    }

                    return $actions;
                }

                if (isset($parsed['action'])) {
                    $action = $this->parseActionData($parsed, $input, $validMemoryIds);
                    if ($action) {
                        $actions[] = $action;
                    }

                    return $actions;
                }
            }
        }

        // Format 3: NDJSON - multiple JSON objects on separate lines
        // This handles when AI returns:
        // {"action":"create",...}
        // {"action":"create",...}
        $lines = preg_split('/\r?\n/', trim($cleanContent));
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $lineData = json_decode($line, true);
            if (null === $lineData) {
                // Try to repair this line
                $repairedLine = $this->repairJson($line);
                $lineData = json_decode($repairedLine, true);
            }

            if (null !== $lineData && isset($lineData['action'])) {
                $action = $this->parseActionData($lineData, $input, $validMemoryIds);
                if ($action) {
                    $actions[] = $action;
                }
            }
        }

        return $actions;
    }

    /**
     * Extract JSON from AI response, handling markdown code blocks.
     */
    private function extractJsonFromResponse(string $content): string
    {
        $content = trim($content);

        // Remove markdown code blocks if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $content = trim($matches[1]);
        }

        return $content;
    }

    /**
     * Attempt to repair common JSON errors from AI responses.
     */
    private function repairJson(string $json): string
    {
        $json = trim($json);

        // Count brackets to find imbalance
        $openBraces = substr_count($json, '{');
        $closeBraces = substr_count($json, '}');
        $openBrackets = substr_count($json, '[');
        $closeBrackets = substr_count($json, ']');

        // Remove extra closing braces (common AI error: }}} instead of }})
        while ($closeBraces > $openBraces && str_contains($json, '}}')) {
            // Find and remove one extra }
            $json = preg_replace('/\}\}(\]|\})/', '}$1', $json, 1);
            $closeBraces = substr_count($json, '}');
        }

        // Remove extra closing brackets
        while ($closeBrackets > $openBrackets && str_contains($json, ']]')) {
            $json = preg_replace('/\]\]/', ']', $json, 1);
            $closeBrackets = substr_count($json, ']');
        }

        // Add missing closing braces at end
        while ($openBraces > $closeBraces) {
            $json .= '}';
            ++$closeBraces;
        }

        // Add missing closing brackets at end
        while ($openBrackets > $closeBrackets) {
            $json .= ']';
            ++$closeBrackets;
        }

        return $json;
    }

    /**
     * Parse a single action from AI response.
     *
     * @param array  $actionData     The action data from AI
     * @param string $input          Original user input
     * @param int[]  $validMemoryIds Valid memory IDs from similar memories search
     *
     * @return array|null Parsed action or null if invalid
     */
    private function parseActionData(array $actionData, string $input, array $validMemoryIds = []): ?array
    {
        $action = $actionData['action'] ?? null;

        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            return null;
        }

        // Validate existingId if provided - it must be a valid memory ID
        $existingId = null;
        if (isset($actionData['existingId'])) {
            $proposedId = (int) $actionData['existingId'];
            // Only accept the ID if it's in our list of valid memories
            if (in_array($proposedId, $validMemoryIds, true)) {
                $existingId = $proposedId;
            }
        }

        // For update/delete, we need a valid existingId
        // If AI suggested update/delete but the ID is invalid, convert to create
        if (in_array($action, ['update', 'delete'], true) && null === $existingId) {
            if ('delete' === $action) {
                // Can't delete without a valid ID, skip this action
                return null;
            }
            // Convert invalid update to create
            $action = 'create';
        }

        $result = ['action' => $action];

        // For create/update, memory field is required
        if (in_array($action, ['create', 'update'], true)) {
            if (!isset($actionData['memory'])) {
                return null;
            }

            $result['memory'] = [
                'category' => (string) ($actionData['memory']['category'] ?? 'preferences'),
                'key' => (string) ($actionData['memory']['key'] ?? 'note'),
                'value' => (string) ($actionData['memory']['value'] ?? trim($input)),
            ];
        }

        if (null !== $existingId) {
            $result['existingId'] = $existingId;
        }

        if (isset($actionData['reason'])) {
            $result['reason'] = (string) $actionData['reason'];
        }

        return $result;
    }

    /**
     * Get user's default chat model name.
     */
    private function getUserChatModel(int $userId): ?string
    {
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $userId);

        if ($modelId) {
            return $this->modelConfigService->getModelName($modelId);
        }

        return null;
    }
}
