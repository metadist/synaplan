<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Message\RoutingFeedbackService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User-facing endpoint for routing feedback corrections.
 *
 * When a user notices a message was routed to the wrong use case
 * (e.g. expected image generation but got chat), they submit a
 * correction via this endpoint. The feedback is forwarded to the
 * external synaplan-router for model retraining.
 */
#[Route('/api/v1/routing')]
#[IsGranted('ROLE_USER')]
#[OA\Tag(name: 'Routing')]
final class RoutingFeedbackController extends AbstractController
{
    public function __construct(
        private readonly RoutingFeedbackService $feedbackService,
    ) {
    }

    #[Route('/feedback', name: 'routing_feedback', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/routing/feedback',
        summary: 'Submit routing feedback correction',
        description: 'Report that a message was routed to the wrong use case. The correction is forwarded to the ML router for retraining.',
        security: [['Bearer' => []]],
        tags: ['Routing'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message_id', 'correct_use_case'],
                properties: [
                    new OA\Property(property: 'message_id', type: 'integer', description: 'The message that was misrouted'),
                    new OA\Property(property: 'correct_use_case', type: 'string', description: 'The use case it should have been routed to'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Feedback accepted',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function submitFeedback(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);

        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $messageId = $body['message_id'] ?? null;
        $correctUseCase = $body['correct_use_case'] ?? null;

        if (!is_int($messageId) || !is_string($correctUseCase) || '' === trim($correctUseCase)) {
            return $this->json(
                ['error' => 'Required fields: message_id (int), correct_use_case (string)'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $result = $this->feedbackService->submitFeedback($messageId, trim($correctUseCase), $user->getId());

        if (!$result['success']) {
            return $this->json(['error' => $result['error']], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/use-cases', name: 'routing_use_cases', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/routing/use-cases',
        summary: 'List available use case labels',
        description: 'Returns the list of use case labels the router is trained on. Used to populate the feedback correction dropdown.',
        security: [['Bearer' => []]],
        tags: ['Routing']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of use case labels',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'use_cases',
                    type: 'array',
                    items: new OA\Items(type: 'string')
                ),
            ]
        )
    )]
    public function getUseCases(): JsonResponse
    {
        $useCases = $this->feedbackService->getAvailableUseCases();

        return $this->json(['use_cases' => $useCases]);
    }
}
