<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\File\FileHelper;
use App\Service\File\FileStorageService;
use App\Service\File\FileUploadService;
use App\Service\RAG\VectorStorage\VectorMigrationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\StorageQuotaService;
use App\Service\WidgetService;
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
        private FileUploadService $uploadService,
        private FileStorageService $storageService,
        private StorageQuotaService $storageQuotaService,
        private FileRepository $fileRepository,
        private MessageRepository $messageRepository,
        private WidgetSessionRepository $widgetSessionRepository,
        private WidgetService $widgetService,
        private VectorStorageFacade $vectorStorageFacade,
        private VectorMigrationService $migrationService,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/upload',
        summary: 'Upload file(s) with flexible processing pipeline',
        tags: ['Files'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'files[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
                        new OA\Property(property: 'group_key', type: 'string', example: 'customer-support'),
                        new OA\Property(property: 'process_level', type: 'string', enum: ['extract', 'vectorize', 'full'], example: 'vectorize'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'All files uploaded successfully'),
            new OA\Response(response: 206, description: 'Partial success — some files failed'),
            new OA\Response(response: 400, description: 'No files provided'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function uploadFiles(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $groupKey = $request->request->get('group_key') ?: null;
        $processLevel = $request->request->get('process_level', 'vectorize');
        if (!in_array($processLevel, ['extract', 'vectorize', 'full'], true)) {
            $processLevel = 'vectorize';
        }

        $uploadedFiles = $request->files->get('files', []);
        if (empty($uploadedFiles)) {
            return $this->json(['error' => 'No files uploaded. Use form-data with files[] field'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->uploadService->uploadBatch($uploadedFiles, $user, $groupKey, $processLevel);

        return $this->json($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_PARTIAL_CONTENT);
    }

    #[Route('/{id}/download', name: 'download', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files/{id}/download',
        summary: 'Download a file',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'File content'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function downloadFile(int $id, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isFileAccessibleByUser($file, $user)) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $absolutePath = $this->uploadDir.'/'.$file->getFilePath();
        if (!FileHelper::fileExistsNfs($absolutePath)) {
            return $this->json(['error' => 'File not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getFileName());

        return $response;
    }

    #[Route('/{id}/content', name: 'content', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files/{id}/content',
        summary: 'Get file metadata and extracted text',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'File content and metadata'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function getFileContent(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => $file->getId(),
            'filename' => $file->getFileName(),
            'file_path' => $file->getFilePath(),
            'file_type' => $file->getFileType(),
            'file_size' => $file->getFileSize(),
            'mime' => $file->getFileMime(),
            'extracted_text' => $file->getFileText() ?? '',
            'status' => $file->getStatus(),
            'uploaded_at' => $file->getCreatedAt(),
            'uploaded_date' => date('Y-m-d H:i:s', $file->getCreatedAt()),
        ]);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files',
        summary: 'List uploaded files with pagination',
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'group_key', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated file list'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function listFiles(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $groupKey = $request->query->get('group_key');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;

        $vectorFileIds = [];
        if ($groupKey) {
            try {
                $vectorFileIds = $this->vectorStorageFacade->getFileIdsByGroupKey($user->getId(), $groupKey);
            } catch (\Throwable $e) {
                $this->logger->warning('FileController: Vector store group lookup failed', ['error' => $e->getMessage()]);
            }
        }

        $result = $this->fileRepository->findByUserPaginated($user->getId(), $groupKey, $offset, $limit, $vectorFileIds);
        $messageFiles = $result['files'];

        $vectorChunkMap = $this->resolveVectorGroupKeys($user->getId(), $messageFiles);

        $files = array_map(fn ($mf) => [
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
            'group_key' => $mf->getGroupKey() ?: ($vectorChunkMap[$mf->getId()] ?? null),
        ], $messageFiles);

        return $this->json([
            'success' => true,
            'files' => $files,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $result['total'],
                'pages' => (int) ceil($result['total'] / $limit),
            ],
        ]);
    }

    #[Route('/groups', name: 'groups', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files/groups',
        summary: 'Get file groups with counts',
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'List of file groups'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function getFileGroups(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $merged = $this->fileRepository->getGroupCountsByUser($user->getId());

            try {
                $vectorGroups = [];
                $allChunks = $this->vectorStorageFacade->getFilesWithChunks($user->getId());
                foreach ($allChunks as $info) {
                    $gk = $info['groupKey'] ?? '';
                    if ('' === $gk || 'DEFAULT' === $gk) {
                        continue;
                    }
                    $vectorGroups[$gk] = ($vectorGroups[$gk] ?? 0) + 1;
                }
                foreach ($vectorGroups as $gk => $count) {
                    $merged[$gk] = max($merged[$gk] ?? 0, $count);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FileController: Vector store group lookup failed', ['error' => $e->getMessage()]);
            }

            ksort($merged);
            $groupsData = array_map(fn ($name, $count) => ['name' => $name, 'count' => $count], array_keys($merged), $merged);

            return $this->json(['success' => true, 'groups' => $groupsData]);
        } catch (\Exception $e) {
            $this->logger->error('FileController: Failed to get file groups', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to load file groups'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/files/{id}',
        summary: 'Delete a file and its vector embeddings',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'File deleted'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function deleteFile(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $this->vectorStorageFacade->deleteByFile($user->getId(), $file->getId());

        if ($file->getFilePath()) {
            $this->storageService->deleteFile($file->getFilePath());
        }

        $this->fileRepository->delete($file);

        return $this->json(['success' => true, 'message' => 'File deleted successfully']);
    }

    #[Route('/{id}/share', name: 'share', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/share',
        summary: 'Make file public and generate share link',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'File shared'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function makePublic(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findUserFileMessage($id, $user->getId());
        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $expiryDays = $data['expiry_days'] ?? 7;

        $message->setPublic(true);
        $token = $message->generateShareToken();
        if ($expiryDays > 0) {
            $message->setShareExpires(time() + ($expiryDays * 24 * 60 * 60));
        }
        $this->messageRepository->flush();

        return $this->json([
            'success' => true,
            'share_url' => '/up/'.$message->getFilePath(),
            'share_token' => $token,
            'expires_at' => $message->getShareExpires(),
            'is_public' => true,
        ]);
    }

    #[Route('/{id}/share', name: 'unshare', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/files/{id}/share',
        summary: 'Revoke public access to a file',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Share revoked'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function revokeShare(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findUserFileMessage($id, $user->getId());
        if (!$message) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $message->revokeShare();
        $this->messageRepository->flush();

        return $this->json(['success' => true, 'message' => 'Share revoked', 'is_public' => false]);
    }

    #[Route('/{id}/share', name: 'share_info', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files/{id}/share',
        summary: 'Get share info for a file',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Share information'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function getShareInfo(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->findUserFileMessage($id, $user->getId());
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

    #[Route('/storage-stats', name: 'storage_stats', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files/storage-stats',
        summary: 'Get storage quota statistics',
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'Storage statistics'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function getStorageStats(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'user_level' => $user->getRateLimitLevel(),
            'storage' => $this->storageQuotaService->getStorageStats($user),
        ]);
    }

    #[Route('/{id}/group-key', name: 'get_group_key', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files/{id}/group-key',
        summary: 'Get group key and vectorization info',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Group key and vectorization status'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function getFileGroupKey(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $chunkInfo = $this->vectorStorageFacade->getFileChunkInfo($user->getId(), $id);
            $migrationStatus = $this->migrationService->getFileMigrationStatus($user->getId(), $id);

            return $this->json([
                'success' => true,
                'groupKey' => $chunkInfo['groupKey'],
                'isVectorized' => $chunkInfo['chunks'] > 0,
                'chunks' => $chunkInfo['chunks'],
                'status' => $file->getStatus(),
                'needsMigration' => $migrationStatus['needsMigration'],
                'mariadbChunks' => $migrationStatus['mariadbChunks'],
                'qdrantChunks' => $migrationStatus['qdrantChunks'],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => true,
                'groupKey' => null,
                'isVectorized' => 'vectorized' === $file->getStatus(),
                'chunks' => 0,
                'status' => $file->getStatus(),
                'needsMigration' => false,
                'mariadbChunks' => 0,
                'qdrantChunks' => 0,
            ]);
        }
    }

    #[Route('/{id}/migrate', name: 'migrate_vectors', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/migrate',
        summary: 'Migrate file vectors from MariaDB to Qdrant',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Migration result'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function migrateFileVectors(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->migrationService->migrateFile($user->getId(), $id);

            return $this->json(['success' => true, 'migrated' => $result['migrated'], 'errors' => $result['errors']]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Migration failed: '.$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/group-key', name: 'update_group_key', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/files/{id}/group-key',
        summary: 'Update the groupKey for a file',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'groupKey', type: 'string', example: 'customer-support')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'GroupKey updated'),
            new OA\Response(response: 400, description: 'Missing groupKey'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function updateGroupKey(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }
        if ($file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();
        $newGroupKey = $data['groupKey'] ?? null;
        if (!$newGroupKey) {
            return $this->json(['error' => 'groupKey is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->fileRepository->updateGroupKey($file, $newGroupKey);

        $chunksUpdated = $this->vectorStorageFacade->updateGroupKey($user->getId(), $file->getId(), $newGroupKey);

        return $this->json(['success' => true, 'chunksUpdated' => $chunksUpdated]);
    }

    #[Route('/{id}/re-vectorize', name: 're_vectorize', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/re-vectorize',
        summary: 'Re-vectorize a file',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'groupKey', type: 'string', example: 'customer-support')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'File re-vectorized'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found or missing on disk'),
            new OA\Response(response: 422, description: 'Text extraction produced no content'),
            new OA\Response(response: 500, description: 'Vectorization or extraction failed'),
        ]
    )]
    public function reVectorize(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }
        if ($file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();
        $groupKey = $data['groupKey'] ?? $file->getGroupKey() ?? '';

        $result = $this->uploadService->reVectorize($file, $user, $groupKey);

        if ($result['success']) {
            return $this->json($result);
        }

        $statusCode = match ($result['errorType'] ?? null) {
            'not_found' => Response::HTTP_NOT_FOUND,
            'empty_content' => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };

        return $this->json($result, $statusCode);
    }

    private function isFileAccessibleByUser(File $file, User $user): bool
    {
        if ($file->getUserId() === $user->getId()) {
            return true;
        }

        if (0 === $file->getUserId()) {
            $sessionId = $file->getUserSessionId();
            if ($sessionId) {
                $widgetSession = $this->widgetSessionRepository->find($sessionId);
                if ($widgetSession) {
                    $widget = $this->widgetService->getWidgetById($widgetSession->getWidgetId());
                    if ($widget && $widget->getOwnerId() === $user->getId()) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Resolve group keys from vector store for legacy files missing DB column.
     *
     * @param File[] $files
     *
     * @return array<int, string>
     */
    private function resolveVectorGroupKeys(int $userId, array $files): array
    {
        $legacyFileIds = array_filter(
            array_map(fn ($mf) => null === $mf->getGroupKey() ? $mf->getId() : null, $files)
        );

        if (empty($legacyFileIds)) {
            return [];
        }

        $vectorChunkMap = [];
        try {
            $allChunks = $this->vectorStorageFacade->getFilesWithChunks($userId);
            foreach ($legacyFileIds as $fid) {
                if (isset($allChunks[$fid]) && !empty($allChunks[$fid]['groupKey'])) {
                    $vectorChunkMap[$fid] = $allChunks[$fid]['groupKey'];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('FileController: Vector store chunk lookup failed', ['error' => $e->getMessage()]);
        }

        if (!empty($vectorChunkMap)) {
            try {
                $this->fileRepository->backfillGroupKeys($files, $vectorChunkMap);
            } catch (\Throwable $e) {
                $this->logger->warning('FileController: Lazy backfill failed', ['error' => $e->getMessage()]);
            }
        }

        return $vectorChunkMap;
    }
}
