<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\UsageStatsService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

        $sinceTimestamp = $_GET['since'] ?? null;
        if ($sinceTimestamp) {
            $sinceTimestamp = (int) $sinceTimestamp;
        }

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
