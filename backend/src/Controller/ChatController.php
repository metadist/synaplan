<?php

namespace App\Controller;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/chats', name: 'api_chats_')]
class ChatController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private SearchResultRepository $searchResultRepository,
        private WidgetSessionService $widgetSessionService,
        private LoggerInterface $logger
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chats',
        summary: 'List all chats for authenticated user',
        tags: ['Chats'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of user chats',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'chats',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'title', type: 'string', example: 'My Chat'),
                                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'messageCount', type: 'integer', example: 5),
                                    new OA\Property(property: 'isShared', type: 'boolean', example: false)
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

        $chats = $this->chatRepository->findByUser($user->getId());
        $chatIds = array_map(static fn (Chat $chat) => $chat->getId(), $chats);
        $sessionMap = $this->widgetSessionService->getSessionMapForChats($chatIds);

        $result = array_map(function (Chat $chat) use ($sessionMap) {
            return [
                'id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
                'messageCount' => $sessionMap[$chat->getId()]['messageCount'] ?? $chat->getMessages()->count(),
                'isShared' => $chat->isPublic(),
                'widgetSession' => $sessionMap[$chat->getId()] ?? null,
            ];
        }, $chats);

        return $this->json(['success' => true, 'chats' => $result]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/chats',
        summary: 'Create a new chat',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'New Discussion', nullable: true)
                ]
            )
        ),
        tags: ['Chats'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Chat created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'chat',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'title', type: 'string', example: 'New Chat'),
                                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time')
                            ]
                        )
                    ]
                )
            ),
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
        $title = $data['title'] ?? null;

        $chat = new Chat();
        $chat->setUserId($user->getId());

        if ($title) {
            $chat->setTitle($title);
        }

        $this->em->persist($chat);
        $this->em->flush();

        $this->logger->info('Chat created', [
            'chat_id' => $chat->getId(),
            'user_id' => $user->getId()
        ]);

        return $this->json([
            'success' => true,
            'chat' => [
                'id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chats/{id}',
        summary: 'Get a specific chat by ID',
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Chat details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'chat',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'title', type: 'string'),
                                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'isShared', type: 'boolean'),
                                new OA\Property(property: 'shareToken', type: 'string', nullable: true)
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found')
        ]
    )]
    public function get(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $sessionInfo = $this->widgetSessionService->getSessionMapForChats([$chat->getId()]);

        return $this->json([
            'success' => true,
            'chat' => [
                'id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
                'isShared' => $chat->isPublic(),
                'shareToken' => $chat->getShareToken(),
                'widgetSession' => $sessionInfo[$chat->getId()] ?? null,
            ]
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/chats/{id}',
        summary: 'Update chat title',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Updated Title')
                ]
            )
        ),
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chat updated successfully'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found')
        ]
    )]
    public function update(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) {
            $chat->setTitle($data['title']);
        }

        $chat->updateTimestamp();
        $this->em->flush();

        return $this->json([
            'success' => true,
            'chat' => [
                'id' => $chat->getId(),
                'title' => $chat->getTitle(),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
            ]
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/chats/{id}',
        summary: 'Delete a chat',
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chat deleted successfully'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found')
        ]
    )]
    public function delete(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($chat);
        $this->em->flush();

        $this->logger->info('Chat deleted', [
            'chat_id' => $id,
            'user_id' => $user->getId()
        ]);

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/share', name: 'share', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/chats/{id}/share',
        summary: 'Enable/disable chat sharing',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'enable', type: 'boolean', example: true)
                ]
            )
        ),
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Share settings updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'shareToken', type: 'string', nullable: true),
                        new OA\Property(property: 'isShared', type: 'boolean'),
                        new OA\Property(property: 'shareUrl', type: 'string', nullable: true)
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found')
        ]
    )]
    public function share(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $enable = $data['enable'] ?? true;

        if ($enable) {
            if (!$chat->getShareToken()) {
                $chat->generateShareToken();
            }
            $chat->setIsPublic(true);
        } else {
            $chat->setIsPublic(false);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'shareToken' => $chat->getShareToken(),
            'isShared' => $chat->isPublic(),
            'shareUrl' => $chat->isPublic()
                ? $this->generateUrl('api_chats_shared', ['token' => $chat->getShareToken()], true)
                : null
        ]);
    }

    #[Route('/{id}/messages', name: 'messages', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chats/{id}/messages',
        summary: 'Get messages for a chat',
        tags: ['Chats', 'Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 100)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of messages with pagination',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items()),
                        new OA\Property(
                            property: 'pagination',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'offset', type: 'integer'),
                                new OA\Property(property: 'limit', type: 'integer'),
                                new OA\Property(property: 'total', type: 'integer'),
                                new OA\Property(property: 'hasMore', type: 'boolean')
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found')
        ]
    )]
    public function getMessages(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $limit = (int) $request->query->get('limit', 50);
        $offset = (int) $request->query->get('offset', 0);
        $limit = min($limit, 100);

        $queryBuilder = $this->messageRepository->createQueryBuilder('m')
            ->where('m.chatId = :chatId')
            ->setParameter('chatId', $chat->getId())
            ->orderBy('m.unixTimestamp', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $messages = $queryBuilder->getQuery()->getResult();
        $messages = array_reverse($messages);

        $messageData = array_map(function ($m) {
            $filesData = [];
            if ($m->hasFiles()) {
                foreach ($m->getFiles() as $file) {
                    $filesData[] = [
                        'id' => $file->getId(),
                        'filename' => $file->getFileName(),
                        'fileType' => $file->getFileType(),
                        'filePath' => $file->getFilePath(),
                        'fileSize' => $file->getFileSize(),
                        'fileMime' => $file->getFileMime(),
                    ];
                }
            }

            // Get AI model metadata for assistant messages
            $aiModels = [];
            $webSearchData = null;
            $searchResultsData = [];

            if ($m->getDirection() === 'OUT') {
                // Chat model (used for generating the response)
                $chatProvider = $m->getMeta('ai_chat_provider');
                $chatModel = $m->getMeta('ai_chat_model');
                if ($chatProvider || $chatModel) {
                    $aiModels['chat'] = [
                        'provider' => $chatProvider,
                        'model' => $chatModel,
                        'model_id' => null, // Chat model ID is not stored (selected from config)
                    ];
                }

                // Sorting model (used for classification/routing)
                $sortingProvider = $m->getMeta('ai_sorting_provider');
                $sortingModel = $m->getMeta('ai_sorting_model');
                $sortingModelId = $m->getMeta('ai_sorting_model_id');
                if ($sortingProvider || $sortingModel) {
                    $aiModels['sorting'] = [
                        'provider' => $sortingProvider,
                        'model' => $sortingModel,
                        'model_id' => $sortingModelId ? (int)$sortingModelId : null,
                    ];
                }

                // Web Search metadata
                $searchQuery = $m->getMeta('web_search_query');
                $searchResultsCount = $m->getMeta('web_search_results_count');
                if ($searchQuery || $searchResultsCount) {
                    $webSearchData = [
                        'query' => $searchQuery,
                        'resultsCount' => $searchResultsCount ? (int)$searchResultsCount : 0
                    ];

                    // Load actual search results from DB
                    // Search results are stored on the INCOMING (user) message, but we need to display them
                    // on the OUTGOING (AI) message. So we need to find the previous incoming message.
                    $incomingMessage = $this->messageRepository->createQueryBuilder('prev')
                        ->where('prev.chatId = :chatId')
                        ->andWhere('prev.direction = :direction')
                        ->andWhere('prev.unixTimestamp < :timestamp')
                        ->setParameter('chatId', $m->getChatId())
                        ->setParameter('direction', 'IN')
                        ->setParameter('timestamp', $m->getUnixTimestamp())
                        ->orderBy('prev.unixTimestamp', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();

                    if ($incomingMessage) {
                        $searchResults = $this->searchResultRepository->findByMessage($incomingMessage);
                        foreach ($searchResults as $sr) {
                            $searchResultsData[] = [
                                'title' => $sr->getTitle(),
                                'url' => $sr->getUrl(),
                                'description' => $sr->getDescription(),
                                'published' => $sr->getPublished(),
                                'source' => $sr->getSource(),
                                'thumbnail' => $sr->getThumbnail(),
                            ];
                        }
                    }
                }
            } else if ($m->getDirection() === 'IN') {
                // Check if web search was enabled for incoming message
                $webSearchEnabled = $m->getMeta('web_search_enabled');
                if ($webSearchEnabled === 'true') {
                    $webSearchData = [
                        'enabled' => true
                    ];
                }
            }

            return [
                'id' => $m->getId(),
                'text' => $m->getText(),
                'direction' => $m->getDirection(),
                'timestamp' => $m->getUnixTimestamp(),
                'provider' => $m->getProviderIndex(),
                'topic' => $m->getTopic(),
                'language' => $m->getLanguage(),
                'createdAt' => $m->getDateTime(),
                'files' => $filesData, // Attached files (user uploads)
                'aiModels' => !empty($aiModels) ? $aiModels : null, // AI model metadata
                'webSearch' => $webSearchData, // Web search metadata
                'searchResults' => !empty($searchResultsData) ? $searchResultsData : null, // Actual search results
                // Generated content (images, videos from AI)
                'file' => ($m->getFile() && $m->getFilePath()) ? [
                    'path' => $m->getFilePath(),
                    'type' => $m->getFileType()
                ] : null,
            ];
        }, $messages);

        $totalCount = $this->messageRepository->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.chatId = :chatId')
            ->setParameter('chatId', $chat->getId())
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'success' => true,
            'messages' => $messageData,
            'pagination' => [
                'offset' => $offset,
                'limit' => $limit,
                'total' => (int) $totalCount,
                'hasMore' => ($offset + count($messages)) < $totalCount
            ]
        ]);
    }

    #[Route('/shared/{token}', name: 'shared', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chats/shared/{token}',
        summary: 'Get a publicly shared chat by token',
        security: [],
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Shared chat with messages',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'chat',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'title', type: 'string'),
                                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time')
                            ]
                        ),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items())
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Chat not found or not shared')
        ]
    )]
    public function getShared(string $token): JsonResponse
    {
        $chat = $this->chatRepository->findPublicByShareToken($token);

        if (!$chat) {
            return $this->json(['error' => 'Chat not found or not shared'], Response::HTTP_NOT_FOUND);
        }

        $messages = $this->messageRepository->findBy(
            ['chatId' => $chat->getId()],
            ['unixTimestamp' => 'ASC']
        );

        $messageData = array_map(function ($m) {
            $data = [
                'id' => $m->getId(),
                'text' => $m->getText(),
                'direction' => $m->getDirection(),
                'timestamp' => $m->getUnixTimestamp(),
                'provider' => $m->getProviderIndex(),
                'topic' => $m->getTopic(),
                'language' => $m->getLanguage(),
            ];

            // Include file information if present
            if ($m->getFile() && $m->getFilePath()) {
                $data['file'] = [
                    'path' => $m->getFilePath(),
                    'type' => $m->getFileType()
                ];
            }

            return $data;
        }, $messages);

        return $this->json([
            'success' => true,
            'chat' => [
                'title' => $chat->getTitle() ?? 'Shared Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
            ],
            'messages' => $messageData
        ]);
    }
}

