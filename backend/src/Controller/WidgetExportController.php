<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\WidgetRepository;
use App\Service\WidgetExportService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Widget Export Controller.
 *
 * Endpoints for exporting widget chat data in various formats.
 */
#[Route('/api/v1/widgets/{widgetId}/export', name: 'api_widget_export_')]
#[OA\Tag(name: 'Widget Export')]
class WidgetExportController extends AbstractController
{
    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetExportService $exportService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Export widget sessions.
     *
     * Supports formats: xlsx (Excel), csv, json
     */
    #[Route('', name: 'export', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/export',
        summary: 'Export widget sessions',
        security: [['Bearer' => []]],
        tags: ['Widget Export']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(
        name: 'format',
        in: 'query',
        required: false,
        description: 'Export format',
        schema: new OA\Schema(type: 'string', enum: ['xlsx', 'csv', 'json'], default: 'xlsx')
    )]
    #[OA\Parameter(
        name: 'from',
        in: 'query',
        required: false,
        description: 'Start date (Unix timestamp)',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Parameter(
        name: 'to',
        in: 'query',
        required: false,
        description: 'End date (Unix timestamp)',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Parameter(
        name: 'mode',
        in: 'query',
        required: false,
        description: 'Filter by mode',
        schema: new OA\Schema(type: 'string', enum: ['ai', 'human', 'waiting'])
    )]
    #[OA\Response(
        response: 200,
        description: 'Export file',
        content: new OA\MediaType(
            mediaType: 'application/octet-stream',
            schema: new OA\Schema(type: 'string', format: 'binary')
        )
    )]
    public function export(
        string $widgetId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): Response {
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

        $format = $request->query->getString('format', 'xlsx');
        if (!in_array($format, ['xlsx', 'csv', 'json'], true)) {
            return $this->json(['error' => 'Invalid format. Use: xlsx, csv, json'], Response::HTTP_BAD_REQUEST);
        }

        $filters = [];
        if ($request->query->has('from')) {
            $filters['from'] = (int) $request->query->get('from');
        }
        if ($request->query->has('to')) {
            $filters['to'] = (int) $request->query->get('to');
        }
        if ($request->query->has('mode')) {
            $filters['mode'] = $request->query->getString('mode');
        }

        try {
            $filePath = match ($format) {
                'xlsx' => $this->exportService->exportToExcel($widget, $filters),
                'csv' => $this->exportService->exportToCsv($widget, $filters),
                'json' => $this->exportService->exportToJson($widget, $filters),
            };

            $contentType = match ($format) {
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'csv' => 'text/csv; charset=utf-8',
                'json' => 'application/json; charset=utf-8',
            };

            $filename = sprintf(
                '%s-export-%s.%s',
                preg_replace('/[^a-zA-Z0-9_-]/', '_', $widget->getName()),
                date('Y-m-d'),
                $format
            );

            $response = new BinaryFileResponse($filePath);
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename
            );
            $response->headers->set('Content-Type', $contentType);
            $response->deleteFileAfterSend(true);

            $this->logger->info('Widget export generated', [
                'widget_id' => $widgetId,
                'format' => $format,
                'user_id' => $user->getId(),
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Widget export failed', [
                'widget_id' => $widgetId,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Export failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get available export formats.
     */
    #[Route('/formats', name: 'formats', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/export/formats',
        summary: 'Get available export formats',
        security: [['Bearer' => []]],
        tags: ['Widget Export']
    )]
    public function formats(string $widgetId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'formats' => [
                [
                    'id' => 'xlsx',
                    'name' => 'Excel (XLSX)',
                    'description' => 'Best for human readability. Formatted with colors and multiple sheets.',
                    'recommended' => true,
                ],
                [
                    'id' => 'csv',
                    'name' => 'CSV',
                    'description' => 'Simple flat format. Compatible with any spreadsheet software.',
                    'recommended' => false,
                ],
                [
                    'id' => 'json',
                    'name' => 'JSON',
                    'description' => 'Structured format for developers and data analysis tools.',
                    'recommended' => false,
                ],
            ],
        ]);
    }
}
