<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\HumanTakeoverService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Widget Live Support Controller.
 *
 * Endpoints for human takeover functionality in chat widgets.
 */
#[Route('/api/v1/widgets/{widgetId}/sessions/{sessionId}', name: 'api_widget_live_support_')]
#[OA\Tag(name: 'Widget Live Support')]
class WidgetLiveSupportController extends AbstractController
{
    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetSessionRepository $sessionRepository,
        private HumanTakeoverService $takeoverService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Take over a session from AI.
     */
    #[Route('/takeover', name: 'takeover', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/takeover',
        summary: 'Take over a session from AI',
        security: [['Bearer' => []]],
        tags: ['Widget Live Support']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Session taken over successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'session', type: 'object'),
            ]
        )
    )]
    public function takeover(
        string $widgetId,
        string $sessionId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Verify widget ownership
        $widget = $this->widgetRepository->findByWidgetId($widgetId);
        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        if ($widget->getOwnerId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        try {
            $session = $this->takeoverService->takeOver($widgetId, $sessionId, $user);

            return $this->json([
                'success' => true,
                'session' => [
                    'id' => $session->getId(),
                    'sessionId' => $session->getSessionId(),
                    'mode' => $session->getMode(),
                    'humanOperatorId' => $session->getHumanOperatorId(),
                    'lastHumanActivity' => $session->getLastHumanActivity(),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Takeover failed', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to take over session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Hand back a session to AI.
     */
    #[Route('/handback', name: 'handback', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/handback',
        summary: 'Hand back session to AI',
        security: [['Bearer' => []]],
        tags: ['Widget Live Support']
    )]
    public function handback(
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

        try {
            $session = $this->takeoverService->handBack($widgetId, $sessionId, $user);

            return $this->json([
                'success' => true,
                'session' => [
                    'id' => $session->getId(),
                    'sessionId' => $session->getSessionId(),
                    'mode' => $session->getMode(),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Handback failed', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to hand back session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send a message as human operator.
     */
    #[Route('/reply', name: 'reply', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/reply',
        summary: 'Send message as human operator',
        security: [['Bearer' => []]],
        tags: ['Widget Live Support']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['text'],
            properties: [
                new OA\Property(property: 'text', type: 'string', example: 'Hello! How can I help you?'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Message sent successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'messageId', type: 'integer'),
            ]
        )
    )]
    public function reply(
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

        $data = json_decode($request->getContent(), true);
        if (empty($data['text'])) {
            return $this->json(['error' => 'Missing required field: text'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $message = $this->takeoverService->sendHumanMessage(
                $widgetId,
                $sessionId,
                $data['text'],
                $user
            );

            return $this->json([
                'success' => true,
                'messageId' => $message->getId(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Human reply failed', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to send message'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
