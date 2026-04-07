<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\UsageStatsService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Usage Statistics Controller.
 *
 * Provides detailed usage statistics for authenticated users
 */
#[Route('/api/v1/usage', name: 'api_usage_')]
class UsageStatsController extends AbstractController
{
    public function __construct(
        private UsageStatsService $usageStatsService,
    ) {
    }

    /**
     * Get comprehensive usage statistics.
     *
     * GET /api/v1/usage/stats
     *
     * Returns:
     * - Current user level and subscription info
     * - Usage per action type (Messages, Images, Videos, etc.)
     * - Limits and remaining quota
     * - Breakdown by source (WhatsApp, Email, Web)
     * - Breakdown by time period (today, this week, this month)
     * - Recent usage history
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/usage/stats',
        summary: 'Get comprehensive usage statistics',
        tags: ['Usage Statistics'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Usage statistics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            description: 'Detailed usage statistics including limits, breakdowns by source and time period'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function getStats(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $stats = $this->usageStatsService->getUserStats($user);

        return $this->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get paginated activity log with filters.
     */
    #[Route('/activity', name: 'activity', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/usage/activity',
        summary: 'Get paginated activity log with search, action filter and date range',
        tags: ['Usage Statistics'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', nullable: true)),
            new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string', nullable: true)),
            new OA\Parameter(name: 'from', in: 'query', description: 'Unix timestamp (start)', schema: new OA\Schema(type: 'integer', nullable: true)),
            new OA\Parameter(name: 'to', in: 'query', description: 'Unix timestamp (end)', schema: new OA\Schema(type: 'integer', nullable: true)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated activity log',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'data', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function getActivity(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = max(1, min(100, $request->query->getInt('per_page', 20)));
        $search = $request->query->get('search') ? trim($request->query->get('search')) : null;
        $action = $request->query->get('action') ? trim($request->query->get('action')) : null;
        $from = $request->query->getInt('from') ?: null;
        $to = $request->query->getInt('to') ?: null;

        $data = $this->usageStatsService->getActivityLog(
            $user->getId(),
            $page,
            $perPage,
            $search,
            $action,
            $from,
            $to,
        );

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Export usage data as CSV.
     *
     * GET /api/v1/usage/export
     *
     * Query parameters:
     * - since: Unix timestamp (optional) - only include data since this timestamp
     *
     * Returns CSV file download
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function exportCsv(
        Request $request,
        #[CurrentUser] ?User $user,
    ): StreamedResponse {
        if (!$user) {
            return new StreamedResponse(
                function () {
                    echo 'Unauthorized';
                },
                Response::HTTP_UNAUTHORIZED
            );
        }

        $sinceTimestamp = $request->query->getInt('since') ?: null;

        $csv = $this->usageStatsService->exportUsageAsCsv($user, $sinceTimestamp);

        $response = new StreamedResponse(function () use ($csv) {
            echo $csv;
        });

        $filename = sprintf(
            'synaplan-usage-%s-%s.csv',
            $user->getId(),
            date('Y-m-d')
        );

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }
}
