<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ContentModerationService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * User-facing content reporting (Apple App Review Guideline 1.2).
 *
 * Any authenticated user can flag a chat message or file as objectionable; the
 * report is stored and the operator is notified. Operator review + account
 * actions live in {@see AdminModerationController}.
 */
#[Route('/api/v1/moderation')]
#[OA\Tag(name: 'Moderation')]
final class ContentReportController extends AbstractController
{
    public function __construct(
        private readonly ContentModerationService $moderationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/reports', name: 'moderation_report_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/moderation/reports',
        summary: 'Report objectionable content',
        tags: ['Moderation']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['contentType', 'contentId', 'reason'],
            properties: [
                new OA\Property(property: 'contentType', type: 'string', enum: ['message', 'file'], example: 'message'),
                new OA\Property(property: 'contentId', type: 'integer', example: 12345),
                new OA\Property(property: 'reason', type: 'string', enum: ['spam', 'harassment', 'hate_speech', 'violence', 'sexual_content', 'csae', 'illegal', 'other'], example: 'harassment'),
                new OA\Property(property: 'details', type: 'string', nullable: true, description: 'Optional free-text context (max 2000 chars)'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Report received',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'reportId', type: 'integer', nullable: true, example: 42),
        ])
    )]
    #[OA\Response(response: 400, description: 'Invalid content type or reason')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $decoded = json_decode($request->getContent(), true);
        $body = is_array($decoded) ? $decoded : [];

        $contentType = isset($body['contentType']) && is_string($body['contentType']) ? $body['contentType'] : '';
        $contentId = isset($body['contentId']) && is_numeric($body['contentId']) ? (int) $body['contentId'] : 0;
        $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : '';
        $details = isset($body['details']) && is_string($body['details']) ? $body['details'] : null;

        if ('' === $contentType || $contentId <= 0 || '' === $reason) {
            return $this->json(['error' => 'contentType, contentId and reason are required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $report = $this->moderationService->report($user, $contentType, $contentId, $reason, $details);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store content report', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to store report'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success' => true,
            'reportId' => $report->getId(),
        ], Response::HTTP_CREATED);
    }
}
