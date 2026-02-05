<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\File\FileStorageService;
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
    private const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB

    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetSessionRepository $sessionRepository,
        private HumanTakeoverService $takeoverService,
        private FileStorageService $fileStorageService,
        private FileRepository $fileRepository,
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
     * Upload file as operator for a session.
     */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets/{widgetId}/sessions/{sessionId}/upload',
        summary: 'Upload file as operator',
        security: [['Bearer' => []]],
        tags: ['Widget Live Support']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'File uploaded successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'fileId', type: 'integer'),
                new OA\Property(property: 'filename', type: 'string'),
            ]
        )
    )]
    public function upload(
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

        // Verify session exists and is in human mode
        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$session->isHumanMode()) {
            return $this->json(['error' => 'Session is not in human mode'], Response::HTTP_BAD_REQUEST);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile || !$uploadedFile->isValid()) {
            return $this->json(['error' => 'No valid file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        // Check file size
        if ($uploadedFile->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => 'File size exceeds 10MB limit'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Store the file
            $storageResult = $this->fileStorageService->storeUploadedFile(
                $uploadedFile,
                $user->getId()
            );

            if (!$storageResult['success']) {
                return $this->json(['error' => $storageResult['error'] ?? 'Failed to store file'], Response::HTTP_BAD_REQUEST);
            }

            // Create File entity with correct method names
            $file = new File();
            $file->setUserId($user->getId());
            $file->setFileName($uploadedFile->getClientOriginalName());
            $file->setFilePath($storageResult['path']);
            $file->setFileSize($storageResult['size']);
            $file->setFileMime($storageResult['mime']);
            $file->setFileType($this->getFileTypeFromMime($storageResult['mime']));
            $file->setStatus('pending'); // Will be 'attached' when message is sent

            $this->fileRepository->getEntityManager()->persist($file);
            $this->fileRepository->getEntityManager()->flush();

            return $this->json([
                'success' => true,
                'fileId' => $file->getId(),
                'filename' => $file->getFileName(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Operator file upload failed', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Failed to upload file'], Response::HTTP_INTERNAL_SERVER_ERROR);
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
                new OA\Property(
                    property: 'files',
                    type: 'array',
                    items: new OA\Items(type: 'integer'),
                    description: 'Array of file IDs to attach'
                ),
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

        // Validate file IDs if provided
        $fileIds = [];
        if (!empty($data['files']) && is_array($data['files'])) {
            foreach ($data['files'] as $fileId) {
                $fileId = (int) $fileId;
                if ($fileId <= 0) {
                    continue;
                }

                $file = $this->fileRepository->find($fileId);
                if (!$file) {
                    return $this->json(['error' => "File not found: {$fileId}"], Response::HTTP_BAD_REQUEST);
                }

                // Verify file belongs to this user
                if ($file->getUserId() !== $user->getId()) {
                    return $this->json(['error' => 'Access denied to file'], Response::HTTP_FORBIDDEN);
                }

                $fileIds[] = $fileId;
            }
        }

        try {
            $message = $this->takeoverService->sendHumanMessage(
                $widgetId,
                $sessionId,
                $data['text'],
                $user,
                $fileIds
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

    /**
     * Get file type category from MIME type.
     */
    private function getFileTypeFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if ('application/pdf' === $mime) {
            return 'pdf';
        }
        if (str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') || 'text/csv' === $mime) {
            return 'spreadsheet';
        }
        if (str_contains($mime, 'document') || str_contains($mime, 'word')) {
            return 'document';
        }
        if (str_starts_with($mime, 'text/')) {
            return 'text';
        }

        return 'other';
    }
}
