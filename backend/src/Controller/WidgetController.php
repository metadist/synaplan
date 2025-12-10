<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Widget;
use App\Repository\WidgetRepository;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Widget Management Controller.
 *
 * Authenticated endpoints for widget owners to manage their widgets
 */
#[Route('/api/v1/widgets', name: 'api_widgets_')]
#[OA\Tag(name: 'Widgets')]
class WidgetController extends AbstractController
{
    public function __construct(
        private WidgetService $widgetService,
        private WidgetSessionService $sessionService,
        private WidgetRepository $widgetRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List all widgets for current user.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets',
        summary: 'List all widgets for current user',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of widgets',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(
                    property: 'widgets',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'widgetId', type: 'string', example: 'wdg_abc123...'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'taskPromptTopic', type: 'string'),
                            new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
                            new OA\Property(property: 'config', type: 'object'),
                            new OA\Property(property: 'created', type: 'integer'),
                            new OA\Property(property: 'updated', type: 'integer'),
                        ]
                    )
                ),
            ]
        )
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $widgets = $this->widgetService->listWidgetsByOwner($user);

        $widgetsData = array_map(function (Widget $widget) {
            return [
                'id' => $widget->getId(),
                'widgetId' => $widget->getWidgetId(),
                'name' => $widget->getName(),
                'taskPromptTopic' => $widget->getTaskPromptTopic(),
                'status' => $widget->getStatus(),
                'config' => $widget->getConfig(),
                'allowedDomains' => $widget->getAllowedDomains(),
                'isActive' => $this->widgetService->isWidgetActive($widget),
                'created' => $widget->getCreated(),
                'updated' => $widget->getUpdated(),
            ];
        }, $widgets);

        return $this->json([
            'success' => true,
            'widgets' => $widgetsData,
        ]);
    }

    /**
     * Create new widget.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets',
        summary: 'Create new widget',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'taskPromptTopic'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Support Chat'),
                new OA\Property(property: 'taskPromptTopic', type: 'string', example: 'customer-support'),
                new OA\Property(property: 'config', type: 'object'),
            ]
        )
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (empty($data['name']) || empty($data['taskPromptTopic'])) {
            return $this->json([
                'error' => 'Missing required fields: name, taskPromptTopic',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $widget = $this->widgetService->createWidget(
                $user,
                $data['name'],
                $data['taskPromptTopic'],
                $data['config'] ?? []
            );

            $widget->syncAllowedDomainsFromConfig();

            return $this->json([
                'success' => true,
                'message' => 'Widget created successfully',
                'widget' => [
                    'id' => $widget->getId(),
                    'widgetId' => $widget->getWidgetId(),
                    'name' => $widget->getName(),
                    'taskPromptTopic' => $widget->getTaskPromptTopic(),
                    'status' => $widget->getStatus(),
                    'config' => $widget->getConfig(),
                    'allowedDomains' => $widget->getAllowedDomains(),
                    'created' => $widget->getCreated(),
                    'updated' => $widget->getUpdated(),
                ],
            ], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create widget', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);

            return $this->json([
                'error' => 'Failed to create widget',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get widget details.
     */
    #[Route('/{widgetId}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}',
        summary: 'Get widget details',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    #[OA\Parameter(
        name: 'widgetId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string')
    )]
    public function get(string $widgetId, #[CurrentUser] ?User $user): JsonResponse
    {
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

        // Get statistics
        $stats = $this->sessionService->getWidgetStats($widgetId);

        return $this->json([
            'success' => true,
            'widget' => [
                'id' => $widget->getId(),
                'widgetId' => $widget->getWidgetId(),
                'name' => $widget->getName(),
                'taskPromptTopic' => $widget->getTaskPromptTopic(),
                'status' => $widget->getStatus(),
                'config' => $widget->getConfig(),
                'allowedDomains' => $widget->getAllowedDomains(),
                'isActive' => $this->widgetService->isWidgetActive($widget),
                'created' => $widget->getCreated(),
                'updated' => $widget->getUpdated(),
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Update widget.
     */
    #[Route('/{widgetId}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/widgets/{widgetId}',
        summary: 'Update widget',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    public function update(string $widgetId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
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

        error_log('🔧 Widget update request - widgetId: '.$widgetId);
        error_log('🔧 Data received: '.json_encode($data));

        try {
            if (isset($data['name'])) {
                $this->widgetService->updateWidgetName($widget, $data['name']);
            }

            if (isset($data['config'])) {
                error_log('🔧 Config received: '.json_encode($data['config']));
                error_log('🔧 allowedDomains in config: '.json_encode($data['config']['allowedDomains'] ?? []));
                $this->widgetService->updateWidget($widget, $data['config']);
            }

            if (isset($data['status']) && in_array($data['status'], ['active', 'inactive'])) {
                $widget->setStatus($data['status']);
            }

            // Always flush after updates
            $this->em->flush();

            error_log('🔧 After flush - allowedDomains: '.json_encode($widget->getAllowedDomains()));

            return $this->json([
                'success' => true,
                'message' => 'Widget updated successfully',
            ]);
        } catch (\Exception $e) {
            error_log('❌ Widget update error: '.$e->getMessage());
            error_log('❌ Stack trace: '.$e->getTraceAsString());

            $this->logger->error('Failed to update widget', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'widget_id' => $widgetId,
            ]);

            return $this->json([
                'error' => 'Failed to update widget',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete widget.
     */
    #[Route('/{widgetId}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/widgets/{widgetId}',
        summary: 'Delete widget',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    public function delete(string $widgetId, #[CurrentUser] ?User $user): JsonResponse
    {
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
            $this->widgetService->deleteWidget($widget);

            return $this->json([
                'success' => true,
                'message' => 'Widget deleted successfully',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete widget', [
                'error' => $e->getMessage(),
                'widget_id' => $widgetId,
            ]);

            return $this->json([
                'error' => 'Failed to delete widget',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get embed code for widget.
     */
    #[Route('/{widgetId}/embed', name: 'embed', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/embed',
        summary: 'Get embed code for widget',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    public function embedCode(string $widgetId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
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

        $baseUrl = $this->getParameter('synaplan_url');
        $embedCode = $this->widgetService->generateEmbedCode($widget, rtrim($baseUrl, '/'));
        $wordpressShortcode = $this->widgetService->generateWordPressShortcode($widget);

        return $this->json([
            'success' => true,
            'embedCode' => $embedCode,
            'wordpressShortcode' => $wordpressShortcode,
            'widgetUrl' => rtrim($baseUrl, '/').'/widget.js',
        ]);
    }

    /**
     * Get widget statistics.
     */
    #[Route('/{widgetId}/stats', name: 'stats', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widgets/{widgetId}/stats',
        summary: 'Get widget statistics',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    public function stats(string $widgetId, #[CurrentUser] ?User $user): JsonResponse
    {
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

        $stats = $this->sessionService->getWidgetStats($widgetId);

        return $this->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Upload widget icon.
     */
    #[Route('/{widgetId}/upload-icon', name: 'upload_icon', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/upload-icon',
        summary: 'Upload widget button icon',
        security: [['Bearer' => []]],
        tags: ['Widgets']
    )]
    public function uploadIcon(string $widgetId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
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

        $uploadedFile = $request->files->get('icon');

        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        // Validate file type
        $allowedMimes = ['image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!in_array($uploadedFile->getMimeType(), $allowedMimes)) {
            return $this->json(['error' => 'Invalid file type. Only SVG, PNG, JPG, GIF, and WebP are allowed.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate file size (max 500KB)
        if ($uploadedFile->getSize() > 500 * 1024) {
            return $this->json(['error' => 'File too large. Maximum size is 500KB.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Create upload directory if it doesn't exist
            $uploadDir = $this->getParameter('kernel.project_dir').'/public/uploads/widget-icons';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $extension = $uploadedFile->guessExtension() ?? 'png';
            $filename = uniqid('icon_').'_'.time().'.'.$extension;

            // Move file to upload directory
            $uploadedFile->move($uploadDir, $filename);

            // Generate public URL
            $baseUrl = $request->getSchemeAndHttpHost();
            $iconUrl = $baseUrl.'/uploads/widget-icons/'.$filename;

            $this->logger->info('Widget icon uploaded', [
                'widget_id' => $widgetId,
                'filename' => $filename,
                'url' => $iconUrl,
            ]);

            return $this->json([
                'success' => true,
                'iconUrl' => $iconUrl,
                'filename' => $filename,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to upload widget icon', [
                'widget_id' => $widgetId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to upload icon: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
