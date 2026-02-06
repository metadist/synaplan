<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\WidgetEventCacheService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Widget Events Controller.
 *
 * Provides Server-Sent Events (SSE) for real-time widget communication.
 * All events are stored in cache (no database persistence).
 */
#[OA\Tag(name: 'Widget Events')]
class WidgetEventsController extends AbstractController
{
    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetSessionRepository $sessionRepository,
        private WidgetEventCacheService $eventCache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * SSE endpoint for widget sessions (used by the embedded widget).
     * Anonymous access allowed for widget users.
     * All events come from cache (no database persistence).
     */
    #[Route('/api/v1/widgets/{widgetId}/sessions/{sessionId}/events', name: 'api_widget_session_events', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/events',
        summary: 'Subscribe to session events via SSE',
        tags: ['Widget Events']
    )]
    public function sessionEvents(
        string $widgetId,
        string $sessionId,
        Request $request,
    ): Response {
        // Validate widget exists
        $widget = $this->widgetRepository->findByWidgetId($widgetId);
        if (!$widget) {
            return new JsonResponse(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        // Validate session exists
        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);
        if (!$session) {
            return new JsonResponse(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        $lastEventId = (int) $request->query->get('lastEventId', 0);
        $eventCache = $this->eventCache;

        // For SSE, return a streamed response
        $response = new StreamedResponse(function () use ($widgetId, $sessionId, $lastEventId, $eventCache) {
            $currentLastEventId = $lastEventId;
            $lastTypingTimestamp = 0;

            // Send initial connection event
            echo "event: connected\n";
            echo "data: {\"status\":\"connected\"}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            // Keep connection open for 5 minutes max
            $startTime = time();
            $maxDuration = 300;
            $heartbeatInterval = 15;
            $lastHeartbeat = time();
            $checkInterval = 2;

            while (time() - $startTime < $maxDuration) {
                if (connection_aborted()) {
                    break;
                }

                // Get new events from cache
                $events = $eventCache->getNewEvents($widgetId, $sessionId, $currentLastEventId);

                foreach ($events as $event) {
                    $data = [
                        'type' => $event['type'],
                        ...$event['payload'],
                    ];

                    echo 'id: '.$event['id']."\n";
                    echo 'event: '.$event['type']."\n";
                    echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();

                    $currentLastEventId = $event['id'];
                }

                // Check for typing indicator
                $typingData = $eventCache->getTyping($widgetId, $sessionId);
                if ($typingData) {
                    $typingTimestamp = $typingData['timestamp'] ?? 0;

                    if ($typingTimestamp > $lastTypingTimestamp) {
                        $lastTypingTimestamp = $typingTimestamp;

                        echo "event: typing\n";
                        echo 'data: '.json_encode([
                            'type' => 'typing',
                            'timestamp' => $typingTimestamp,
                            'operatorId' => $typingData['operatorId'] ?? null,
                        ], JSON_UNESCAPED_UNICODE)."\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }

                // Heartbeat
                if (time() - $lastHeartbeat >= $heartbeatInterval) {
                    echo ": heartbeat\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $lastHeartbeat = time();
                }

                sleep($checkInterval);
            }

            // Reconnect hint
            echo "event: reconnect\n";
            echo "data: {\"lastEventId\":$currentLastEventId}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable nginx buffering
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }

    /**
     * Long-polling fallback for environments that don't support SSE.
     */
    #[Route('/api/v1/widgets/{widgetId}/sessions/{sessionId}/poll', name: 'api_widget_session_poll', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/poll',
        summary: 'Poll for new session events',
        tags: ['Widget Events']
    )]
    public function pollEvents(
        string $widgetId,
        string $sessionId,
        Request $request,
    ): JsonResponse {
        // Validate widget exists
        $widget = $this->widgetRepository->findByWidgetId($widgetId);
        if (!$widget) {
            return new JsonResponse(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        // Validate session exists
        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);
        if (!$session) {
            return new JsonResponse(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        $lastEventId = (int) $request->query->get('lastEventId', 0);

        // Get new events from cache
        $events = $this->eventCache->getNewEvents($widgetId, $sessionId, $lastEventId);

        $eventData = [];
        $newLastEventId = $lastEventId;

        foreach ($events as $event) {
            $eventData[] = [
                'id' => $event['id'],
                'type' => $event['type'],
                ...$event['payload'],
            ];
            $newLastEventId = max($newLastEventId, $event['id']);
        }

        return new JsonResponse([
            'success' => true,
            'events' => $eventData,
            'lastEventId' => $newLastEventId,
        ]);
    }

    /**
     * Endpoint for widget notifications (used by admin UI).
     * Requires authentication.
     */
    #[Route('/api/v1/widgets/{widgetId}/notifications', name: 'api_widget_notifications', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/notifications',
        summary: 'Poll for widget notifications',
        security: [['Bearer' => []]],
        tags: ['Widget Events']
    )]
    public function notifications(
        string $widgetId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $widget = $this->widgetRepository->findByWidgetId($widgetId);
        if (!$widget) {
            return new JsonResponse(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if ($widget->getOwnerId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $lastEventId = (int) $request->query->get('lastEventId', 0);

        // Get new notifications from cache
        $events = $this->eventCache->getNewNotifications($widgetId, $lastEventId);

        $eventData = [];
        $newLastEventId = $lastEventId;

        foreach ($events as $event) {
            $eventData[] = [
                'id' => $event['id'],
                'type' => $event['type'],
                ...$event['payload'],
            ];
            $newLastEventId = max($newLastEventId, $event['id']);
        }

        return new JsonResponse([
            'success' => true,
            'events' => $eventData,
            'lastEventId' => $newLastEventId,
        ]);
    }
}
