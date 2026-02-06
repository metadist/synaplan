<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\WidgetRepository;
use App\Service\WidgetSummaryService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Widget Summary Controller.
 *
 * Endpoints for AI-generated chat summaries.
 */
#[Route('/api/v1/widgets/{widgetId}/summaries', name: 'api_widget_summaries_')]
#[OA\Tag(name: 'Widget Summaries')]
class WidgetSummaryController extends AbstractController
{
    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetSummaryService $summaryService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List recent summaries for a widget.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/summaries',
        summary: 'List recent summaries',
        security: [['Bearer' => []]],
        tags: ['Widget Summaries']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'integer', default: 7, maximum: 30)
    )]
    #[OA\Response(
        response: 200,
        description: 'List of summaries',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(
                    property: 'summaries',
                    type: 'array',
                    items: new OA\Items(type: 'object')
                ),
            ]
        )
    )]
    public function list(
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

        $limit = min((int) $request->query->get('limit', 7), 30);
        $summaries = $this->summaryService->getSummaries($widgetId, $limit);

        return $this->json([
            'success' => true,
            'summaries' => array_map(fn ($s) => [
                'id' => $s->getId(),
                'date' => $s->getDate(),
                'formattedDate' => $s->getFormattedDate(),
                'sessionCount' => $s->getSessionCount(),
                'messageCount' => $s->getMessageCount(),
                'topics' => $s->getTopics(),
                'faqs' => $s->getFaqs(),
                'sentiment' => $s->getSentiment(),
                'issues' => $s->getIssues(),
                'recommendations' => $s->getRecommendations(),
                'summary' => $s->getSummaryText(),
                'promptSuggestions' => $s->getPromptSuggestions(),
                'fromDate' => $s->getFromDate(),
                'toDate' => $s->getToDate(),
                'dateRange' => $s->getFormattedDateRange(),
                'created' => $s->getCreated(),
            ], $summaries),
        ]);
    }

    /**
     * Get summary for a specific date.
     */
    #[Route('/{date}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/summaries/{date}',
        summary: 'Get summary for a specific date',
        security: [['Bearer' => []]],
        tags: ['Widget Summaries']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(
        name: 'date',
        in: 'path',
        required: true,
        description: 'Date in YYYYMMDD format',
        schema: new OA\Schema(type: 'integer', example: 20240115)
    )]
    public function get(
        string $widgetId,
        int $date,
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

        $summary = $this->summaryService->getSummaryByDate($widgetId, $date);

        if (!$summary) {
            return $this->json(['error' => 'Summary not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'summary' => [
                'id' => $summary->getId(),
                'date' => $summary->getDate(),
                'formattedDate' => $summary->getFormattedDate(),
                'sessionCount' => $summary->getSessionCount(),
                'messageCount' => $summary->getMessageCount(),
                'topics' => $summary->getTopics(),
                'faqs' => $summary->getFaqs(),
                'sentiment' => $summary->getSentiment(),
                'issues' => $summary->getIssues(),
                'recommendations' => $summary->getRecommendations(),
                'summary' => $summary->getSummaryText(),
                'created' => $summary->getCreated(),
            ],
        ]);
    }

    /**
     * Generate a summary for a specific date.
     */
    #[Route('/generate', name: 'generate', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/summaries/generate',
        summary: 'Generate summary for a date',
        security: [['Bearer' => []]],
        tags: ['Widget Summaries']
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'date',
                    type: 'integer',
                    description: 'Date in YYYYMMDD format. Defaults to yesterday.',
                    example: 20240115
                ),
            ]
        )
    )]
    public function generate(
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

        $data = json_decode($request->getContent(), true) ?? [];
        $date = (int) ($data['date'] ?? (int) date('Ymd', strtotime('-1 day')));

        // Validate date format
        if (8 !== strlen((string) $date)) {
            return $this->json(['error' => 'Invalid date format. Use YYYYMMDD.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $summary = $this->summaryService->generateDailySummary($widget, $date);

            return $this->json([
                'success' => true,
                'summary' => [
                    'id' => $summary->getId(),
                    'date' => $summary->getDate(),
                    'formattedDate' => $summary->getFormattedDate(),
                    'sessionCount' => $summary->getSessionCount(),
                    'messageCount' => $summary->getMessageCount(),
                    'topics' => $summary->getTopics(),
                    'faqs' => $summary->getFaqs(),
                    'sentiment' => $summary->getSentiment(),
                    'issues' => $summary->getIssues(),
                    'recommendations' => $summary->getRecommendations(),
                    'summary' => $summary->getSummaryText(),
                    'created' => $summary->getCreated(),
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Summary generation failed', [
                'widget_id' => $widgetId,
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to generate summary: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generate an AI-powered custom summary for specific sessions or date range.
     */
    #[Route('/analyze', name: 'analyze', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/summaries/analyze',
        summary: 'Generate AI analysis for selected sessions or date range',
        security: [['Bearer' => []]],
        tags: ['Widget Summaries']
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'sessionIds',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    description: 'Specific session IDs to analyze'
                ),
                new OA\Property(
                    property: 'fromDate',
                    type: 'integer',
                    description: 'Start date in YYYYMMDD format',
                    example: 20240101
                ),
                new OA\Property(
                    property: 'toDate',
                    type: 'integer',
                    description: 'End date in YYYYMMDD format',
                    example: 20240115
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'AI-generated analysis',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'summary', type: 'object'),
            ]
        )
    )]
    public function analyze(
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

        $data = json_decode($request->getContent(), true) ?? [];
        $sessionIds = $data['sessionIds'] ?? null;
        $fromDate = isset($data['fromDate']) ? (int) $data['fromDate'] : null;
        $toDate = isset($data['toDate']) ? (int) $data['toDate'] : null;
        $summaryId = isset($data['summaryId']) ? (int) $data['summaryId'] : null;

        // Validate at least one filter is provided
        if (empty($sessionIds) && !$fromDate && !$toDate) {
            return $this->json([
                'error' => 'Please provide sessionIds or a date range (fromDate/toDate)',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $summary = $this->summaryService->generateCustomSummary(
                $widget,
                $sessionIds,
                $fromDate,
                $toDate,
                $summaryId
            );

            return $this->json([
                'success' => true,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Custom summary generation failed', [
                'widget_id' => $widgetId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to generate analysis: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
