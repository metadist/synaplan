<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\FeedbackExampleService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/feedback')]
#[OA\Tag(name: 'Feedback')]
final class FeedbackController extends AbstractController
{
    public function __construct(
        private readonly FeedbackExampleService $feedbackExampleService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/feedback',
        summary: 'List all feedback examples',
        description: 'Returns all false positive and positive feedback examples for the current user',
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', description: 'Filter by type (false_positive, positive, or all)', schema: new OA\Schema(type: 'string', enum: ['false_positive', 'positive', 'all'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of feedback examples',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'feedbacks',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'type', type: 'string', enum: ['false_positive', 'positive']),
                                    new OA\Property(property: 'value', type: 'string'),
                                    new OA\Property(property: 'messageId', type: 'integer', nullable: true),
                                    new OA\Property(property: 'created', type: 'integer'),
                                    new OA\Property(property: 'updated', type: 'integer'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 503, description: 'Memory service unavailable'),
        ]
    )]
    public function listFeedbacks(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $type = $request->query->get('type', 'all');

        try {
            $feedbacks = $this->feedbackExampleService->listFeedbacks($user, $type);

            return $this->json(['feedbacks' => $feedbacks]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list feedbacks', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to list feedbacks'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/feedback/{id}',
        summary: 'Update a feedback example',
        description: 'Updates the value of a feedback example',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['value'],
                properties: [
                    new OA\Property(property: 'value', type: 'string', example: 'Updated feedback text'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Feedback updated'),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feedback not found'),
        ]
    )]
    public function updateFeedback(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $value = trim((string) ($data['value'] ?? ''));
        if (mb_strlen($value) < 5) {
            return $this->json(['error' => 'Value must be at least 5 characters'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $feedback = $this->feedbackExampleService->updateFeedback($user, $id, $value);

            return $this->json(['success' => true, 'feedback' => $feedback]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update feedback', [
                'user_id' => $user->getId(),
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to update feedback'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/feedback/{id}',
        summary: 'Delete a feedback example',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Feedback deleted'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feedback not found'),
        ]
    )]
    public function deleteFeedback(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->feedbackExampleService->deleteFeedback($user, $id);

            return $this->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete feedback', [
                'user_id' => $user->getId(),
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to delete feedback'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/false-positive', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/feedback/false-positive',
        summary: 'Submit a false-positive example',
        description: 'Stores a confirmed false-positive summary as a negative feedback example',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['summary'],
                properties: [
                    new OA\Property(property: 'summary', type: 'string', example: 'Claims Sydney is the capital of Australia.'),
                    new OA\Property(property: 'messageId', type: 'integer', nullable: true, example: 1234),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'False-positive stored',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'example',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 123456789),
                                new OA\Property(property: 'category', type: 'string', example: 'feedback_negative'),
                                new OA\Property(property: 'key', type: 'string', example: 'false_positive'),
                                new OA\Property(property: 'value', type: 'string', example: 'Claims Sydney is the capital of Australia.'),
                                new OA\Property(property: 'source', type: 'string', example: 'user_created'),
                                new OA\Property(property: 'messageId', type: 'integer', nullable: true, example: 1234),
                                new OA\Property(property: 'created', type: 'integer', example: 1737115234),
                                new OA\Property(property: 'updated', type: 'integer', example: 1737115234),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 503, description: 'Memory service unavailable'),
        ]
    )]
    public function createFalsePositive(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $summary = trim((string) ($data['summary'] ?? ''));
        if (mb_strlen($summary) < 5) {
            return $this->json(['error' => 'Summary must be at least 5 characters'], Response::HTTP_BAD_REQUEST);
        }

        $messageId = isset($data['messageId']) ? (int) $data['messageId'] : null;

        try {
            $example = $this->feedbackExampleService->createFalsePositive($user, $summary, $messageId);

            return $this->json([
                'success' => true,
                'example' => $example->toArray(),
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store false-positive example', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to store feedback'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/false-positive/preview', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/feedback/false-positive/preview',
        summary: 'Preview a false-positive summary and correction',
        description: 'Generates a summary and a corrected statement for user confirmation. Optionally accepts the user message for better context.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['text'],
                properties: [
                    new OA\Property(property: 'text', type: 'string', example: 'The answer claims the capital of Australia is Sydney.'),
                    new OA\Property(property: 'userMessage', type: 'string', nullable: true, example: 'What is the capital of Australia?', description: 'The user question that prompted this AI response, for better context'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Preview data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'summary', type: 'string', example: 'Claims Sydney is the capital of Australia.'),
                        new OA\Property(property: 'correction', type: 'string', example: 'The capital of Australia is Canberra.'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function previewFalsePositive(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $text = trim((string) ($data['text'] ?? ''));
        if (mb_strlen($text) < 10) {
            return $this->json(['error' => 'Text must be at least 10 characters'], Response::HTTP_BAD_REQUEST);
        }

        $userMessage = isset($data['userMessage']) ? trim((string) $data['userMessage']) : null;

        $preview = $this->feedbackExampleService->previewFalsePositive($user, $text, $userMessage);

        return $this->json($preview);
    }

    #[Route('/false-positive/regenerate', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/feedback/false-positive/regenerate',
        summary: 'Regenerate correction for a false positive',
        description: 'Takes the false claim and a previous incorrect correction, generates a better correction',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['falseClaim'],
                properties: [
                    new OA\Property(property: 'falseClaim', type: 'string', example: 'Sydney is the capital of Australia'),
                    new OA\Property(property: 'oldCorrection', type: 'string', example: 'The capital is Canberra', description: 'Previous incorrect correction to improve upon'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Regenerated correction',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'correction', type: 'string', example: 'The capital of Australia is Canberra, not Sydney.'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function regenerateCorrection(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $falseClaim = trim((string) ($data['falseClaim'] ?? ''));
        if (mb_strlen($falseClaim) < 5) {
            return $this->json(['error' => 'False claim must be at least 5 characters'], Response::HTTP_BAD_REQUEST);
        }

        $oldCorrection = trim((string) ($data['oldCorrection'] ?? ''));

        $correction = $this->feedbackExampleService->regenerateCorrection($user, $falseClaim, $oldCorrection);

        return $this->json(['correction' => $correction]);
    }

    #[Route('/positive', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/feedback/positive',
        summary: 'Submit a positive correction example',
        description: 'Stores a corrected statement as a positive feedback example',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['text'],
                properties: [
                    new OA\Property(property: 'text', type: 'string', example: 'The capital of Australia is Canberra.'),
                    new OA\Property(property: 'messageId', type: 'integer', nullable: true, example: 1234),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Positive example stored',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'example',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 123456789),
                                new OA\Property(property: 'category', type: 'string', example: 'feedback_positive'),
                                new OA\Property(property: 'key', type: 'string', example: 'positive_example'),
                                new OA\Property(property: 'value', type: 'string', example: 'The capital of Australia is Canberra.'),
                                new OA\Property(property: 'source', type: 'string', example: 'user_created'),
                                new OA\Property(property: 'messageId', type: 'integer', nullable: true, example: 1234),
                                new OA\Property(property: 'created', type: 'integer', example: 1737115234),
                                new OA\Property(property: 'updated', type: 'integer', example: 1737115234),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 503, description: 'Memory service unavailable'),
        ]
    )]
    public function createPositive(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $text = trim((string) ($data['text'] ?? ''));
        if (mb_strlen($text) < 5) {
            return $this->json(['error' => 'Text must be at least 5 characters'], Response::HTTP_BAD_REQUEST);
        }

        $messageId = isset($data['messageId']) ? (int) $data['messageId'] : null;

        try {
            $example = $this->feedbackExampleService->createPositive($user, $text, $messageId);

            return $this->json([
                'success' => true,
                'example' => $example->toArray(),
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store positive example', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to store feedback'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
