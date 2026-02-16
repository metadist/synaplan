<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Task Prompts Management.
 *
 * Allows users to view system prompts and create their own custom prompts
 * User prompts override system prompts for the same topic
 */
#[Route('/api/v1/prompts', name: 'api_prompts_')]
class PromptController extends AbstractController
{
    /**
     * Supported languages for sorting prompt rendering.
     */
    private const SUPPORTED_LANGUAGES = ['de', 'en', 'it', 'es', 'fr', 'nl', 'pt', 'ru', 'sv', 'tr'];

    public function __construct(
        private PromptRepository $promptRepository,
        private PromptMetaRepository $promptMetaRepository,
        private PromptService $promptService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private AiFacade $aiFacade,
        private MessageRepository $messageRepository,
        private FileRepository $fileRepository,
        private ModelConfigService $modelConfigService,
        private VectorStorageFacade $vectorStorageFacade,
    ) {
    }

    // Dependencies will be injected via method parameters for file endpoints

    /**
     * List all accessible prompts (system + user-specific).
     *
     * GET /api/v1/prompts
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/prompts',
        summary: 'List all accessible task prompts for current user',
        description: 'Returns system prompts (default) and user-specific prompts. User prompts override system prompts for the same topic.',
        tags: ['Task Prompts'],
        parameters: [
            new OA\Parameter(
                name: 'language',
                in: 'query',
                required: false,
                description: 'Language code (default: en)',
                schema: new OA\Schema(type: 'string', example: 'en')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of prompts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'prompts',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'topic', type: 'string', example: 'mediamaker'),
                                    new OA\Property(property: 'name', type: 'string', example: 'Media Generation'),
                                    new OA\Property(property: 'shortDescription', type: 'string', example: 'Generate images, videos, or audio files'),
                                    new OA\Property(property: 'prompt', type: 'string', example: 'You are a media generation assistant...'),
                                    new OA\Property(property: 'language', type: 'string', example: 'en'),
                                    new OA\Property(property: 'isDefault', type: 'boolean', example: true, description: 'True if this is a system prompt'),
                                    new OA\Property(property: 'isUserOverride', type: 'boolean', example: false, description: 'True if user has customized this prompt'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function list(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $language = $request->query->get('language', 'en');

        // Get all system prompts (ownerId = 0, excluding tools:*)
        // No language filter: system prompts are always visible regardless of UI language
        $systemPrompts = $this->promptRepository->createQueryBuilder('p')
            ->where('p.ownerId = 0')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->setParameter('toolsPrefix', 'tools:%')
            ->orderBy('p.topic', 'ASC')
            ->getQuery()
            ->getResult();

        // Get all user-specific prompts
        // Widget prompts (w_*) are not language-filtered — they are always visible
        // regardless of UI language, similar to system prompts
        $userPrompts = $this->promptRepository->createQueryBuilder('p')
            ->where('p.ownerId = :userId')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->andWhere('p.language = :lang OR p.topic LIKE :widgetPrefix')
            ->setParameter('userId', $user->getId())
            ->setParameter('lang', $language)
            ->setParameter('toolsPrefix', 'tools:%')
            ->setParameter('widgetPrefix', 'w\\_%')
            ->orderBy('p.topic', 'ASC')
            ->getQuery()
            ->getResult();

        // Build result: user prompts override system prompts
        $promptsMap = [];

        // First add all system prompts
        /** @var Prompt $prompt */
        foreach ($systemPrompts as $prompt) {
            $metadata = $this->promptService->loadMetadataForPrompt($prompt->getId());

            $promptsMap[$prompt->getTopic()] = [
                'id' => $prompt->getId(),
                'topic' => $prompt->getTopic(),
                'name' => $this->formatPromptName($prompt->getTopic(), $prompt->getShortDescription()),
                'shortDescription' => $prompt->getShortDescription(),
                'prompt' => $prompt->getPrompt(),
                'selectionRules' => $prompt->getSelectionRules(),
                'language' => $prompt->getLanguage(),
                'isDefault' => true,
                'isUserOverride' => false,
                'metadata' => $metadata,
            ];
        }

        // Then override with user prompts
        foreach ($userPrompts as $prompt) {
            $topic = $prompt->getTopic();
            $hasSystemVersion = isset($promptsMap[$topic]);
            $metadata = $this->promptService->loadMetadataForPrompt($prompt->getId());

            $promptsMap[$topic] = [
                'id' => $prompt->getId(),
                'topic' => $topic,
                'name' => $this->formatPromptName($topic, $prompt->getShortDescription(), false),
                'shortDescription' => $prompt->getShortDescription(),
                'prompt' => $prompt->getPrompt(),
                'selectionRules' => $prompt->getSelectionRules(),
                'language' => $prompt->getLanguage(),
                'isDefault' => false,
                'isUserOverride' => $hasSystemVersion,
                'metadata' => $metadata,
            ];
        }

