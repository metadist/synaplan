<?php

namespace App\Controller;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Service\File\OgImageService;
use App\Service\Message\MessageApiFormatter;
use App\Service\Multitask\InProgressTurnResolver;
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
        private WidgetSessionService $widgetSessionService,
        private OgImageService $ogImageService,
        private MessageApiFormatter $messageApiFormatter,
        private InProgressTurnResolver $inProgressTurnResolver,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chats',
        summary: 'List chats for authenticated user',
        description: 'Returns the user\'s chats ordered by last activity. Pagination is opt-in: pass a `limit` query parameter to page through the list (used by the mobile history drawer). Without `limit` the full list is returned for backwards compatibility.',
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'When present, enables pagination and caps the page size.', schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, description: 'Number of chats to skip (only used when `limit` is present).', schema: new OA\Schema(type: 'integer', default: 0, minimum: 0)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of user chats',
                content: new OA\JsonContent(
                    required: ['success', 'chats', 'total', 'offset', 'limit', 'hasMore'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'chats',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'title', 'createdAt', 'updatedAt', 'messageCount', 'isShared'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'title', type: 'string', example: 'My Chat'),
                                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'messageCount', type: 'integer', example: 5),
                                    new OA\Property(property: 'isShared', type: 'boolean', example: false),
                                    new OA\Property(property: 'source', type: 'string', nullable: true, example: 'web'),
                                    new OA\Property(property: 'firstMessagePreview', type: 'string', nullable: true, example: 'How do I reset my password?'),
                                    new OA\Property(
                                        property: 'widgetSession',
                                        type: 'object',
                                        nullable: true,
                                        properties: [
                                            new OA\Property(property: 'widgetId', type: 'string'),
                                            new OA\Property(property: 'widgetName', type: 'string', nullable: true),
                                            new OA\Property(property: 'sessionId', type: 'string'),
                                            new OA\Property(property: 'messageCount', type: 'integer'),
                                            new OA\Property(property: 'lastMessage', type: 'integer', nullable: true),
                                            new OA\Property(property: 'created', type: 'integer'),
                                            new OA\Property(property: 'expires', type: 'integer'),
                                        ]
                                    ),
                                ]
                            )
                        ),
                        new OA\Property(property: 'total', type: 'integer', example: 42),
                        new OA\Property(property: 'offset', type: 'integer', example: 0),
                        new OA\Property(property: 'limit', type: 'integer', example: 20),
                        new OA\Property(property: 'hasMore', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Pagination is opt-in so existing callers (desktop rail, chat switching)
        // keep receiving the full list. The mobile history drawer passes `limit`
        // to page through the chats with infinite scroll.
        $paginate = $request->query->has('limit');
        $limit = max(1, min((int) $request->query->get('limit', 20), 100));
        $offset = max(0, (int) $request->query->get('offset', 0));

        if ($paginate) {
            $chats = $this->chatRepository->findByUserPaginated($user->getId(), $limit, $offset);
            $total = $this->chatRepository->countByUser($user->getId());
        } else {
            $chats = $this->chatRepository->findByUser($user->getId());
            $total = count($chats);
        }

        $chatIds = array_map(static fn (Chat $chat) => $chat->getId(), $chats);
        $sessionMap = $this->widgetSessionService->getSessionMapForChats($chatIds);

        $result = array_map(function (Chat $chat) use ($sessionMap) {
            // Get first user message preview (first 30 chars)
            // Direction 'IN' = user message, 'OUT' = assistant message
            $firstMessagePreview = null;
            $messages = $chat->getMessages();
            foreach ($messages as $message) {
                if ('IN' === $message->getDirection()) {
                    // Tool commands (/pic, /vid, …) are kept in the stored message
                    // text for backend routing, but the raw prefix must never leak
                    // into the chat list preview — only the user's actual query.
                    $content = $this->stripToolCommandPrefix($message->getText() ?? '');
                    $firstMessagePreview = mb_strlen($content) > 30
                        ? mb_substr($content, 0, 30).'…'
                        : $content;
                    break;
                }
            }

            return [
                'id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
                'messageCount' => $sessionMap[$chat->getId()]['messageCount'] ?? $chat->getMessages()->count(),
                'isShared' => $chat->isPublic(),
                'source' => $chat->getSource(),
                'widgetSession' => $sessionMap[$chat->getId()] ?? null,
                'firstMessagePreview' => $firstMessagePreview,
            ];
        }, $chats);

        return $this->json([
            'success' => true,
            'chats' => $result,
            'total' => $total,
            'offset' => $paginate ? $offset : 0,
            'limit' => $paginate ? $limit : count($result),
            'hasMore' => $paginate ? ($offset + count($result)) < $total : false,
        ]);
    }

    /**
     * Strip a leading tool-command prefix ("/pic ", "/vid ", "/search ", …) from
     * a stored user message. The frontend keeps these prefixes in the message
     * text sent to the backend (needed for routing), so previews built from raw
     * message text must strip them explicitly — mirrors the same cleanup in
     * SharedChatPageController::cleanSlashCommands().
     */
    private function stripToolCommandPrefix(string $text): string
    {
        return preg_replace('/^\/(?:pic|vid|audio|tts|image|video|search)\s*/i', '', $text) ?? $text;
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/chats',
        summary: 'Create a new chat',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'New Discussion', nullable: true),
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
                                new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function create(
        Request $request,
        #[CurrentUser] ?User $user,
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
            'user_id' => $user->getId(),
        ]);

        return $this->json([
            'success' => true,
            'chat' => [
                'id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chats/{id}',
        summary: 'Get a specific chat by ID',
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
                                new OA\Property(property: 'shareToken', type: 'string', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found'),
        ]
    )]
    public function get(
        int $id,
        #[CurrentUser] ?User $user,
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
            ],
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/chats/{id}',
        summary: 'Update chat title',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Updated Title'),
                ]
            )
        ),
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chat updated successfully'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found'),
        ]
    )]
    public function update(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
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
            ],
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/chats/{id}',
        summary: 'Delete a chat',
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chat deleted successfully'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found'),
        ]
    )]
    public function delete(
        int $id,
        #[CurrentUser] ?User $user,
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
            'user_id' => $user->getId(),
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
                    new OA\Property(property: 'enable', type: 'boolean', example: true),
                ]
            )
        ),
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
                        new OA\Property(property: 'shareUrl', type: 'string', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found'),
        ]
    )]
    public function share(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
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

            // Generate OG image for social media sharing
            $ogImagePath = $this->ogImageService->generateOgImage($chat);
            $chat->setOgImagePath($ogImagePath);
        } else {
            $chat->setIsPublic(false);

            // Delete OG image to free up storage space
            $this->ogImageService->deleteOgImage($chat);
            $chat->setOgImagePath(null);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'shareToken' => $chat->getShareToken(),
            'isShared' => $chat->isPublic(),
            'shareUrl' => $chat->isPublic()
                ? $this->generateUrl('api_chats_shared', ['token' => $chat->getShareToken()], true)
                : null,
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
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
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
                                new OA\Property(property: 'hasMore', type: 'boolean'),
                            ]
                        ),
                        new OA\Property(
                            property: 'inProgressTurn',
                            description: 'Present only on the first page (offset 0) when the newest message is a still-running multi-task turn: the per-node task cards rebuilt from persisted plan state so a mid-stream reload shows running/completed cards before the assistant reply row exists (#1142).',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'reply_node', type: 'string'),
                                new OA\Property(
                                    property: 'cards',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'nodeId', type: 'string'),
                                            new OA\Property(property: 'capability', type: 'string'),
                                            new OA\Property(property: 'kind', type: 'string'),
                                            new OA\Property(property: 'state', type: 'string'),
                                        ]
                                    )
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Chat not found'),
        ]
    )]
    public function getMessages(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
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
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $messages = $queryBuilder->getQuery()->getResult();
        $messages = array_reverse($messages);

        // Issue #1070: serialization lives in MessageApiFormatter so this
        // endpoint and GET /api/v1/messages/{id} can never diverge.
        $messageData = array_map(
            fn ($m) => $this->messageApiFormatter->format($m),
            $messages
        );

        $totalCount = $this->messageRepository->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.chatId = :chatId')
            ->setParameter('chatId', $chat->getId())
            ->getQuery()
            ->getSingleScalarResult();

        $payload = [
            'success' => true,
            'messages' => $messageData,
            'pagination' => [
                'offset' => $offset,
                'limit' => $limit,
                'total' => (int) $totalCount,
                'hasMore' => ($offset + count($messages)) < $totalCount,
            ],
        ];

        // Issue #1142: on a fresh load, surface a still-running multi-task turn
        // (user prompt sent, assistant OUT row not written yet) as in-progress
        // task cards rebuilt from BMESSAGE_TASKS, so returning mid-stream shows
        // running/completed cards instead of only the bare prompt. Only for the
        // first page — the newest message is at the tail of the ascending list.
        if (0 === $offset && [] !== $messages) {
            $inProgress = $this->inProgressTurnResolver->resolve($messages[array_key_last($messages)]);
            if (null !== $inProgress) {
                $payload['inProgressTurn'] = $inProgress;
            }
        }

        return $this->json($payload);
    }

    #[Route('/shared/{token}', name: 'shared', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chats/shared/{token}',
        summary: 'Get a publicly shared chat by token',
        security: [],
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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
                                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            ]
                        ),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items()),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Chat not found or not shared'),
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

        // Issue #1175: serialize shared messages through the canonical
        // MessageApiFormatter (same as the authenticated history endpoint)
        // so user-uploaded file attachments (the File entity M2M relation,
        // exposed as `files[]`) are visible to viewers — the previous inline
        // serializer only emitted the legacy single `file` field, so uploads
        // silently disappeared in the shared view.
        $messageData = array_map(
            fn ($m) => $this->messageApiFormatter->format($m),
            $messages
        );

        return $this->json([
            'success' => true,
            'chat' => [
                'title' => $chat->getTitle() ?? 'Shared Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
            ],
            'messages' => $messageData,
        ]);
    }
}
