<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\FeedbackContradictionService;
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
        private readonly FeedbackContradictionService $feedbackContradictionService,
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

    #[Route('/check-contradictions', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/feedback/check-contradictions',
        summary: 'Check for contradictions before saving feedback',
        description: 'Vectorizes the text, searches Qdrant for related memories and feedback, asks AI if contradictions exist. Returns contradictions if any.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['text', 'type'],
                properties: [
                    new OA\Property(property: 'text', type: 'string', example: 'The capital of Australia is Canberra.'),
                    new OA\Property(property: 'type', type: 'string', enum: ['false_positive', 'positive']),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contradiction check result',
                content: new OA\JsonContent(
                    required: ['hasContradictions', 'contradictions'],
                    properties: [
                        new OA\Property(property: 'hasContradictions', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'contradictions',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 123),
                                    new OA\Property(property: 'type', type: 'string', enum: ['memory', 'false_positive', 'positive']),
                                    new OA\Property(property: 'value', type: 'string', example: 'Sydney is the capital of Australia'),
                                    new OA\Property(property: 'reason', type: 'string', example: 'User previously confirmed Sydney; now marks Canberra as correct'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function checkContradictions(
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
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, ['false_positive', 'positive'], true)) {
            return $this->json(['error' => 'type must be false_positive or positive'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($text) < 5) {
            return $this->json(['error' => 'Text must be at least 5 characters'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->feedbackContradictionService->checkContradictions($user, $text, $type);

        return $this->json($result);
    }

    #[Route('/check-contradictions-batch', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/feedback/check-contradictions-batch',
        summary: 'Batch check contradictions for summary + correction',
        description: 'Checks both summary and correction for contradictions in a single operation (one vector search + one AI call)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['summary', 'correction'],
                properties: [
                    new OA\Property(property: 'summary', type: 'string', example: 'Claims Sydney is the capital of Australia.'),
                    new OA\Property(property: 'correction', type: 'string', example: 'The capital of Australia is Canberra.'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Batch contradiction check result',
                content: new OA\JsonContent(
                    required: ['hasContradictions', 'contradictions'],
                    properties: [
                        new OA\Property(property: 'hasContradictions', type: 'boolean'),
                        new OA\Property(
                            property: 'contradictions',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'type', type: 'string', enum: ['memory', 'false_positive', 'positive']),
                                    new OA\Property(property: 'value', type: 'string'),
                                    new OA\Property(property: 'reason', type: 'string'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function checkContradictionsBatch(
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
        $correction = trim((string) ($data['correction'] ?? ''));

        if (mb_strlen($summary) < 5 && mb_strlen($correction) < 5) {
            return $this->json(['error' => 'At least one of summary or correction must be at least 5 characters'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->feedbackContradictionService->checkContradictionsBatch($user, $summary, $correction);

        return $this->json($result);
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
        summary: 'Preview false-positive summary and correction options',
        description: 'Generates multiple summary and correction options for user selection. Optionally accepts the user message for better context.',
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
                description: 'Preview options with classification',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'classification',
                            type: 'string',
                            enum: ['memory', 'feedback'],
                            example: 'feedback',
                            description: 'Whether the error is about personal user data (memory) or general knowledge (feedback)'
                        ),
                        new OA\Property(
                            property: 'summaryOptions',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['Claims Sydney is the capital of Australia.', 'Incorrectly states Sydney as capital.']
                        ),
                        new OA\Property(
                            property: 'correctionOptions',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['The capital of Australia is Canberra.', 'Canberra, not Sydney, is the capital of Australia.']
                        ),
                        new OA\Property(
                            property: 'relatedMemoryIds',
                            type: 'array',
                            items: new OA\Items(type: 'integer'),
                            description: 'IDs of user memories found to be related to the marked text. Used by the frontend to target specific memories for update/deletion.',
                            example: [42, 87]
                        ),
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

    #[Route('/false-positive/research', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/feedback/false-positive/research',
        summary: 'Research sources from user data for a false-positive claim',
        description: 'Searches the user\'s uploaded documents, existing feedbacks, and personal memories for relevant content and returns AI-summarized source entries. Each source includes a sourceType to distinguish the data origin.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['text'],
                properties: [
                    new OA\Property(
                        property: 'text',
                        type: 'string',
                        description: 'The claim or summary text to research',
                        example: 'The capital of Australia is Sydney.'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Research results with source summaries',
                content: new OA\JsonContent(
                    required: ['sources'],
                    properties: [
                        new OA\Property(
                            property: 'sources',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 42),
                                    new OA\Property(property: 'sourceType', type: 'string', enum: ['file', 'feedback_false', 'feedback_correct', 'memory'], example: 'file', description: 'Origin type: file=uploaded document, feedback_false=previously marked incorrect, feedback_correct=previously confirmed, memory=personal memory'),
                                    new OA\Property(property: 'fileName', type: 'string', example: 'geography-notes.pdf', description: 'Only populated for sourceType=file'),
                                    new OA\Property(property: 'excerpt', type: 'string', example: 'The capital of Australia is Canberra, not Sydney...'),
                                    new OA\Property(property: 'summary', type: 'string', example: 'This source states that Canberra is the capital of Australia.'),
                                    new OA\Property(property: 'score', type: 'number', format: 'float', example: 0.87),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function researchSources(
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

        try {
            $result = $this->feedbackExampleService->researchSources($user, $text);

            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->error('Source research failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Research failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/false-positive/web-research', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/feedback/false-positive/web-research',
        summary: 'Research sources from the web for a false-positive claim',
        description: 'Searches the web via Brave Search for relevant sources and returns AI-summarized entries. The user can then select which sources are correct.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['text'],
                properties: [
                    new OA\Property(
                        property: 'text',
                        type: 'string',
                        description: 'The claim or summary text to fact-check on the web',
                        example: 'The capital of Australia is Sydney.'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Web research results with source summaries',
                content: new OA\JsonContent(
                    required: ['sources'],
                    properties: [
                        new OA\Property(
                            property: 'sources',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 0),
                                    new OA\Property(property: 'title', type: 'string', example: 'Capital of Australia - Wikipedia'),
                                    new OA\Property(property: 'url', type: 'string', example: 'https://en.wikipedia.org/wiki/Canberra'),
                                    new OA\Property(property: 'summary', type: 'string', example: 'This source confirms that Canberra is the capital of Australia.'),
                                    new OA\Property(property: 'snippet', type: 'string', example: 'Canberra is the capital city of Australia...'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function webResearchSources(
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

        try {
            $result = $this->feedbackExampleService->webResearchSources($text);

            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->error('Web research failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Web research failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