        return $this->json([
            'success' => true,
            'prompts' => array_values($promptsMap),
        ]);
    }

    /**
     * Get sorting prompt with dynamic categories.
     *
     * GET /api/v1/prompts/sorting
     */
    #[Route('/sorting', name: 'sorting_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/prompts/sorting',
        summary: 'Get sorting prompt with dynamic categories',
        description: 'Returns the tools:sort prompt with dynamic topic list and categories for display.',
        tags: ['Task Prompts'],
        parameters: [
            new OA\Parameter(
                name: 'language',
                in: 'query',
                required: false,
                description: 'Language code (default: en)',
                schema: new OA\Schema(type: 'string', example: 'en')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sorting prompt data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'prompt',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 2),
                                new OA\Property(property: 'topic', type: 'string', example: 'tools:sort'),
                                new OA\Property(property: 'shortDescription', type: 'string'),
                                new OA\Property(property: 'prompt', type: 'string'),
                                new OA\Property(property: 'renderedPrompt', type: 'string'),
                                new OA\Property(
                                    property: 'categories',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'name', type: 'string'),
                                            new OA\Property(property: 'description', type: 'string'),
                                            new OA\Property(property: 'type', type: 'string', enum: ['default', 'custom']),
                                        ]
                                    )
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Sorting prompt not found'),
        ]
    )]
    public function getSortingPrompt(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $language = $request->query->get('language', 'en');
        $sortingPrompt = $this->promptRepository->findByTopic('tools:sort', 0);

        if (!$sortingPrompt) {
            return $this->json(['error' => 'Sorting prompt not found'], Response::HTTP_NOT_FOUND);
        }

        $userId = $user->getId();
        $topics = $this->promptRepository->getAllTopics(0, $userId, excludeTools: true);
        $topicsWithDesc = $this->promptRepository->getTopicsWithDescriptions(0, $language, $userId, excludeTools: true);

        $dynamicList = $this->buildDynamicList($topicsWithDesc);
        $keyList = implode(' | ', array_map(fn ($topic) => '"'.$topic.'"', $topics));
        $langList = implode(' | ', array_map(fn ($lang) => '"'.$lang.'"', self::SUPPORTED_LANGUAGES));

        $renderedPrompt = $sortingPrompt->getPrompt();
        $renderedPrompt = str_replace('[DYNAMICLIST]', $dynamicList, $renderedPrompt);
        $renderedPrompt = str_replace('[KEYLIST]', $keyList, $renderedPrompt);
        $renderedPrompt = str_replace('[LANGLIST]', $langList, $renderedPrompt);

        return $this->json([
            'success' => true,
            'prompt' => [
                'id' => $sortingPrompt->getId(),
                'topic' => $sortingPrompt->getTopic(),
                'shortDescription' => $sortingPrompt->getShortDescription(),
                'prompt' => $sortingPrompt->getPrompt(),
                'renderedPrompt' => $renderedPrompt,
                'categories' => $this->buildSortingCategories($userId, $language),
            ],
        ]);
    }

    /**
     * Update sorting prompt (admin only).
     *
     * PUT /api/v1/prompts/sorting
     */
    #[Route('/sorting', name: 'sorting_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/prompts/sorting',
        summary: 'Update sorting prompt',
        description: 'Update the tools:sort prompt content (admin only).',
        security: [['Bearer' => []]],
        tags: ['Task Prompts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['prompt'],
                properties: [
                    new OA\Property(property: 'prompt', type: 'string'),
                    new OA\Property(property: 'shortDescription', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sorting prompt updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'prompt',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'topic', type: 'string'),
                                new OA\Property(property: 'shortDescription', type: 'string'),
                                new OA\Property(property: 'prompt', type: 'string'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid payload'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Admin access required'),
            new OA\Response(response: 404, description: 'Sorting prompt not found'),
        ]
    )]
    public function updateSortingPrompt(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['prompt']) || !is_string($data['prompt'])) {
            return $this->json(['error' => 'Missing required field: prompt'], Response::HTTP_BAD_REQUEST);
        }

        $sortingPrompt = $this->promptRepository->findByTopic('tools:sort', 0);
        if (!$sortingPrompt) {
            return $this->json(['error' => 'Sorting prompt not found'], Response::HTTP_NOT_FOUND);
        }

        $sortingPrompt->setPrompt($data['prompt']);
        if (!empty($data['shortDescription']) && is_string($data['shortDescription'])) {
            $sortingPrompt->setShortDescription($data['shortDescription']);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'prompt' => [
                'id' => $sortingPrompt->getId(),
                'topic' => $sortingPrompt->getTopic(),
                'shortDescription' => $sortingPrompt->getShortDescription(),
                'prompt' => $sortingPrompt->getPrompt(),
            ],
        ]);
    }

    /**
     * Get all available files (vectorized) for user.
     *
     * GET /api/v1/prompts/available-files
     *
     * IMPORTANT: This route must be BEFORE /{id} route to avoid conflicts!
     */
    #[Route('/available-files', name: 'available_files', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/prompts/available-files',
        summary: 'Get all vectorized files available for linking',
        description: 'Returns all files that have been uploaded and vectorized by the user',
        tags: ['Task Prompts'],
        parameters: [
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Search filter for filename',
                schema: new OA\Schema(type: 'string', example: 'customer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of available files',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'files',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'messageId', type: 'integer', example: 123),
                                    new OA\Property(property: 'fileName', type: 'string', example: 'customer-faq.pdf'),
                                    new OA\Property(property: 'chunks', type: 'integer', example: 15),
                                    new OA\Property(property: 'currentGroupKey', type: 'string', example: 'DEFAULT'),
                                    new OA\Property(property: 'uploadedAt', type: 'string', example: '2024-01-15T10:30:00Z'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function getAvailableFiles(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $searchQuery = $request->query->get('search', '');

        $filesByFileId = [];

        // Get per-file chunk info from active vector storage (Qdrant or MariaDB)
        try {
            $filesWithChunks = $this->vectorStorageFacade->getFilesWithChunks($user->getId());
        } catch (\Throwable $e) {
            $this->logger->warning('PromptController: Failed to get files with chunks from vector storage', [
                'error' => $e->getMessage(),
            ]);
            $filesWithChunks = [];
        }

        // Build file list from vector storage results
        foreach ($filesWithChunks as $fileId => $info) {
            $file = $this->fileRepository->find($fileId);
            if (!$file || $file->getUserId() !== $user->getId()) {
                continue;
            }

            $fileName = $file->getFileName();

            // Apply search filter
            if (!empty($searchQuery) && false === stripos($fileName, $searchQuery)) {
                continue;
            }

            $filesByFileId[$fileId] = [
                'messageId' => $fileId, // Keep as messageId for frontend compatibility
                'fileName' => $fileName,
                'chunks' => $info['chunks'],
                'currentGroupKey' => $info['groupKey'] ?? 'default',
                'uploadedAt' => $file->getCreatedAt()
                    ? date('Y-m-d\TH:i:s\Z', $file->getCreatedAt())
                    : null,
            ];
        }

        // Also include files with 'vectorized' status that may not appear in vector storage yet
        $vectorizedFiles = $this->fileRepository->findBy([
            'userId' => $user->getId(),
            'status' => 'vectorized',
        ]);

        foreach ($vectorizedFiles as $file) {
            $fileId = $file->getId();
            if (!isset($filesByFileId[$fileId])) {
                $fileName = $file->getFileName();

                // Apply search filter
                if (!empty($searchQuery) && false === stripos($fileName, $searchQuery)) {
                    continue;
                }

                $filesByFileId[$fileId] = [
                    'messageId' => $fileId,
                    'fileName' => $fileName,
                    'chunks' => 0,
                    'currentGroupKey' => 'default',
                    'uploadedAt' => $file->getCreatedAt()
                        ? date('Y-m-d\TH:i:s\Z', $file->getCreatedAt())
                        : null,
                ];
            }
        }

        // Also include files with 'extracted' status (have text, can be vectorized)
        $extractedFiles = $this->fileRepository->findBy([
            'userId' => $user->getId(),
            'status' => 'extracted',
        ]);

        foreach ($extractedFiles as $file) {
            $fileId = $file->getId();
            if (!isset($filesByFileId[$fileId])) {
                $fileName = $file->getFileName();

                // Apply search filter
                if (!empty($searchQuery) && false === stripos($fileName, $searchQuery)) {
                    continue;
                }

                // Only include if file has extracted text
                if (!empty($file->getFileText())) {
                    $filesByFileId[$fileId] = [
                        'messageId' => $fileId,
                        'fileName' => $fileName,
                        'chunks' => 0,
                        'currentGroupKey' => 'default',
                        'uploadedAt' => $file->getCreatedAt()
                            ? date('Y-m-d\TH:i:s\Z', $file->getCreatedAt())
                            : null,
                    ];
                }
            }
        }

        return $this->json([
            'success' => true,
            'files' => array_values($filesByFileId),
        ]);
    }

    /**
     * Get a specific prompt by ID.
     *
     * GET /api/v1/prompts/{id}
     */
    #[Route('/{id}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/prompts/{id}',
        summary: 'Get a specific prompt by ID',
        tags: ['Task Prompts'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Prompt ID',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Prompt details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'prompt', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied - not your prompt'),
            new OA\Response(response: 404, description: 'Prompt not found'),
        ]
    )]
    public function get(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $prompt = $this->promptRepository->find($id);

        if (!$prompt) {
            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }

        // Check access: user can only access system prompts (ownerId=0) or their own prompts
        if (0 !== $prompt->getOwnerId() && $prompt->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'prompt' => [
                'id' => $prompt->getId(),
                'topic' => $prompt->getTopic(),
                'name' => $this->formatPromptName($prompt->getTopic(), $prompt->getShortDescription(), 0 === $prompt->getOwnerId()),
                'shortDescription' => $prompt->getShortDescription(),
                'prompt' => $prompt->getPrompt(),
                'language' => $prompt->getLanguage(),
                'isDefault' => 0 === $prompt->getOwnerId(),
            ],
        ]);
    }

    /**
     * Create a new user-specific prompt.
     *
     * POST /api/v1/prompts
     * Body: {
     *   "topic": "custom-task",
     *   "shortDescription": "My custom task",
     *   "prompt": "You are...",
     *   "language": "en"
     * }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/prompts',
        summary: 'Create a new user-specific prompt',
        description: 'Create a custom prompt. If a system prompt with the same topic exists, the user prompt will override it.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['topic', 'shortDescription', 'prompt'],
                properties: [
                    new OA\Property(property: 'topic', type: 'string', example: 'custom-analyzer', description: 'Unique topic identifier'),
                    new OA\Property(property: 'shortDescription', type: 'string', example: 'Custom file analyzer', description: 'Short description for the prompt'),
                    new OA\Property(property: 'prompt', type: 'string', example: 'You are a specialized file analyzer...', description: 'The actual prompt content'),
                    new OA\Property(property: 'language', type: 'string', example: 'en', description: 'Language code (default: en)'),
                ]
            )
        ),
        tags: ['Task Prompts'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Prompt created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'prompt', type: 'object'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 409, description: 'Prompt with this topic already exists for this user'),
        ]
    )]
    public function create(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        try {
            if (!$user) {
                $this->logger->error('PromptController::create - No user authenticated');

                return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
            }

            $data = json_decode($request->getContent(), true);

            $this->logger->info('🔵 CREATE PROMPT REQUEST', [
                'data' => $data,
                'user_id' => $user->getId(),
            ]);

            // Validate required fields
            if (empty($data['topic']) || empty($data['shortDescription']) || empty($data['prompt'])) {
                $this->logger->warning('PromptController::create - Missing required fields', ['data' => $data]);

                return $this->json([
                    'error' => 'Missing required fields: topic, shortDescription, prompt',
                ], Response::HTTP_BAD_REQUEST);
            }

            $topic = trim($data['topic']);
            $shortDescription = trim($data['shortDescription']);
            $promptContent = trim($data['prompt']);
            $language = $data['language'] ?? 'en';
            $selectionRules = isset($data['selectionRules']) ? trim($data['selectionRules']) : null;
            $metadata = $data['metadata'] ?? [];

            // Prevent creating tool prompts (reserved for system)
            if (str_starts_with($topic, 'tools:')) {
                return $this->json([
                    'error' => 'Cannot create prompts with "tools:" prefix - reserved for system',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if user already has a prompt with this topic
            $existingPrompt = $this->promptRepository->findByTopic($topic, $user->getId());
            if ($existingPrompt) {
                return $this->json([
                    'error' => 'You already have a prompt with this topic. Use PUT /api/v1/prompts/{id} to update it.',
                ], Response::HTTP_CONFLICT);
            }

            // Create new prompt
            $prompt = new Prompt();
            $prompt->setOwnerId($user->getId());
            $prompt->setTopic($topic);
            $prompt->setShortDescription($shortDescription);
            $prompt->setPrompt($promptContent);
            $prompt->setLanguage($language);
            $prompt->setSelectionRules($selectionRules);

            $this->em->persist($prompt);
            $this->em->flush();

            // Refresh to ensure ID is populated
            $this->em->refresh($prompt);

            $this->logger->info('🟢 PROMPT CREATED', [
                'prompt_id' => $prompt->getId(),
                'topic' => $topic,
                'has_metadata' => !empty($metadata),
            ]);

            // Save metadata (AI model, tools)
            if (!empty($metadata)) {
                if (!$prompt->getId()) {
                    throw new \RuntimeException('Prompt ID is null after flush and refresh!');
                }

                $this->logger->info('🔵 SAVING METADATA', [
                    'prompt_id' => $prompt->getId(),
                    'metadata' => $metadata,
                ]);
                $this->promptService->saveMetadataForPrompt($prompt, $metadata);
                $this->logger->info('🟢 METADATA SAVED');
            }

            $this->logger->info('User created custom prompt', [
                'user_id' => $user->getId(),
                'prompt_id' => $prompt->getId(),
                'topic' => $topic,
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Prompt created successfully',
                'prompt' => [
                    'id' => $prompt->getId(),
                    'topic' => $prompt->getTopic(),
                    'name' => $this->formatPromptName($topic, $shortDescription, false),
                    'shortDescription' => $prompt->getShortDescription(),
                    'prompt' => $prompt->getPrompt(),
                    'language' => $prompt->getLanguage(),
                    'isDefault' => false,
                ],
            ], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error('❌ PromptController::create - Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'error' => 'Failed to create prompt: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing user-specific prompt.
     *
     * PUT /api/v1/prompts/{id}
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/prompts/{id}',
        summary: 'Update an existing user-specific prompt',
        description: 'Update a custom prompt. You can only update your own prompts, not system prompts.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'shortDescription', type: 'string', example: 'Updated description'),
                    new OA\Property(property: 'prompt', type: 'string', example: 'Updated prompt content...'),
                ]
            )
        ),
        tags: ['Task Prompts'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Prompt updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'prompt', type: 'object'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Cannot modify system prompts'),
            new OA\Response(response: 404, description: 'Prompt not found'),
        ]
    )]
    public function update(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        $this->logger->info('PromptController::update called', [
            'prompt_id' => $id,
            'user_id' => $user?->getId(),
        ]);

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $prompt = $this->promptRepository->find($id);

        if (!$prompt) {
            $this->logger->error('Prompt not found', ['id' => $id]);

            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }

        // Check ownership: only user's own prompts can be updated (admins can also update system prompts)
        if ($prompt->getOwnerId() !== $user->getId() && !($user->isAdmin() && 0 === $prompt->getOwnerId())) {
            $this->logger->warning('User tried to update prompt they don\'t own', [
                'user_id' => $user->getId(),
                'prompt_owner' => $prompt->getOwnerId(),
                'prompt_id' => $id,
            ]);

            return $this->json([
                'error' => 'Cannot modify this prompt. You can only modify your own custom prompts.',
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        $this->logger->info('Update data received', [
            'prompt_id' => $id,
            'has_metadata' => isset($data['metadata']),
            'metadata_keys' => isset($data['metadata']) ? array_keys($data['metadata']) : [],
        ]);

        // Update fields if provided
        if (isset($data['shortDescription'])) {
            $prompt->setShortDescription(trim($data['shortDescription']));
        }

        if (isset($data['prompt'])) {
            $prompt->setPrompt(trim($data['prompt']));
        }

        if (isset($data['selectionRules'])) {
            $prompt->setSelectionRules(trim($data['selectionRules']) ?: null);
        }

        // Only update language for user-owned prompts (not system prompts)
        // System prompts (ownerId=0) should keep their original language to prevent corruption
        if (isset($data['language']) && 0 !== $prompt->getOwnerId()) {
            $lang = strtolower(trim($data['language']));
            if ('' !== $lang && 2 === strlen($lang)) {
                $prompt->setLanguage($lang);
            }
        }

        try {
            $this->em->flush();
            $this->logger->info('Prompt entity flushed successfully', ['prompt_id' => $id]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to flush prompt entity', [
                'prompt_id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Update metadata (AI model, tools) if provided
        if (isset($data['metadata'])) {
            try {
                $this->logger->info('Saving metadata', [
                    'prompt_id' => $prompt->getId(),
                    'metadata' => $data['metadata'],
                ]);
                $this->promptService->saveMetadataForPrompt($prompt, $data['metadata']);
                $this->logger->info('Metadata saved successfully', ['prompt_id' => $prompt->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to save metadata', [
                    'prompt_id' => $prompt->getId(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        }

        $this->logger->info('User updated custom prompt', [
            'user_id' => $user->getId(),
            'prompt_id' => $prompt->getId(),
            'topic' => $prompt->getTopic(),
        ]);

        $isSystemPrompt = 0 === $prompt->getOwnerId();

        return $this->json([
            'success' => true,
            'message' => 'Prompt updated successfully',
            'prompt' => [
                'id' => $prompt->getId(),
                'topic' => $prompt->getTopic(),
                'name' => $this->formatPromptName($prompt->getTopic(), $prompt->getShortDescription(), $isSystemPrompt),
                'shortDescription' => $prompt->getShortDescription(),
                'prompt' => $prompt->getPrompt(),
                'language' => $prompt->getLanguage(),
                'isDefault' => $isSystemPrompt,
            ],
        ]);
    }

    /**
     * Delete a user-specific prompt.
     *
     * DELETE /api/v1/prompts/{id}
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/prompts/{id}',
        summary: 'Delete a user-specific prompt',
        description: 'Delete a custom prompt. You can only delete your own prompts. After deletion, the system default (if exists) will be used again.',
        tags: ['Task Prompts'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Prompt deleted successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Cannot delete system prompts'),
            new OA\Response(response: 404, description: 'Prompt not found'),
        ]
    )]
    public function delete(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $prompt = $this->promptRepository->find($id);

        if (!$prompt) {
            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }

        // Check ownership: only user's own prompts can be deleted (admins can also delete system prompts)
        if ($prompt->getOwnerId() !== $user->getId() && !($user->isAdmin() && 0 === $prompt->getOwnerId())) {
            return $this->json([
                'error' => 'Cannot delete this prompt. You can only delete your own custom prompts.',
            ], Response::HTTP_FORBIDDEN);
        }

        $topic = $prompt->getTopic();

        // Delete metadata entries first (to avoid foreign key constraint violation)
        $metaEntries = $this->promptMetaRepository->findBy(['promptId' => $prompt->getId()]);
        foreach ($metaEntries as $meta) {
            $this->em->remove($meta);
        }

        // Flush metadata deletions first
        if (!empty($metaEntries)) {
            $this->em->flush();
        }

        // Now delete the prompt itself
        $this->em->remove($prompt);
        $this->em->flush();

        $this->logger->info('User deleted custom prompt', [
            'user_id' => $user->getId(),
            'prompt_id' => $id,
            'topic' => $topic,
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Prompt deleted successfully. System default (if exists) will be used.',
        ]);
    }

    /**
     * Format prompt name for display.
     */
    private function formatPromptName(string $topic, string $shortDescription, bool $isDefault = true): string
    {
        $prefix = $isDefault ? '(default)' : '(custom)';
        $truncatedDesc = strlen($shortDescription) > 60
            ? substr($shortDescription, 0, 57).'...'
            : $shortDescription;

        return "{$prefix} {$topic} - {$truncatedDesc}";
    }

    /**
     * Build dynamic list of topics with descriptions.
     *
     * @param array<int, array{topic: string, description: string}> $topicsWithDesc
     */
    private function buildDynamicList(array $topicsWithDesc): string
    {
        $list = [];
        foreach ($topicsWithDesc as $item) {
            $list[] = "- \"{$item['topic']}\": {$item['description']}";
        }

        return implode("\n", $list);
    }

    /**
     * Reconstruct file text from RAG chunks (legacy fallback).
     *
     * For files uploaded before fileText was stored in the entity,
     * the extracted text may only exist as RAG chunks in the BRAG table.
     * This method assembles them back into a single string.
     */
    private function reconstructTextFromRagChunks(int $userId, int $fileId): string
    {
        try {
            $conn = $this->em->getConnection();
            $sql = 'SELECT BTEXT FROM BRAG WHERE BUID = :userId AND BMID = :fileId ORDER BY BCHUNK ASC';
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('userId', $userId);
            $stmt->bindValue('fileId', $fileId);
            $rows = $stmt->executeQuery()->fetchAllAssociative();

            if (empty($rows)) {
                return '';
            }

            $this->logger->info('PromptController: Reconstructed text from RAG chunks (legacy fallback)', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'chunks' => count($rows),
            ]);

            return implode("\n\n", array_filter(
                array_map(fn (array $row) => $row['BTEXT'] ?? '', $rows)
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('PromptController: Failed to reconstruct text from RAG chunks', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Build sorting categories (default vs custom) for UI display.
     *
     * @return array<int, array{name: string, description: string, type: string}>
     */
    private function buildSortingCategories(int $userId, string $language): array
    {
        $prompts = $this->promptRepository->findAllForUser($userId, $language);
        $categories = [];
        $seen = [];

        foreach ($prompts as $prompt) {
            $topic = $prompt->getTopic();
            if (isset($seen[$topic])) {
                continue;
            }

            $categories[] = [
                'name' => $topic,
                'description' => $prompt->getShortDescription(),
                'type' => 0 === $prompt->getOwnerId() ? 'default' : 'custom',
            ];
            $seen[$topic] = true;
        }

        return $categories;
    }

    /**
     * Get all files associated with a task prompt (via BGROUPKEY).
     *
     * GET /api/v1/prompts/{topic}/files
     */
    #[Route('/{topic}/files', name: 'files', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/prompts/{topic}/files',
        summary: 'Get all files/documents associated with a task prompt',
        description: 'Returns list of files that have been uploaded and vectorized for this task prompt. These files provide RAG context when using the prompt.',
        tags: ['Task Prompts'],
        parameters: [
            new OA\Parameter(
                name: 'topic',
                in: 'path',
                required: true,
                description: 'Task prompt topic (e.g., "general", "customersupport")',
                schema: new OA\Schema(type: 'string', example: 'customersupport')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of files for this prompt',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'files',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'messageId', type: 'integer', example: 123),
                                    new OA\Property(property: 'fileName', type: 'string', example: 'customer-faq.pdf'),
                                    new OA\Property(property: 'chunks', type: 'integer', example: 15, description: 'Number of text chunks'),
                                    new OA\Property(property: 'uploadedAt', type: 'string', example: '2024-01-15T10:30:00Z'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Prompt not found'),
        ]
    )]
    public function getFiles(
        string $topic,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Verify prompt exists and user has access
        $prompt = $this->promptRepository->findByTopicAndUser($topic, $user->getId());
        if (!$prompt) {
            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }

        // Build groupKey for this task prompt
        $groupKey = "TASKPROMPT:{$topic}";

        // Get files with chunks for this specific group key directly.
        // This uses a targeted query (by groupKey) instead of fetching ALL files
        // and filtering, which avoids stale groupKey issues with Qdrant.
        try {
            $filesWithChunks = $this->vectorStorageFacade->getFilesWithChunksByGroupKey(
                $user->getId(),
                $groupKey
            );
        } catch (\Throwable $e) {
            $this->logger->warning('PromptController: Failed to get files for group key', [
                'group_key' => $groupKey,
                'error' => $e->getMessage(),
            ]);
            $filesWithChunks = [];
        }

        // Build file list from targeted query results
        $filesByFileId = [];
        foreach ($filesWithChunks as $fileId => $info) {
            $file = $this->fileRepository->find($fileId);
            if (!$file || $file->getUserId() !== $user->getId()) {
                continue;
            }

            $filesByFileId[$fileId] = [
                'messageId' => $fileId, // Keep as messageId for frontend compatibility
                'fileName' => $file->getFileName(),
                'chunks' => $info['chunks'],
                'uploadedAt' => $file->getCreatedAt()
                    ? date('Y-m-d\TH:i:s\Z', $file->getCreatedAt())
                    : null,
            ];
        }

        return $this->json([
            'success' => true,
            'files' => array_values($filesByFileId),
            'groupKey' => $groupKey,
        ]);
    }

    /**
     * Delete a file from task prompt.
     *
     * DELETE /api/v1/prompts/{topic}/files/{messageId}
     */
    #[Route('/{topic}/files/{messageId}', name: 'delete_file', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/prompts/{topic}/files/{messageId}',
        summary: 'Delete a file from task prompt knowledge base',
        description: 'Removes all vectorized chunks associated with this file from the task prompt.',
        tags: ['Task Prompts'],
        parameters: [
            new OA\Parameter(
                name: 'topic',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', example: 'customersupport')
            ),
            new OA\Parameter(
                name: 'messageId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 123)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'File deleted successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'chunksDeleted', type: 'integer', example: 15),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Not authorized'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function deleteFile(
        string $topic,
        int $messageId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Delete all chunks via facade
        // Note: deleteByFile deletes ALL chunks for the file, regardless of groupKey.
        // The original implementation checked for groupKey="TASKPROMPT:$topic".
        // But since a file can only belong to one group at a time (in current model),
        // deleting by file ID is safe and correct.
        // If we want to be strict about groupKey, we could use deleteByGroupKey but that deletes ALL files in group.
        // Or we need a deleteByFileAndGroupKey method in interface.
        // But wait, `linkFile` changes the groupKey of the file. So the file IS in that group.
        // So `deleteByFile` is correct.

        $chunksDeleted = $this->vectorStorageFacade->deleteByFile($user->getId(), $messageId);

        if (0 === $chunksDeleted) {
            // Maybe check if file exists first to return 404?
            // But for now, idempotent success is fine.
        }

        $this->logger->info('Deleted file from task prompt', [
            'user_id' => $user->getId(),
            'topic' => $topic,
            'message_id' => $messageId,
            'chunks_deleted' => $chunksDeleted,
            'provider' => $this->vectorStorageFacade->getProviderName(),
        ]);

        return $this->json([
            'success' => true,
            'chunksDeleted' => $chunksDeleted,
            'message' => 'File removed from task prompt knowledge base',
        ]);
    }

    /**
     * Link existing file to task prompt.
     *
     * POST /api/v1/prompts/{topic}/files/link
     * Body: { "messageId": 123 }
     */
    #[Route('/{topic}/files/link', name: 'link_file', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/prompts/{topic}/files/link',
        summary: 'Link an existing file to task prompt',
        description: 'Updates the groupKey of all RAG chunks for a file to link it to this task prompt',
        tags: ['Task Prompts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'messageId', type: 'integer', example: 123),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'File linked successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'chunksLinked', type: 'integer', example: 15),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function linkFile(
        string $topic,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $messageId = $data['messageId'] ?? null;

        if (!$messageId) {
            return $this->json(['error' => 'messageId is required'], Response::HTTP_BAD_REQUEST);
        }

        // Verify the file exists and belongs to user
        $file = $this->fileRepository->find($messageId);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Update groupKey for all chunks via facade
        $newGroupKey = "TASKPROMPT:{$topic}";
        $chunksLinked = $this->vectorStorageFacade->updateGroupKey(
            $user->getId(),
            $messageId,
            $newGroupKey
        );

        $this->logger->info('Linked file to task prompt', [
            'user_id' => $user->getId(),
            'topic' => $topic,
            'message_id' => $messageId,
            'chunks_linked' => $chunksLinked,
            'file_name' => $file->getFileName(),
            'provider' => $this->vectorStorageFacade->getProviderName(),
        ]);

        return $this->json([
            'success' => true,
            'chunksLinked' => $chunksLinked,
            'message' => 'File linked to task prompt successfully',
        ]);
    }

    /**
     * Generate AI summary for a file attached to a task prompt.
     *
     * POST /api/v1/prompts/{topic}/files/{messageId}/summarize
     */
    #[Route('/{topic}/files/{messageId}/summarize', name: 'summarize_file', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/prompts/{topic}/files/{messageId}/summarize',
        summary: 'Generate AI summary for a file',
        description: 'Extracts text from the file and generates a concise AI summary for use in the knowledge base',
        security: [['Bearer' => []]],
        tags: ['Task Prompts'],
        parameters: [
            new OA\Parameter(
                name: 'topic',
                in: 'path',
                required: true,
                description: 'Task prompt topic',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'messageId',
                in: 'path',
                required: true,
                description: 'Message ID containing the file',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Summary generated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'summary', type: 'string', example: 'This document contains product information including prices and specifications.'),
                        new OA\Property(property: 'fileName', type: 'string', example: 'products.pdf'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
            new OA\Response(response: 500, description: 'Summary generation failed'),
        ]
    )]
    public function summarizeFile(
        string $topic,
        int $messageId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $fileText = '';
        $fileName = 'Document';

        // First, try to find in File repository (standalone files)
        $file = $this->fileRepository->find($messageId);
        if ($file && $file->getUserId() === $user->getId()) {
            $fileText = $file->getFileText();
            $fileName = $file->getFileName();
        } else {
            // Fall back to Message repository (message attachments)
            $message = $this->messageRepository->find($messageId);

            if (!$message || $message->getUserId() !== $user->getId()) {
                return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
            }

            $fileText = $message->getFileText();
            $fileName = $message->getFilePath() ? basename($message->getFilePath()) : 'Document';
        }

        // If no fileText from the file entity, fall back to reconstructing from RAG chunks.
        // This handles legacy files uploaded before fileText was stored in the entity.
        if (empty(trim($fileText))) {
            $this->logger->warning('PromptController: No extracted text in entity, attempting RAG chunk fallback', [
                'message_id' => $messageId,
                'user_id' => $user->getId(),
            ]);

            $fileText = $this->reconstructTextFromRagChunks($user->getId(), $messageId);
        }

        if (empty(trim($fileText))) {
            return $this->json([
                'error' => 'No text content found in file',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Truncate text if too long (keep first 15000 chars for summary)
        $maxLength = 15000;
        if (strlen($fileText) > $maxLength) {
            $fileText = substr($fileText, 0, $maxLength).'... [truncated]';
        }

        try {
            // Get a cheap, fast model for summarization
            $provider = null;
            $modelName = null;

            // Try OpenAI gpt-4o-mini first (cheap and fast)
            $openaiProvider = $this->modelConfigService->getProviderForModel(73);
            $openaiModel = $this->modelConfigService->getModelName(73);

            if ($openaiProvider && $openaiModel) {
                $provider = $openaiProvider;
                $modelName = $openaiModel;
            } else {
                // Fallback to user's default chat model
                $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
                if ($modelId && $modelId > 0) {
                    $provider = $this->modelConfigService->getProviderForModel($modelId);
                    $modelName = $this->modelConfigService->getModelName($modelId);
                }
            }

            // Build prompt for concise summary
            $systemPrompt = <<<'PROMPT'
You are a document summarizer. Your task is to create a brief, informative summary of the provided document.

Rules:
- Write 2-3 sentences maximum
- Focus on what the document IS and what information it CONTAINS
- Be specific about the type of content (prices, FAQs, product info, etc.)
- Write in the same language as the document
- Do NOT include any metadata or formatting
- Just output the plain summary text
PROMPT;

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Summarize this document:\n\n{$fileText}"],
            ];

            $aiOptions = ['temperature' => 0.3];
            if ($provider) {
                $aiOptions['provider'] = $provider;
            }
            if ($modelName) {
                $aiOptions['model'] = $modelName;
            }

            $response = $this->aiFacade->chat($messages, $user->getId(), $aiOptions);
            $summary = trim($response['content'] ?? '');

            $this->logger->info('File summary generated', [
                'user_id' => $user->getId(),
                'topic' => $topic,
                'message_id' => $messageId,
                'file_name' => $fileName,
                'summary_length' => strlen($summary),
            ]);

            return $this->json([
                'success' => true,
                'summary' => $summary,
                'fileName' => $fileName,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('File summary generation failed', [
                'user_id' => $user->getId(),
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to generate summary',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
