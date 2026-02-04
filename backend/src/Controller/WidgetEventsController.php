<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\WidgetEventRepository;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
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
 */
#[OA\Tag(name: 'Widget Events')]
class WidgetEventsController extends AbstractController
{
    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetSessionRepository $sessionRepository,
        private WidgetEventRepository $eventRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * SSE endpoint for widget sessions (used by the embedded widget).
     * Anonymous access allowed for widget users.
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

        // For SSE, return a streamed response
        $response = new StreamedResponse(function () use ($widgetId, $sessionId, $lastEventId) {
            $currentLastEventId = $lastEventId;

            // Send initial connection event
            echo "event: connected\n";
            echo "data: {\"status\":\"connected\"}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            // Keep connection open for 5 minutes max
            $startTime = time();
            $maxDuration = 300; // 5 minutes
            $heartbeatInterval = 15; // Send heartbeat every 15 seconds
            $lastHeartbeat = time();
            $checkInterval = 2; // Check for events every 2 seconds

            while (time() - $startTime < $maxDuration) {
                // Check if client disconnected
                if (connection_aborted()) {
                    break;
                }

                // Get new events
                $events = $this->eventRepository->findNewEvents($widgetId, $sessionId, $currentLastEventId);

                foreach ($events as $event) {
                    $data = [
                        'type' => $event->getType(),
                        ...$event->getPayload(),
                    ];

                    echo 'id: '.$event->getId()."\n";
                    echo 'event: '.$event->getType()."\n";
                    echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();

                    $currentLastEventId = $event->getId();
                }

                // Send heartbeat periodically to keep connection alive
                if (time() - $lastHeartbeat >= $heartbeatInterval) {
                    echo ": heartbeat\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $lastHeartbeat = time();
                }

                // Wait before checking again
                sleep($checkInterval);
            }

            // End with reconnect hint (client should reconnect)
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

        // Get new events
        $events = $this->eventRepository->findNewEvents($widgetId, $sessionId, $lastEventId);

        $eventData = [];
        $newLastEventId = $lastEventId;

        foreach ($events as $event) {
            $eventData[] = [
                'id' => $event->getId(),
                'type' => $event->getType(),
                ...$event->getPayload(),
            ];
            $newLastEventId = max($newLastEventId, $event->getId());
        }

        return new JsonResponse([
            'success' => true,
            'events' => $eventData,
            'lastEventId' => $newLastEventId,
        ]);
    }

    /**
     * SSE endpoint for widget notifications (used by admin UI).
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

        // Get new notification events
        $events = $this->eventRepository->findNewNotifications($widgetId, $lastEventId);

        $eventData = [];
        $newLastEventId = $lastEventId;

        foreach ($events as $event) {
            $eventData[] = [
                'id' => $event->getId(),
                'type' => $event->getType(),
                ...$event->getPayload(),
            ];
            $newLastEventId = max($newLastEventId, $event->getId());
        }

        return new JsonResponse([
            'success' => true,
            'events' => $eventData,
            'lastEventId' => $newLastEventId,
        ]);
    }
}
