<?php

namespace App\Controller;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\RagDocumentRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\File\FileHelper;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use App\Service\StorageQuotaService;
use App\Service\WidgetService;
use Doctrine\ORM\EntityManagerInterface;
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

#[Route('/api/v1/files', name: 'api_files_')]
#[OA\Tag(name: 'Files')]
class FileController extends AbstractController
{
    public function __construct(
        private FileStorageService $storageService,
        private FileProcessor $fileProcessor,
        private VectorizationService $vectorizationService,
        private StorageQuotaService $storageQuotaService,
        private RateLimitService $rateLimitService,
        private MessageRepository $messageRepository,
        private FileRepository $fileRepository,
        private RagDocumentRepository $ragDocumentRepository,
        private WidgetSessionRepository $widgetSessionRepository,
        private WidgetService $widgetService,
        private VectorStorageFacade $vectorStorageFacade,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * Upload file(s) with flexible processing pipeline.
     *
     * POST /api/v1/files/upload
     *
     * Form-Data:
     * - files[]: File(s) to upload (multipart/form-data)
     * - group_key: Optional grouping keyword for vectorization (default: 'DEFAULT')
     * - process_level: Processing level ('extract' | 'vectorize' | 'full') (default: 'vectorize')
     *   - extract: File storage + text extraction only
     *   - vectorize: extract + vector embeddings (default)
     *   - full: vectorize + AI processing (future: summarization, analysis)
     *
     * Response:
     * {
     *   "success": true,
     *   "files": [
     *     {
     *       "id": 123,
     *       "filename": "document.pdf",
     *       "size": 1024000,
     *       "extracted_text_length": 5000,
     *       "chunks_created": 12,
     *       "processing_time_ms": 1500
     *     }
     *   ],
     *   "errors": []
     * }
     */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function uploadFiles(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $startTime = microtime(true);

        // Get parameters
        $groupKey = $request->request->get('group_key', 'DEFAULT');
        $processLevel = 'vectorize'; // Always vectorize for optimal RAG performance

        // Legacy support: Accept process_level parameter but ignore it
        if ($request->request->has('process_level')) {
            $this->logger->debug('FileController: process_level parameter ignored (always vectorize)', [
                'requested_level' => $request->request->get('process_level'),
            ]);
        }

        // Get uploaded files
        $uploadedFiles = $request->files->get('files', []);
        if (empty($uploadedFiles)) {
            return $this->json([
                'error' => 'No files uploaded. Use form-data with files[] field',
            ], Response::HTTP_BAD_REQUEST);
        }

        $results = [];
        $errors = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $fileStartTime = microtime(true);

            try {
                $result = $this->processUploadedFile(
                    $uploadedFile,
                    $user,
                    $groupKey,
                    $processLevel
                );

                if ($result['success']) {
                    $result['processing_time_ms'] = (int) ((microtime(true) - $fileStartTime) * 1000);
                    $results[] = $result;
                } else {
                    $errors[] = [
                        'filename' => $uploadedFile->getClientOriginalName(),
                        'error' => $result['error'],
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->error('FileController: File upload failed', [
                    'filename' => $uploadedFile->getClientOriginalName(),
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'filename' => $uploadedFile->getClientOriginalName(),
                    'error' => 'Upload failed: '.$e->getMessage(),
                ];
            }
        }

        $totalTime = (int) ((microtime(true) - $startTime) * 1000);

        return $this->json([
            'success' => 0 === count($errors),
            'files' => $results,
            'errors' => $errors,
            'total_time_ms' => $totalTime,
            'process_level' => $processLevel,
        ], 0 === count($errors) ? Response::HTTP_OK : Response::HTTP_PARTIAL_CONTENT);
    }

    /**
     * Process single uploaded file.
     */
    private function processUploadedFile(
        $uploadedFile,
        User $user,
        string $groupKey,
        string $processLevel,
    ): array {
        // Step 0a: Check rate limit for FILE_ANALYSIS BEFORE uploading
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'FILE_ANALYSIS');
        if (!$rateLimitCheck['allowed']) {
            return [
                'success' => false,
                'error' => "Rate limit exceeded for FILE_ANALYSIS. Used: {$rateLimitCheck['used']}/{$rateLimitCheck['limit']}",
                'rate_limit_exceeded' => true,
                'action' => 'FILE_ANALYSIS',
                'used' => $rateLimitCheck['used'],
                'limit' => $rateLimitCheck['limit'],
            ];
        }

        // Step 0b: Check storage quota BEFORE uploading
        $fileSize = $uploadedFile->getSize();
        try {
            $this->storageQuotaService->checkStorageLimit($user, $fileSize);
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'storage_exceeded' => true,
            ];
        }

        // Step 1: Store file
        $storageResult = $this->storageService->storeUploadedFile($uploadedFile, $user->getId());

        if (!$storageResult['success']) {
            return [
                'success' => false,
                'error' => $storageResult['error'],
            ];
        }

        $relativePath = $storageResult['path'];
        $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());

        // Create File entity (standalone, not attached to a message yet)
        $messageFile = new File();
        $messageFile->setUserId($user->getId());
        $messageFile->setFilePath($relativePath);
        $messageFile->setFileType($fileExtension);
        $messageFile->setFileName($uploadedFile->getClientOriginalName());
        $messageFile->setFileSize($storageResult['size']);
        $messageFile->setFileMime($storageResult['mime']);
        $messageFile->setStatus('uploaded');

        $this->em->persist($messageFile);
        $this->em->flush();

        $result = [
            'success' => true,
            'id' => $messageFile->getId(),
            'filename' => $uploadedFile->getClientOriginalName(),
            'size' => $storageResult['size'],
            'mime' => $storageResult['mime'],
            'path' => $relativePath,
            'group_key' => $groupKey,
        ];

        // Step 2: Extract text (ALWAYS done - Preprocessor)
        try {
            [$extractedText, $extractMeta] = $this->fileProcessor->extractText(
                $relativePath,
                $fileExtension,
                $user->getId()
            );

            $messageFile->setFileText($extractedText);
            $messageFile->setStatus('extracted');
            $this->em->flush();

            $result['extracted_text_length'] = strlen($extractedText);
            $result['extraction_strategy'] = $extractMeta['strategy'] ?? 'unknown';

            // Stop here if only extraction requested
            if ('extract' === $processLevel) {
                return $result;
            }
        } catch (\Throwable $e) {
            $this->logger->error('FileController: Text extraction failed', [
                'file_id' => $messageFile->getId(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Text extraction failed: '.$e->getMessage(),
            ];
        }

        // Step 3: Vectorize (if requested)
        if (in_array($processLevel, ['vectorize', 'full'], true)) {
            try {
                $vectorResult = $this->vectorizationService->vectorizeAndStore(
                    $extractedText,
                    $user->getId(),
                    $messageFile->getId(),
                    $groupKey,
                    $this->getFileTypeCode($fileExtension)
                );

                if ($vectorResult['success']) {
                    $messageFile->setStatus('vectorized');
                    $this->em->flush();

                    $result['chunks_created'] = $vectorResult['chunks_created'];
                    $result['vectorized'] = true;
                } else {
                    $this->logger->warning('FileController: Vectorization failed', [
                        'file_id' => $messageFile->getId(),
                        'error' => $vectorResult['error'],
                    ]);

                    $result['vectorized'] = false;
                    $result['vectorization_error'] = $vectorResult['error'];
                }
            } catch (\Throwable $e) {
                $this->logger->error('FileController: Vectorization exception', [
                    'file_id' => $messageFile->getId(),
                    'error' => $e->getMessage(),
                ]);

                $result['vectorized'] = false;
                $result['vectorization_error'] = $e->getMessage();
            }
        }

        // Step 4: Full processing (future: AI analysis, summarization)
        if ('full' === $processLevel) {
            // TODO: Implement AI processing
            // - Generate summary
            // - Extract entities
            // - Create structured metadata
            $result['ai_processed'] = false;
            $result['ai_processing_note'] = 'AI processing not yet implemented';
        }

        // Record FILE_ANALYSIS usage for statistics
        $this->rateLimitService->recordUsage($user, 'FILE_ANALYSIS', [
            'file_id' => $messageFile->getId(),
            'filename' => $uploadedFile->getClientOriginalName(),
            'source' => 'WEB',
        ]);

        return $result;
    }

    /**
     * Get file type code for BRAG table.
     */
    private function getFileTypeCode(string $extension): int
    {
        return match (strtolower($extension)) {
            'txt', 'md', 'csv' => 0, // Plain text
            'jpg', 'jpeg', 'png', 'gif', 'webp' => 1, // Image
            'mp3', 'mp4', 'wav', 'ogg', 'm4a', 'webm' => 2, // Audio/Video
            'pdf' => 3, // PDF
            'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt' => 4, // Office
            default => 5, // Other
        };
    }

    /**
     * Download a file.
     *
     * GET /api/v1/files/{id}/download
     */
    #[Route('/{id}/download', name: 'download', methods: ['GET'])]
    public function downloadFile(
        int $id,
        #[CurrentUser] ?User $user,
    ): Response {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get the File entity
        $messageFile = $this->fileRepository->find($id);

        if (!$messageFile) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Security check: Only owner can download
        // Also allow widget files (userId=0) if current user is the widget owner
        $isOwner = $messageFile->getUserId() === $user->getId();
        $isWidgetFileOwner = false;

        if (!$isOwner && 0 === $messageFile->getUserId()) {
            // Check if this is a widget file and user is the widget owner
            $sessionId = $messageFile->getUserSessionId();
            if ($sessionId) {
                $widgetSession = $this->widgetSessionRepository->find($sessionId);
                if ($widgetSession) {
                    $widget = $this->widgetService->getWidgetById($widgetSession->getWidgetId());
                    if ($widget && $widget->getOwnerId() === $user->getId()) {
                        $isWidgetFileOwner = true;
                    }
                }
            }
        }

        if (!$isOwner && !$isWidgetFileOwner) {
            $this->logger->warning('FileController: Unauthorized download attempt', [
                'file_id' => $id,
                'user_id' => $user->getId(),
                'owner_id' => $messageFile->getUserId(),
            ]);

            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $filePath = $messageFile->getFilePath();
        if (!$filePath) {
            return $this->json(['error' => 'File path not found'], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->uploadDir.'/'.$filePath;

        // Use NFS-aware file check for multi-server deployments
        if (!FileHelper::fileExistsNfs($absolutePath)) {
            $this->logger->error('FileController: File not found on disk', [
                'file_id' => $id,
                'path' => $absolutePath,
            ]);

            return $this->json(['error' => 'File not found on disk'], Response::HTTP_NOT_FOUND);
        }

        // Return file as download
        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $messageFile->getFileName()
        );

        return $response;
    }

    /**
     * Get file content/text.
     *
     * GET /api/v1/files/{id}/content
     */
    #[Route('/{id}/content', name: 'content', methods: ['GET'])]
    public function getFileContent(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get the File entity
        $messageFile = $this->fileRepository->find($id);

        if (!$messageFile) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Security check: Only owner can view
        if ($messageFile->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'id' => $messageFile->getId(),
            'filename' => $messageFile->getFileName(),
            'file_path' => $messageFile->getFilePath(),
            'file_type' => $messageFile->getFileType(),
            'file_size' => $messageFile->getFileSize(),
            'mime' => $messageFile->getFileMime(),
            'extracted_text' => $messageFile->getFileText() ?? '',
            'status' => $messageFile->getStatus(),
            'message_id' => null,
            'is_attached' => null !== null,
            'uploaded_at' => $messageFile->getCreatedAt(),
            'uploaded_date' => date('Y-m-d H:i:s', $messageFile->getCreatedAt()),
        ]);
    }

    /**
     * List user's uploaded files.
     *
     * GET /api/v1/files
     * Query params:
     * - group_key: Filter by group (optional)
     * - page: Page number (default: 1)
     * - limit: Items per page (default: 50, max: 100)
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function listFiles(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $groupKey = $request->query->get('group_key');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;

        // Build query for MessageFiles
        $qb = $this->fileRepository->createQueryBuilder('mf')
            ->where('mf.userId = :userId')
            ->setParameter('userId', $user->getId());

        // If group_key filter is provided, join with BRAG table
        if ($groupKey) {
            $qb->innerJoin(
                \App\Entity\RagDocument::class,
                'r',
                'WITH',
                'r.messageId = mf.id AND r.userId = :userId AND r.groupKey = :groupKey'
            )
            ->setParameter('groupKey', $groupKey);
        }

        $qb->orderBy('mf.createdAt', 'DESC');

        // Get total count
        $totalCount = (clone $qb)->select('COUNT(DISTINCT mf.id)')->getQuery()->getSingleScalarResult();

        // Get paginated results
        $messageFiles = $qb->select('DISTINCT mf')
                          ->setFirstResult($offset)
                          ->setMaxResults($limit)
                          ->getQuery()
                          ->getResult();

        $files = array_map(fn (File $mf) => [
            'id' => $mf->getId(),
            'filename' => $mf->getFileName(),
            'path' => $mf->getFilePath(),
            'file_type' => $mf->getFileType(),
            'file_size' => $mf->getFileSize(),
            'mime' => $mf->getFileMime(),
            'status' => $mf->getStatus(),
            'text_preview' => mb_substr($mf->getFileText() ?? '', 0, 200),
            'uploaded_at' => $mf->getCreatedAt(),
            'uploaded_date' => date('Y-m-d H:i:s', $mf->getCreatedAt()),
            'message_id' => null, // null if standalone
            'is_attached' => null !== null,
        ], $messageFiles);

        return $this->json([
            'success' => true,
            'files' => $files,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'pages' => (int) ceil($totalCount / $limit),
            ],
        ]);
    }

    /**
     * Get all file groups (unique group keys with file counts).
     *
     * GET /api/v1/files/groups
     */
    #[Route('/groups', name: 'groups', methods: ['GET'])]
    public function getFileGroups(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $groups = $this->vectorStorageFacade->getGroupKeys($user->getId());

            $this->logger->debug('FileController: Retrieved file groups', [
                'user_id' => $user->getId(),
                'groups_count' => count($groups),
                'groups' => $groups,
            ]);

            // Transform to expected format
            // Note: Facade returns simple array of strings, but frontend might expect objects with counts?
            // The previous implementation returned `['groupKey' => '...', 'count' => 5]`
            // The facade `getGroupKeys` currently returns `string[]`.
            // Let's check `MariaDBVectorStorage::getGroupKeys`. It calls `ragRepository->findDistinctGroupKeysByUser`.
            // `findDistinctGroupKeysByUser` likely returns array of arrays with counts.
            // Let's check `RagDocumentRepository`.
            // If Facade returns strings, we lose counts.
            // Let's check `VectorStorageInterface`. `getGroupKeys` returns `string[]`.
            // This is a regression if frontend uses counts.
            // Let's check `MariaDBVectorStorage` implementation again.
            // `return $this->ragRepository->findDistinctGroupKeysByUser($userId);`
            // If that returns `[['groupKey' => 'A', 'count' => 1]]`, then interface return type `array` is loose but PHPDoc says `string[]`.
            // I should update `VectorStorageInterface` and implementations to return `array` (of group info) or `string[]` and fetch stats separately.
            // `getStats` returns `chunksByGroup`. I can use that!

            // Better approach: Use getStats to get groups and counts.
            $stats = $this->vectorStorageFacade->getStats($user->getId());
            $chunksByGroup = $stats->chunksByGroup;

            $groupsData = [];
            foreach ($chunksByGroup as $groupKey => $count) {
                $groupsData[] = [
                    'name' => $groupKey,
                    'count' => $count,
                ];
            }

            return $this->json([
                'success' => true,
                'groups' => $groupsData,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('FileController: Failed to get file groups', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to load file groups',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete file.
     *
     * DELETE /api/v1/files/{id}
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function deleteFile(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $messageFile = $this->fileRepository->find($id);

        if (!$messageFile || $messageFile->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $fileId = $messageFile->getId();

        // Delete RAG embeddings first (before deleting the file entity) via facade
        $deletedChunks = $this->vectorStorageFacade->deleteByFile($user->getId(), $fileId);

        $this->logger->info('FileController: Deleted RAG embeddings for file', [
            'file_id' => $fileId,
            'user_id' => $user->getId(),
            'chunks_deleted' => $deletedChunks,
            'provider' => $this->vectorStorageFacade->getProviderName(),
        ]);

        // Delete physical file
        if ($messageFile->getFilePath()) {
            $this->storageService->deleteFile($messageFile->getFilePath());
        }

        // Delete File entity
        $this->em->remove($messageFile);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'File deleted successfully',
        ]);
    }

    /**
     * Make file public and generate share link.
     *
     * POST /api/v1/files/{id}/share
     */
    #[Route('/{id}/share', name: 'share', methods: ['POST'])]
    public function makePublic(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findOneBy([
            'id' => $id,
            'userId' => $user->getId(),
            'file' => 1,
        ]);

        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Parse expiry from request (optional)
        $data = json_decode($request->getContent(), true);
        $expiryDays = $data['expiry_days'] ?? 7; // Default: 7 days

        // Make public
        $message->setPublic(true);
        $token = $message->generateShareToken();

        if ($expiryDays > 0) {
            $expiresAt = time() + ($expiryDays * 24 * 60 * 60);
            $message->setShareExpires($expiresAt);
        }

        $this->em->flush();

        $this->logger->info('File shared publicly', [
            'file_id' => $id,
            'user_id' => $user->getId(),
            'expires_at' => $message->getShareExpires(),
        ]);

        return $this->json([
            'success' => true,
            'share_url' => '/up/'.$message->getFilePath(),
            'share_token' => $token,
            'expires_at' => $message->getShareExpires(),
            'is_public' => true,
        ]);
    }

    /**
     * Revoke public access.
     *
     * DELETE /api/v1/files/{id}/share
     */
    #[Route('/{id}/share', name: 'unshare', methods: ['DELETE'])]
    public function revokeShare(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findOneBy([
            'id' => $id,
            'userId' => $user->getId(),
            'file' => 1,
        ]);

        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $message->revokeShare();
        $this->em->flush();

        $this->logger->info('File share revoked', [
            'file_id' => $id,
            'user_id' => $user->getId(),
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Share revoked',
            'is_public' => false,
        ]);
    }

    /**
     * Get share info.
     *
     * GET /api/v1/files/{id}/share
     */
    #[Route('/{id}/share', name: 'share_info', methods: ['GET'])]
    public function getShareInfo(
        int $id,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findOneBy([
            'id' => $id,
            'userId' => $user->getId(),
            'file' => 1,
        ]);

        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'is_public' => $message->isPublic(),
            'share_url' => $message->isPublic() ? '/up/'.$message->getFilePath() : null,
            'share_token' => $message->getShareToken(),
            'expires_at' => $message->getShareExpires(),
            'is_expired' => $message->isShareExpired(),
        ]);
    }

    /**
     * Get storage quota statistics for current user.
     *
     * GET /api/v1/files/storage-stats
     */
    #[Route('/storage-stats', name: 'storage_stats', methods: ['GET'])]
    public function getStorageStats(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $stats = $this->storageQuotaService->getStorageStats($user);

        return $this->json([
            'success' => true,
            'user_level' => $user->getRateLimitLevel(),
            'storage' => $stats,
        ]);
    }

    /**
     * Update groupKey for an existing file.
     *
     * PUT /api/v1/files/{id}/group-key
     * Body: { "groupKey": "new-group-name" }
     */
    #[Route('/{id}/group-key', name: 'update_group_key', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/files/{id}/group-key',
        summary: 'Update the groupKey for a file',
        description: 'Updates the groupKey in all RAG documents associated with this file',
        tags: ['Files'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'groupKey', type: 'string', example: 'customer-support'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'GroupKey updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'chunksUpdated', type: 'integer', example: 15),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function updateGroupKey(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $messageFile = $this->fileRepository->find($id);

        if (!$messageFile) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Security check: Only owner can update
        if ($messageFile->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $newGroupKey = $data['groupKey'] ?? null;

        if (!$newGroupKey) {
            return $this->json(['error' => 'groupKey is required'], Response::HTTP_BAD_REQUEST);
        }

        // Update all RAG documents for this file via facade
        $chunksUpdated = $this->vectorStorageFacade->updateGroupKey(
            $user->getId(),
            $messageFile->getId(),
            $newGroupKey
        );

        $this->logger->info('FileController: GroupKey updated', [
            'file_id' => $id,
            'user_id' => $user->getId(),
            'new_group_key' => $newGroupKey,
            'chunks_updated' => $chunksUpdated,
            'provider' => $this->vectorStorageFacade->getProviderName(),
        ]);

        return $this->json([
            'success' => true,
            'chunksUpdated' => $chunksUpdated,
            'message' => 'GroupKey updated successfully',
        ]);
    }

    /**
     * Re-vectorize a file (extract text + create embeddings).
     *
     * POST /api/v1/files/{id}/re-vectorize
     * Body: { "groupKey": "optional-group-name" }
     */
    #[Route('/{id}/re-vectorize', name: 're_vectorize', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/re-vectorize',
        summary: 'Re-vectorize a file',
        description: 'Extracts text from file and creates vector embeddings. Useful for files uploaded without vectorization.',
        tags: ['Files'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'groupKey', type: 'string', example: 'customer-support'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'File re-vectorized successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'chunksCreated', type: 'integer', example: 15),
                        new OA\Property(property: 'extractedTextLength', type: 'integer', example: 5000),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function reVectorize(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $messageFile = $this->fileRepository->find($id);

        if (!$messageFile) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Security check: Only owner can re-vectorize
        if ($messageFile->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $groupKey = $data['groupKey'] ?? 'DEFAULT';

        // Step 1: Delete existing RAG documents for this file via facade
        $this->vectorStorageFacade->deleteByFile($user->getId(), $messageFile->getId());

        // Step 2: Extract text from file (if not already extracted)
        $relativePath = $messageFile->getFilePath();
        $fileExtension = strtolower($messageFile->getFileType() ?: pathinfo($relativePath, PATHINFO_EXTENSION) ?? '');
        $extractedText = $messageFile->getFileText();

        if ('' === trim($extractedText)) {
            $absolutePath = rtrim($this->uploadDir, '/').'/'.ltrim($relativePath, '/');

            // Use NFS-aware file check for multi-server deployments
            if (!FileHelper::fileExistsNfs($absolutePath)) {
                return $this->json([
                    'success' => false,
                    'error' => 'File not found on disk',
                ], Response::HTTP_NOT_FOUND);
            }

            try {
                [$extractedText, $extractMeta] = $this->fileProcessor->extractText(
                    $relativePath,
                    $fileExtension,
                    $user->getId()
                );

                $messageFile->setFileText($extractedText);
                $messageFile->setStatus('extracted');
                $this->em->flush();

                $this->logger->info('FileController: Re-vectorization text extraction completed', [
                    'file_id' => $id,
                    'strategy' => $extractMeta['strategy'] ?? 'unknown',
                    'bytes' => strlen($extractedText),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('FileController: Re-vectorization text extraction failed', [
                    'file_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                return $this->json([
                    'success' => false,
                    'error' => 'Text extraction failed: '.$e->getMessage(),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        if ('' === trim($extractedText)) {
            return $this->json([
                'success' => false,
                'error' => 'Text extraction produced no content',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Step 3: Vectorize extracted text (NOT the binary file!)
        try {
            $vectorResult = $this->vectorizationService->vectorizeAndStore(
                $extractedText,  // ✅ Using extracted TEXT, not binary file!
                $user->getId(),
                $messageFile->getId(),
                $groupKey,
                $this->getFileTypeCode($fileExtension)
            );

            if ($vectorResult['success']) {
                $messageFile->setStatus('vectorized');
                $this->em->flush();

                $this->logger->info('FileController: File re-vectorized successfully', [
                    'file_id' => $id,
                    'user_id' => $user->getId(),
                    'group_key' => $groupKey,
                    'chunks_created' => $vectorResult['chunks_created'],
                    'provider' => $vectorResult['provider'] ?? 'unknown',
                ]);

                return $this->json([
                    'success' => true,
                    'chunksCreated' => $vectorResult['chunks_created'],
                    'extractedTextLength' => strlen($extractedText),
                    'groupKey' => $groupKey,
                    'message' => 'File re-vectorized successfully',
                    'provider' => $vectorResult['provider'] ?? 'unknown',
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'error' => 'Vectorization failed: '.($vectorResult['error'] ?? 'Unknown error'),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } catch (\Throwable $e) {
            $this->logger->error('FileController: Re-vectorization failed', [
                'file_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Vectorization failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get groupKey for a file.
     *
     * GET /api/v1/files/{id}/group-key
     */
    #[Route('/{id}/group-key', name: 'get_group_key', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files/{id}/group-key',
        summary: 'Get the groupKey for a file',
        description: 'Returns the groupKey from RAG documents, or null if not vectorized',
        tags: ['Files'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'GroupKey retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'groupKey', type: 'string', example: 'customer-support', nullable: true),
                        new OA\Property(property: 'isVectorized', type: 'boolean'),
                        new OA\Property(property: 'chunks', type: 'integer', example: 15),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function getGroupKey(
        int $id,
        #[CurrentUser] ?User $user,
        RagDocumentRepository $ragRepository,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $messageFile = $this->fileRepository->find($id);

        if (!$messageFile) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Security check: Only owner can view
        if ($messageFile->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get RAG documents for this file
        $ragDocs = $ragRepository->findBy([
            'userId' => $user->getId(),
            'messageId' => $messageFile->getId(),
        ]);

        $groupKey = null;
        $chunks = count($ragDocs);

        if ($chunks > 0) {
            // Get groupKey from first chunk (all should have the same)
            $groupKey = $ragDocs[0]->getGroupKey();
        }

        return $this->json([
            'success' => true,
            'groupKey' => $groupKey,
            'isVectorized' => $chunks > 0,
            'chunks' => $chunks,
            'status' => $messageFile->getStatus(),
        ]);
    }
}
