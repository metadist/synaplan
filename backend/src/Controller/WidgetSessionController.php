<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\WidgetSession;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\WidgetEventCacheService;
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
 * Widget Session Controller.
 *
 * Endpoints for managing and viewing widget chat sessions
 */
#[Route('/api/v1/widgets/{widgetId}/sessions', name: 'api_widget_sessions_')]
#[OA\Tag(name: 'Widget Sessions')]
class WidgetSessionController extends AbstractController
{
    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetSessionRepository $sessionRepository,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private LoggerInterface $logger,
        private WidgetEventCacheService $eventCache,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * List all sessions for a widget with pagination and filtering.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/sessions',
        summary: 'List all sessions for a widget',
        security: [['Bearer' => []]],
        tags: ['Widget Sessions']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100))]
    #[OA\Parameter(name: 'offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0))]
    #[OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'expired']))]
    #[OA\Parameter(name: 'mode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ai', 'human', 'waiting']))]
    #[OA\Parameter(name: 'from', in: 'query', description: 'Unix timestamp', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'to', in: 'query', description: 'Unix timestamp', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['lastMessage', 'created', 'messageCount'], default: 'lastMessage'))]
    #[OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ASC', 'DESC'], default: 'DESC'))]
    #[OA\Parameter(name: 'favorite', in: 'query', description: 'Filter by favorite status', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(
        response: 200,
        description: 'List of sessions',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(
                    property: 'sessions',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'sessionId', type: 'string'),
                            new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                            new OA\Property(property: 'messageCount', type: 'integer'),
                            new OA\Property(property: 'fileCount', type: 'integer'),
                            new OA\Property(property: 'mode', type: 'string', enum: ['ai', 'human', 'waiting']),
                            new OA\Property(property: 'lastMessage', type: 'integer'),
                            new OA\Property(property: 'lastMessagePreview', type: 'string', nullable: true),
                            new OA\Property(property: 'created', type: 'integer'),
                            new OA\Property(property: 'expires', type: 'integer'),
                            new OA\Property(property: 'isExpired', type: 'boolean'),
                        ]
                    )
                ),
                new OA\Property(
                    property: 'pagination',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'total', type: 'integer'),
                        new OA\Property(property: 'limit', type: 'integer'),
                        new OA\Property(property: 'offset', type: 'integer'),
                        new OA\Property(property: 'hasMore', type: 'boolean'),
                    ]
                ),
                new OA\Property(
                    property: 'stats',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'ai', type: 'integer'),
                        new OA\Property(property: 'human', type: 'integer'),
                        new OA\Property(property: 'waiting', type: 'integer'),
                    ]
                ),
            ]
        )
    )]
    public function list(string $widgetId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $widget = $this->widgetRepository->findByWidgetId($widgetId);

        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if ($widget->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Parse query parameters
        $limit = min((int) $request->query->get('limit', 20), 100);
        $offset = max((int) $request->query->get('offset', 0), 0);

        $filters = [];
        if ($request->query->has('status')) {
            $filters['status'] = $request->query->get('status');
        }
        if ($request->query->has('mode')) {
            $filters['mode'] = $request->query->get('mode');
        }
        if ($request->query->has('from')) {
            $filters['from'] = (int) $request->query->get('from');
        }
        if ($request->query->has('to')) {
            $filters['to'] = (int) $request->query->get('to');
        }
        if ($request->query->has('sort')) {
            $filters['sort'] = $request->query->get('sort');
        }
        if ($request->query->has('order')) {
            $filters['order'] = $request->query->get('order');
        }
        if ($request->query->has('favorite')) {
            $filters['favorite'] = filter_var($request->query->get('favorite'), FILTER_VALIDATE_BOOLEAN);
        }

        try {
            $result = $this->sessionRepository->findSessionsByWidget($widgetId, $limit, $offset, $filters);
            $modeStats = $this->sessionRepository->countSessionsByMode($widgetId);

            // Get chat IDs for all sessions to fetch actual last messages
            $chatIds = array_filter(array_map(fn (WidgetSession $s) => $s->getChatId(), $result['sessions']));
            $lastMessages = !empty($chatIds) ? $this->messageRepository->getLastMessageTextForChats($chatIds) : [];

            $sessionsData = array_map(function (WidgetSession $session) use ($lastMessages) {
                $chatId = $session->getChatId();
                // Use actual last message from database, truncated to 100 chars
                $lastMessagePreview = null;
                if ($chatId && isset($lastMessages[$chatId])) {
                    $lastMessagePreview = mb_substr($lastMessages[$chatId], 0, 100);
                }

                return [
                    'id' => $session->getId(),
                    'sessionId' => $session->getSessionId(),
                    'sessionIdDisplay' => $this->anonymizeSessionId($session->getSessionId()),
                    'chatId' => $chatId,
                    'messageCount' => $session->getMessageCount(),
                    'fileCount' => $session->getFileCount(),
                    'mode' => $session->getMode(),
                    'humanOperatorId' => $session->getHumanOperatorId(),
                    'lastMessage' => $session->getLastMessage(),
                    'lastMessagePreview' => $lastMessagePreview,
                    'lastHumanActivity' => $session->getLastHumanActivity(),
                    'created' => $session->getCreated(),
                    'expires' => $session->getExpires(),
                    'isExpired' => $session->isExpired(),
                    'isFavorite' => $session->isFavorite(),
                    'country' => $session->getCountry(),
                    'title' => $session->getTitle(),
                ];
            }, $result['sessions']);

            return $this->json([
                'success' => true,
                'sessions' => $sessionsData,
                'pagination' => [
                    'total' => $result['total'],
                    'limit' => $limit,
                    'offset' => $offset,
                    'hasMore' => ($offset + $limit) < $result['total'],
                ],
                'stats' => $modeStats,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to list widget sessions', [
                'widget_id' => $widgetId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to list sessions',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a single session details with full chat history.
     */
    #[Route('/{sessionId}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}',
        summary: 'Get session details with chat history',
        security: [['Bearer' => []]],
        tags: ['Widget Sessions']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Session details',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'session', type: 'object'),
                new OA\Property(
                    property: 'messages',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'direction', type: 'string'),
                            new OA\Property(property: 'text', type: 'string'),
                            new OA\Property(property: 'timestamp', type: 'integer'),
                            new OA\Property(property: 'sender', type: 'string', enum: ['user', 'ai', 'human']),
                        ]
                    )
                ),
            ]
        )
    )]
    public function get(string $widgetId, string $sessionId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $widget = $this->widgetRepository->findByWidgetId($widgetId);

        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if ($widget->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        // Get chat messages if chat exists
        $messages = [];
        $chatMessages = [];
        if ($session->getChatId()) {
            $chat = $this->chatRepository->find($session->getChatId());
            if ($chat) {
                // Use the widget owner's user ID for the query
                $chatMessages = $this->messageRepository->findChatHistory(
                    $widget->getOwnerId(),
                    $chat->getId(),
                    100
                );
                $messages = array_map(function ($message) {
                    // Determine sender based on direction and provider
                    // Direction: IN = user message, OUT = system response (AI, human operator, or system)
                    $providerIndex = $message->getProviderIndex();
                    if ('IN' === $message->getDirection()) {
                        $sender = 'user';
                    } elseif ('SYSTEM' === $providerIndex) {
                        $sender = 'system';
                    } elseif ('HUMAN_OPERATOR' === $providerIndex) {
                        $sender = 'human';
                    } else {
                        $sender = 'ai';
                    }

                    // Get attached files
                    $files = [];
                    foreach ($message->getFiles() as $file) {
                        $files[] = [
                            'id' => $file->getId(),
                            'filename' => $file->getFileName(),
                            'mimeType' => $file->getFileMime(),
                            'size' => $file->getFileSize(),
                        ];
                    }

                    $result = [
                        'id' => $message->getId(),
                        'direction' => $message->getDirection(),
                        'text' => $message->getText(),
                        'timestamp' => $message->getUnixTimestamp(),
                        'sender' => $sender,
                    ];

                    if (!empty($files)) {
                        $result['files'] = $files;
                    }

                    return $result;
                }, $chatMessages);
            }
        }

        // Get last message preview from the transformed messages array
        $lastMessagePreview = null;
        if (!empty($messages)) {
            // Messages array is ordered oldest first, so last element is newest
            $lastIndex = count($messages) - 1;
            if (isset($messages[$lastIndex]['text'])) {
                $lastMessagePreview = mb_substr($messages[$lastIndex]['text'], 0, 100);
            }
        }

        return $this->json([
            'success' => true,
            'session' => [
                'id' => $session->getId(),
                'sessionId' => $session->getSessionId(),
                'sessionIdDisplay' => $this->anonymizeSessionId($session->getSessionId()),
                'chatId' => $session->getChatId(),
                'messageCount' => $session->getMessageCount(),
                'fileCount' => $session->getFileCount(),
                'mode' => $session->getMode(),
                'humanOperatorId' => $session->getHumanOperatorId(),
                'lastMessage' => $session->getLastMessage(),
                'lastMessagePreview' => $lastMessagePreview,
                'lastHumanActivity' => $session->getLastHumanActivity(),
                'created' => $session->getCreated(),
                'expires' => $session->getExpires(),
                'isExpired' => $session->isExpired(),
                'isFavorite' => $session->isFavorite(),
                'country' => $session->getCountry(),
                'title' => $session->getTitle(),
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Toggle favorite status for a session.
     */
    #[Route('/{sessionId}/favorite', name: 'toggle_favorite', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/favorite',
        summary: 'Toggle favorite status for a session',
        security: [['Bearer' => []]],
        tags: ['Widget Sessions']
    )]
    #[OA\Response(
        response: 200,
        description: 'Favorite status toggled',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'isFavorite', type: 'boolean'),
            ]
        )
    )]
    public function toggleFavorite(
        string $widgetId,
        string $sessionId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $widget = $this->widgetRepository->findByWidgetId($widgetId);

        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if ($widget->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $session->toggleFavorite();
            $this->sessionRepository->save($session, true);

            return $this->json([
                'success' => true,
                'isFavorite' => $session->isFavorite(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to toggle favorite', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to toggle favorite',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Rename a session (update the title).
     */
    #[Route('/{sessionId}/rename', name: 'rename', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/rename',
        summary: 'Rename a session (update title)',
        security: [['Bearer' => []]],
        tags: ['Widget Sessions']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'title', type: 'string', maxLength: 100, description: 'New session title'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Session renamed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'title', type: 'string'),
            ]
        )
    )]
    public function rename(
        string $widgetId,
        string $sessionId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $widget = $this->widgetRepository->findByWidgetId($widgetId);

        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if ($widget->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $title = isset($data['title']) ? trim((string) $data['title']) : null;

            // Allow empty string to clear the title
            if ('' === $title) {
                $title = null;
            }

            // Validate title length
            if (null !== $title && mb_strlen($title) > 100) {
                return $this->json([
                    'error' => 'Title too long (max 100 characters)',
                ], Response::HTTP_BAD_REQUEST);
            }

            $session->setTitle($title);
            $this->sessionRepository->save($session, true);

            return $this->json([
                'success' => true,
                'title' => $session->getTitle(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to rename session', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to rename session',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send typing indicator from operator to widget user.
     */
    #[Route('/{sessionId}/typing', name: 'typing', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/typing',
        summary: 'Send typing indicator from operator to widget',
        security: [['Bearer' => []]],
        tags: ['Widget Sessions']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'isTyping', type: 'boolean', description: 'Whether operator is typing'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Typing indicator sent',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
            ]
        )
    )]
    public function typing(
        string $widgetId,
        string $sessionId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $widget = $this->widgetRepository->findByWidgetId($widgetId);

        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if ($widget->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        // Only send typing indicator if session is in human mode
        if ('human' !== $session->getMode()) {
            return $this->json(['error' => 'Session is not in human mode'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $isTyping = $data['isTyping'] ?? true;

            if ($isTyping) {
                $this->eventCache->setTyping($widgetId, $sessionId, $user->getId());
            } else {
                $this->eventCache->clearTyping($widgetId, $sessionId);
            }

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send typing indicator', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to send typing indicator',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete multiple sessions.
     */
    #[Route('', name: 'delete_bulk', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/widgets/{widgetId}/sessions',
        summary: 'Delete multiple sessions',
        security: [['Bearer' => []]],
        tags: ['Widget Sessions']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'sessionIds',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    description: 'Array of session IDs to delete'
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Sessions deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'deleted', type: 'integer'),
            ]
        )
    )]
    public function deleteSessions(
        string $widgetId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $widget = $this->widgetRepository->findByWidgetId($widgetId);

        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if ($widget->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $sessionIds = $data['sessionIds'] ?? [];

            if (empty($sessionIds) || !is_array($sessionIds)) {
                return $this->json(['error' => 'No session IDs provided'], Response::HTTP_BAD_REQUEST);
            }

            // Find sessions to delete
            $sessions = $this->sessionRepository->findBySessionIds($widgetId, $sessionIds);

            if (empty($sessions)) {
                return $this->json([
                    'success' => true,
                    'deleted' => 0,
                ]);
            }

            // Collect chat IDs for deletion
            $chatIds = [];
            foreach ($sessions as $session) {
                if ($session->getChatId()) {
                    $chatIds[] = $session->getChatId();
                }
            }

            // Delete messages associated with the chats
            if (!empty($chatIds)) {
                $this->messageRepository->deleteByChatIds($chatIds);
            }

            // Delete chats
            foreach ($chatIds as $chatId) {
                $chat = $this->chatRepository->find($chatId);
                if ($chat) {
                    $this->chatRepository->remove($chat);
                }
            }

            // Delete sessions
            foreach ($sessions as $session) {
                $this->sessionRepository->remove($session);
            }

            // Flush all changes
            $this->em->flush();

            return $this->json([
                'success' => true,
                'deleted' => count($sessions),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete sessions', [
                'widget_id' => $widgetId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to delete sessions',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Anonymize session ID for display (show only first 8 chars).
     */
    private function anonymizeSessionId(string $sessionId): string
    {
        if (strlen($sessionId) <= 12) {
            return $sessionId;
        }

        return substr($sessionId, 0, 8).'...'.substr($sessionId, -4);
    }
}
