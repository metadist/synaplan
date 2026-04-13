<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Chat;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\GuestSessionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Guest Chat API Controller.
 *
 * PUBLIC endpoints (no authentication required) for the guest trial chat mode.
 */
#[Route('/api/v1/guest', name: 'api_guest_')]
#[OA\Tag(name: 'Guest')]
class GuestChatController extends AbstractController
{
    public function __construct(
        private GuestSessionService $guestSessionService,
        private EntityManagerInterface $em,
        private MessageRepository $messageRepository,
        private SearchResultRepository $searchResultRepository,
    ) {
    }

    /**
     * Create or retrieve a guest session.
     *
     * If a valid sessionId is provided and the session exists, returns its current state.
     * Otherwise creates a new session.
     */
    #[Route('/session', name: 'session_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/guest/session',
        summary: 'Create or retrieve a guest chat session',
        tags: ['Guest']
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'sessionId', type: 'string', description: 'Client-generated UUID (optional)', example: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Guest session status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'sessionId', type: 'string'),
                new OA\Property(property: 'remaining', type: 'integer'),
                new OA\Property(property: 'maxMessages', type: 'integer'),
                new OA\Property(property: 'limitReached', type: 'boolean'),
            ]
        )
    )]
    public function createSession(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $sessionId = $data['sessionId'] ?? null;

        if ($sessionId) {
            $session = $this->guestSessionService->getSession($sessionId);
            if ($session && !$session->isExpired()) {
                return $this->json([
                    'sessionId' => $session->getSessionId(),
                    'chatId' => $session->getChatId(),
                    'remaining' => $session->getRemainingMessages(),
                    'maxMessages' => $session->getMaxMessages(),
                    'limitReached' => $session->isLimitReached(),
                ]);
            }
        }

        $sessionId = Uuid::v4()->toRfc4122();

        $session = $this->guestSessionService->createSession($sessionId, $request);

        return $this->json([
            'sessionId' => $session->getSessionId(),
            'chatId' => null,
            'remaining' => $session->getRemainingMessages(),
            'maxMessages' => $session->getMaxMessages(),
            'limitReached' => false,
        ]);
    }

    /**
     * Get current guest session status.
     */
    #[Route('/session/{sessionId}', name: 'session_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/guest/session/{sessionId}',
        summary: 'Get guest session status',
        tags: ['Guest']
    )]
    #[OA\Parameter(
        name: 'sessionId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'Guest session status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'sessionId', type: 'string'),
                new OA\Property(property: 'remaining', type: 'integer'),
                new OA\Property(property: 'maxMessages', type: 'integer'),
                new OA\Property(property: 'limitReached', type: 'boolean'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Session not found or expired')]
    public function getSessionStatus(string $sessionId): JsonResponse
    {
        $session = $this->guestSessionService->getSession($sessionId);

        if (!$session || $session->isExpired()) {
            return $this->json([
                'error' => 'Session not found or expired',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'sessionId' => $session->getSessionId(),
            'remaining' => $session->getRemainingMessages(),
            'maxMessages' => $session->getMaxMessages(),
            'limitReached' => $session->isLimitReached(),
        ]);
    }

    /**
     * Create a chat for the guest session (or return existing one).
     */
    #[Route('/chat', name: 'chat_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/guest/chat',
        summary: 'Create or retrieve a guest chat',
        tags: ['Guest']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['sessionId'],
            properties: [
                new OA\Property(property: 'sessionId', type: 'string'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Chat created or retrieved',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'chatId', type: 'integer'),
            ]
        )
    )]
    public function createChat(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $sessionId = $data['sessionId'] ?? null;

        if (!$sessionId) {
            return $this->json(['error' => 'sessionId is required'], Response::HTTP_BAD_REQUEST);
        }

        $session = $this->guestSessionService->getSession($sessionId);
        if (!$session || $session->isExpired()) {
            return $this->json(['error' => 'Session not found or expired'], Response::HTTP_NOT_FOUND);
        }

        if ($session->getChatId()) {
            return $this->json(['chatId' => $session->getChatId()]);
        }

        $user = $this->guestSessionService->getProcessingUser();
        if (!$user) {
            return $this->json(['error' => 'Guest mode unavailable'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Re-check after potential concurrent request
        $this->em->refresh($session);
        if ($session->getChatId()) {
            return $this->json(['chatId' => $session->getChatId()]);
        }

        $now = new \DateTimeImmutable();
        $chat = new Chat();
        $chat->setUserId($user->getId());
        $chat->setTitle('Guest Chat • '.substr($sessionId, 0, 8));
        $chat->setSource('guest');
        $chat->setCreatedAt($now);
        $chat->setUpdatedAt($now);

        $this->em->persist($chat);
        $this->em->flush();

        $chatId = $chat->getId();
        if (!$chatId) {
            return $this->json(['error' => 'Failed to create chat'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->guestSessionService->attachChat($session, $chatId);
        $this->em->flush();

        return $this->json(['chatId' => $chatId]);
    }

    /**
     * Retrieve messages for a guest session's chat.
     */
    #[Route('/messages/{sessionId}', name: 'messages', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/guest/messages/{sessionId}',
        summary: 'Get messages for a guest chat session',
        tags: ['Guest']
    )]
    #[OA\Parameter(
        name: 'sessionId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'Guest chat messages',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'object')),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Session not found or has no chat')]
    public function getMessages(string $sessionId): JsonResponse
    {
        $session = $this->guestSessionService->getSession($sessionId);

        if (!$session || $session->isExpired() || !$session->getChatId()) {
            return $this->json(['error' => 'Session not found or has no chat'], Response::HTTP_NOT_FOUND);
        }

        $chatId = $session->getChatId();

        $messages = $this->messageRepository->createQueryBuilder('m')
            ->where('m.chatId = :chatId')
            ->setParameter('chatId', $chatId)
            ->orderBy('m.unixTimestamp', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        // Build in-memory index of IN messages for preceding-message lookup
        $inMessages = [];
        foreach ($messages as $m) {
            if ('IN' === $m->getDirection()) {
                $inMessages[] = $m;
            }
        }

        // Collect IN messages that precede OUT messages with web search data
        $inMessagesNeedingResults = [];
        $outToInMap = []; // outMessageId => inMessage
        foreach ($messages as $m) {
            if ('OUT' !== $m->getDirection()) {
                continue;
            }
            $searchQuery = $m->getMeta('web_search_query');
            $searchResultsCount = $m->getMeta('web_search_results_count');
            if (!$searchQuery && !$searchResultsCount) {
                continue;
            }
            // Find the closest preceding IN message in-memory
            $preceding = null;
            foreach ($inMessages as $inMsg) {
                if ($inMsg->getUnixTimestamp() < $m->getUnixTimestamp()) {
                    $preceding = $inMsg;
                } else {
                    break;
                }
            }
            if ($preceding) {
                $outToInMap[$m->getId()] = $preceding;
                $inMessagesNeedingResults[$preceding->getId()] = $preceding;
            }
        }

        // Batch-load all needed search results in a single query
        $searchResultsByMessageId = $this->searchResultRepository
            ->findByMessages(array_values($inMessagesNeedingResults));

        $messageData = array_map(function ($m) use ($outToInMap, $searchResultsByMessageId) {
            $aiModels = [];
            $webSearchData = null;
            $searchResultsData = [];

            if ('OUT' === $m->getDirection()) {
                $chatProvider = $m->getMeta('ai_chat_provider');
                $chatModel = $m->getMeta('ai_chat_model');
                $chatModelIdMeta = $m->getMeta('ai_chat_model_id');
                if ($chatProvider || $chatModel) {
                    $aiModels['chat'] = [
                        'provider' => $chatProvider,
                        'model' => $chatModel,
                        'model_id' => $chatModelIdMeta ? (int) $chatModelIdMeta : null,
                    ];
                }

                $sortingProvider = $m->getMeta('ai_sorting_provider');
                $sortingModel = $m->getMeta('ai_sorting_model');
                $sortingModelId = $m->getMeta('ai_sorting_model_id');
                if ($sortingProvider || $sortingModel) {
                    $aiModels['sorting'] = [
                        'provider' => $sortingProvider,
                        'model' => $sortingModel,
                        'model_id' => $sortingModelId ? (int) $sortingModelId : null,
                    ];
                }

                $searchQuery = $m->getMeta('web_search_query');
                $searchResultsCount = $m->getMeta('web_search_results_count');
                if ($searchQuery || $searchResultsCount) {
                    $webSearchData = [
                        'query' => $searchQuery,
                        'resultsCount' => $searchResultsCount ? (int) $searchResultsCount : 0,
                    ];

                    $incomingMessage = $outToInMap[$m->getId()] ?? null;
                    if ($incomingMessage) {
                        $results = $searchResultsByMessageId[$incomingMessage->getId()] ?? [];
                        foreach ($results as $sr) {
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
                'aiModels' => !empty($aiModels) ? $aiModels : null,
                'webSearch' => $webSearchData,
                'searchResults' => !empty($searchResultsData) ? $searchResultsData : null,
            ];
        }, $messages);

        return $this->json([
            'success' => true,
            'messages' => $messageData,
        ]);
    }
}
