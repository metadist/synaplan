<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\FileHelper;
use App\Service\File\FileListService;
use App\Service\File\FileStorageService;
use App\Service\File\FileUploadService;
use App\Service\File\UploadOptions;
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
        private FileListService $fileListService,
        private FileStorageService $storageService,
        private StorageQuotaService $storageQuotaService,
        private FileRepository $fileRepository,
        private MessageRepository $messageRepository,
        private WidgetSessionRepository $widgetSessionRepository,
        private WidgetService $widgetService,
        private VectorStorageFacade $vectorStorageFacade,
        private VectorMigrationService $migrationService,
        private DocumentGeneratorService $documentGenerator,
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
                        new OA\Property(property: 'process_level', type: 'string', enum: ['store', 'extract', 'vectorize', 'full'], example: 'vectorize'),
                        new OA\Property(property: 'source', type: 'string', enum: ['web_upload', 'chat_attachment', 'outlook', 'nextcloud', 'opencloud', 'whatsapp', 'widget', 'api', 'generated'], example: 'nextcloud', description: 'Origin of the file (provenance). Defaults to web_upload. Integrations (Nextcloud/OpenCloud/Outlook) should set this so the file is labelled by source.'),
                        new OA\Property(property: 'original_name', type: 'string', example: '/Shared/Q3 Report.pdf', description: 'The file name at the source, preserved even when the stored name is normalised. Falls back to the uploaded filename.'),
                        new OA\Property(property: 'source_id', type: 'string', example: '12345', description: 'Stable external id of the file at its source (e.g. the Nextcloud file id). Enables overwrite-in-place and bulk stale checks. Sent with a single file per request.'),
                        new OA\Property(property: 'source_etag', type: 'string', example: 'a1b2c3', description: 'External version/etag captured at ingest; a differing value reported later marks the knowledge copy stale.'),
                        new OA\Property(property: 'overwrite', type: 'boolean', example: true, description: 'Replace the existing file matching (source, source_id) — or (group_key, original_name) — in place instead of creating a duplicate. Keeps the file id stable.'),
                        new OA\Property(property: 'retain_source', type: 'boolean', example: true, description: 'When false, the stored binary is discarded after successful vectorization; the row, extracted text and vectors are kept. Defaults to true.'),
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
        if (!in_array($processLevel, ['store', 'extract', 'vectorize', 'full'], true)) {
            $processLevel = 'vectorize';
        }

        // Provenance (03_file-management.md §3.1): integrations declare where the
        // file came from + its original name. Web uploads omit these → web_upload.
        $source = (string) $request->request->get('source', 'web_upload');
        if (!in_array($source, File::SOURCES, true)) {
            $source = 'web_upload';
        }
        $originalName = $request->request->get('original_name');
        $originalName = is_string($originalName) && '' !== trim($originalName) ? trim($originalName) : null;

        // Knowledge-file lifecycle (CORE-4): external identity + sync knobs an
        // integration passes so the same source file overwrites in place instead
        // of duplicating, and the binary can be dropped after embedding.
        $sourceId = $request->request->get('source_id');
        $sourceId = is_string($sourceId) && '' !== trim($sourceId) ? trim($sourceId) : null;
        $sourceEtag = $request->request->get('source_etag');
        $sourceEtag = is_string($sourceEtag) && '' !== trim($sourceEtag) ? trim($sourceEtag) : null;
        $overwrite = $request->request->getBoolean('overwrite');
        $retainSource = !$request->request->has('retain_source') || $request->request->getBoolean('retain_source');

        $options = new UploadOptions($source, $originalName, $sourceId, $sourceEtag, $overwrite, $retainSource);

        $uploadedFiles = $request->files->get('files', []);

        // Silent-truncation guard. PHP's `max_file_uploads` (default 20) drops
        // any files past the limit from `$_FILES` without raising an error,
        // and there is no signal in $_FILES that this happened. The
        // file-manager / RAG UI lets users select hundreds of files, so the
        // 21st…Nth file would simply vanish — exactly the silent failure the
        // customer hit ("selected 50, only 20 uploaded, no error"). We now
        // require the client to send the intended count as `file_count` and
        // return 413 the moment PHP saw fewer files than declared, with the
        // active server ceiling so the UI can chunk and retry.
        $declaredCount = $request->request->getInt('file_count', 0);
        $actualCount = is_array($uploadedFiles) ? count($uploadedFiles) : ($uploadedFiles ? 1 : 0);
        $maxFileUploads = self::getMaxFileUploads();

        if ($declaredCount > 0 && $declaredCount > $actualCount) {
            return $this->json([
                'error' => sprintf(
                    'Server accepted %d of %d files in this request (PHP max_file_uploads = %d). Retry with smaller batches.',
                    $actualCount,
                    $declaredCount,
                    $maxFileUploads,
                ),
                'reason' => 'max_file_uploads_exceeded',
                'received' => $actualCount,
                'declared' => $declaredCount,
                'max_files_per_request' => $maxFileUploads,
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if (empty($uploadedFiles)) {
            return $this->json(['error' => 'No files uploaded. Use form-data with files[] field'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->uploadService->uploadBatch($uploadedFiles, $user, $groupKey, $processLevel, $options);

        return $this->json($result, $result['success'] ? Response::HTTP_OK : Response::HTTP_PARTIAL_CONTENT);
    }

    /**
     * Resolve PHP's effective `max_file_uploads` runtime limit.
     *
     * Returned to the client via the pre-flight check so the UI can chunk
     * large selections into safe batches. Defensive against a misreported
     * INI (some hosters return '' for unset values) — fall back to PHP's
     * historical default of 20 in that case.
     */
    private static function getMaxFileUploads(): int
    {
        $raw = ini_get('max_file_uploads');
        if (false === $raw || '' === $raw) {
            return 20;
        }
        $value = (int) $raw;

        return $value > 0 ? $value : 20;
    }

    #[Route('/check-upload', name: 'check_upload', methods: ['POST'], priority: 10)]
    #[OA\Post(
        path: '/api/v1/files/check-upload',
        summary: 'Pre-flight check for an upload (quota, size, extension, rate limit)',
        description: 'Lightweight metadata-only check that lets the UI fail fast BEFORE streaming the file body. Prevents timeouts when an upload would be rejected for quota reasons.',
        tags: ['Files'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['filename', 'size'],
                properties: [
                    new OA\Property(property: 'filename', type: 'string', example: 'document.pdf'),
                    new OA\Property(property: 'size', type: 'integer', example: 1048576, description: 'File size in bytes'),
                    new OA\Property(property: 'mime', type: 'string', example: 'application/pdf', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pre-flight result. allowed=false includes a reason and message; allowed=true means the upload may proceed.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'allowed', type: 'boolean', example: false),
                        new OA\Property(property: 'reason', type: 'string', enum: ['rate_limit_exceeded', 'file_too_large', 'extension_not_allowed', 'storage_exceeded'], nullable: true),
                        new OA\Property(property: 'message', type: 'string', nullable: true),
                        new OA\Property(property: 'max_file_size', type: 'integer', description: 'Per-file size limit in bytes'),
                        new OA\Property(
                            property: 'allowed_extensions',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                        ),
                        new OA\Property(property: 'remaining', type: 'integer', description: 'Remaining storage quota in bytes'),
                        new OA\Property(property: 'used', type: 'integer', nullable: true),
                        new OA\Property(property: 'limit', type: 'integer', nullable: true),
                        new OA\Property(property: 'max_files_per_request', type: 'integer', description: 'Max files per multipart POST (PHP max_file_uploads). UI must batch above this.'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function checkUpload(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $filename = isset($data['filename']) ? trim((string) $data['filename']) : '';
        if ('' === $filename) {
            return $this->json(['error' => 'filename is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['size']) || !is_numeric($data['size'])) {
            return $this->json(['error' => 'size (integer, bytes) is required'], Response::HTTP_BAD_REQUEST);
        }
        $size = (int) $data['size'];
        if ($size < 0) {
            return $this->json(['error' => 'size must be a non-negative integer'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->uploadService->checkUpload($user, $filename, $size);
        // Surface PHP's runtime cap so the UI can chunk bulk selections
        // BEFORE building the multipart body. Without this, the only signal
        // is the 413 from the upload itself — which the user only sees AFTER
        // streaming the request.
        $result['max_files_per_request'] = self::getMaxFileUploads();

        return $this->json($result);
    }

    #[Route('/{id}/process', name: 'process', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/process',
        summary: 'Trigger extraction and vectorization for a stored file',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Processing result'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function processFile(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        if (in_array($file->getStatus(), ['vectorized', 'processed'], true)) {
            return $this->json(['success' => true, 'status' => $file->getStatus(), 'already_processed' => true]);
        }

        $result = $this->uploadService->processFile($file, $user);

        return $this->json($result);
    }

    #[Route('/{id}/describe', name: 'describe', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/describe',
        summary: 'Describe, vectorize & sort a file (makes images/audio/video RAG-ready)',
        description: 'Generates a RAG-ready description (rich scene description for images, transcript + visual for audio/video, text for documents), vectorizes it, and files the result into an AI-chosen knowledge group when the user has not already chosen one.',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'File described and vectorized'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found or missing on disk'),
            new OA\Response(response: 422, description: 'No searchable content could be derived'),
            new OA\Response(response: 500, description: 'Description or vectorization failed'),
        ]
    )]
    public function describeFile(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $result = $this->uploadService->describeVectorizeAndSort($file, $user);

        if ($result['success']) {
            return $this->json($result);
        }

        $statusCode = match ($result['errorType'] ?? null) {
            'not_found' => Response::HTTP_NOT_FOUND,
            'rate_limited' => Response::HTTP_TOO_MANY_REQUESTS,
            'empty_content' => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };

        return $this->json($result, $statusCode);
    }

    #[Route('/{id}/index-prompt', name: 'index_prompt', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/index-prompt',
        summary: 'Add an AI-generated file to the knowledge base (AI description)',
        description: 'Vectorizes an AI DESCRIPTION of the generated artefact so it becomes searchable by its actual content (#1224). The stored generation prompt is only a fallback when no description can be derived. An optional group_key files the content into that knowledge group instead of the AI-suggested one.',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'group_key', type: 'string', nullable: true, example: 'Marketing')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'File indexed'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
            new OA\Response(response: 422, description: 'No searchable content available'),
            new OA\Response(response: 500, description: 'Vectorization failed'),
        ]
    )]
    public function indexPrompt(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Optional target knowledge group: both indexing paths below keep a
        // user-chosen group (they only auto-suggest when none is set), so
        // setting it on the file up front routes the vectors there.
        $body = '' !== $request->getContent() ? $request->toArray() : [];
        $requestedGroup = isset($body['group_key']) && is_string($body['group_key']) ? trim($body['group_key']) : '';
        if ('' !== $requestedGroup && 'DEFAULT' !== $requestedGroup) {
            $file->setGroupKey($requestedGroup);
        }

        // #1224: the searchable content must be the AI DESCRIPTION of the
        // artefact, not the technical generation prompt — the prompt describes
        // the wish, the description describes the actual content, and only the
        // latter makes the file findable by what is visible/audible in it.
        // describeVectorizeAndSort() derives that description from the real
        // file (vision for images, transcript for audio/video, text for
        // documents), so it is the primary path for EVERY generated artefact.
        $result = $this->uploadService->describeVectorizeAndSort($file, $user);

        // Degraded fallback: when no description can be derived (vision model
        // unavailable, undecodable file), fall back to the stored generation
        // prompt so the action still yields a searchable entry.
        if (!$result['success'] && 'rate_limited' !== ($result['errorType'] ?? null)) {
            $prompt = $this->resolveGenerationPrompt($file);
            if ('' !== trim($prompt)) {
                $result = $this->uploadService->indexGenerationPrompt($file, $user, $prompt);
            }
        }

        if ($result['success']) {
            return $this->json($result);
        }

        $statusCode = match ($result['errorType'] ?? null) {
            'not_found' => Response::HTTP_NOT_FOUND,
            'rate_limited' => Response::HTTP_TOO_MANY_REQUESTS,
            'empty_content' => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };

        return $this->json($result, $statusCode);
    }

    /**
     * The stored generation prompt of an AI-generated file: the resolved media
     * prompt (BMESSAGEMETA.media_prompt) if present, else the originating
     * message text. Fallback content only (#1224).
     */
    private function resolveGenerationPrompt(File $file): string
    {
        $messageId = $file->getMessageId();
        if (null === $messageId) {
            return '';
        }

        $message = $this->messageRepository->find($messageId);
        if (!$message) {
            return '';
        }

        $prompt = (string) ($message->getMeta('media_prompt') ?? '');

        return '' !== trim($prompt) ? $prompt : $message->getText();
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
            // Issue #1190: a chat-generated file can lose its on-disk binary
            // (e.g. a Docker rebuild) while BFILETEXT — the Markdown/text source
            // it was built from — survives in the DB. Rather than a dead-end 404
            // (the user can still preview the content), regenerate the binary
            // from BFILETEXT on the fly so the download stays consistent with
            // the preview.
            $regenerated = $this->regenerateMissingBinary($file);
            if (null !== $regenerated) {
                return $regenerated;
            }

            return $this->json(['error' => 'File not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getFileName());

        return $response;
    }

    /**
     * Rebuild a missing on-disk binary from the file's stored text source
     * (BFILETEXT) and return it as a one-shot download. Used as a safety net
     * for chat-generated files whose binary was lost (issue #1190). Returns
     * null when the file cannot be regenerated (no text or write failure), so
     * the caller falls back to a 404.
     */
    private function regenerateMissingBinary(File $file): ?BinaryFileResponse
    {
        $text = $file->getFileText();
        if ('' === trim($text)) {
            return null;
        }

        $extension = strtolower(pathinfo($file->getFileName(), PATHINFO_EXTENSION))
            ?: strtolower($file->getFileType());
        if ('' === $extension) {
            return null;
        }

        try {
            $tmpPath = tempnam(sys_get_temp_dir(), 'regen_').'.'.$extension;
            $this->documentGenerator->write($text, $extension, $tmpPath);
        } catch (\Throwable $e) {
            $this->logger->warning('FileController: failed to regenerate missing binary from BFILETEXT', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->logger->info('FileController: regenerated missing binary from BFILETEXT for download', [
            'file_id' => $file->getId(),
            'extension' => $extension,
        ]);

        $response = new BinaryFileResponse($tmpPath);
        $response->deleteFileAfterSend(true);
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
        summary: 'List uploaded files with pagination and search',
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'group_key', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search in file name and content', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'file_type', in: 'query', required: false, description: 'Filter by file extension(s), comma-separated for groups', schema: new OA\Schema(type: 'string', example: 'jpg,jpeg,png')),
            new OA\Parameter(name: 'source', in: 'query', required: false, description: 'Filter by provenance source(s), comma-separated', schema: new OA\Schema(type: 'string', example: 'nextcloud,outlook')),
            new OA\Parameter(name: 'vector_state', in: 'query', required: false, description: 'Filter by vector state(s): none, pending, vectorized, failed, not_applicable, stale (comma-separated for groups)', schema: new OA\Schema(type: 'string', example: 'stale')),
            new OA\Parameter(name: 'origin_kind', in: 'query', required: false, description: 'Filter generated media by kind: image, video, audio, calendar, document', schema: new OA\Schema(type: 'string', example: 'image')),
            new OA\Parameter(name: 'incoming', in: 'query', required: false, description: 'Filter the Incoming inbox: 1 = only incoming, 0 = exclude incoming', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'Sort order: date_desc (default), date_asc, name_asc, name_desc, size_asc, size_desc', schema: new OA\Schema(type: 'string', example: 'date_desc')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Unix timestamp lower bound', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Unix timestamp upper bound', schema: new OA\Schema(type: 'integer')),
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

        $filters = [
            'search' => $request->query->get('search'),
            'file_type' => $request->query->get('file_type'),
            'source' => $request->query->get('source'),
            'vector_state' => $request->query->get('vector_state'),
            'origin_kind' => $request->query->get('origin_kind'),
            'incoming' => $request->query->has('incoming') ? $request->query->getBoolean('incoming') : null,
            'sort' => $request->query->get('sort'),
            'date_from' => $request->query->getInt('date_from') ?: null,
            'date_to' => $request->query->getInt('date_to') ?: null,
        ];

        $result = $this->fileListService->buildListing($user->getId(), $groupKey, $offset, $limit, $filters);

        return $this->json([
            'success' => true,
            'files' => $result['files'],
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

    #[Route('/facets', name: 'facets', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/files/facets',
        summary: 'Faceted counts per source / vector state, plus the incoming count for the tab badge',
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'Facet counts'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function getFacets(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $facets = $this->fileRepository->getFacetsByUser($user->getId());

            return $this->json(['success' => true, 'facets' => $facets]);
        } catch (\Throwable $e) {
            $this->logger->error('FileController: Failed to compute facets', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to load facets'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/accept', name: 'accept_bulk', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/accept',
        summary: 'Triage (keep) multiple incoming files: clear the incoming flag, optionally file them in a group',
        tags: ['Files'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ids'],
                properties: [
                    new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'integer')),
                    new OA\Property(property: 'group_key', type: 'string', nullable: true, example: 'Contracts'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Files accepted'),
            new OA\Response(response: 400, description: 'No ids provided'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function acceptIncomingBulk(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->toArray();
        $ids = array_values(array_filter(array_map('intval', (array) ($data['ids'] ?? []))));
        if (empty($ids)) {
            return $this->json(['error' => 'ids is required'], Response::HTTP_BAD_REQUEST);
        }

        $groupKey = isset($data['group_key']) && is_string($data['group_key']) && '' !== trim($data['group_key'])
            ? trim($data['group_key'])
            : null;

        $files = $this->fileRepository->findByUserAndIds($user->getId(), $ids);
        $accepted = $this->fileListService->acceptMany($files, $user->getId(), $groupKey);

        return $this->json(['success' => true, 'accepted' => $accepted, 'group_key' => $groupKey]);
    }

    #[Route('/{id}/accept', name: 'accept', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/accept',
        summary: 'Triage (keep) an incoming file: clear the incoming flag, optionally file it in a group',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'group_key', type: 'string', nullable: true, example: 'Contracts')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'File accepted'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function acceptIncoming(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $this->fileRepository->find($id);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $groupKey = isset($data['group_key']) && is_string($data['group_key']) && '' !== trim($data['group_key'])
            ? trim($data['group_key'])
            : null;

        $this->fileListService->accept($file, $user->getId(), $groupKey);

        return $this->json(['success' => true, 'id' => $id, 'group_key' => $groupKey]);
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

    #[Route('/{id}/group-key', name: 'clear_group_key', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/files/{id}/group-key',
        summary: 'Remove a file from its knowledge group (keeps the file and its vectors)',
        description: 'Clears BGROUPKEY and moves the file\'s vector chunks to the ungrouped DEFAULT bucket. The file stays searchable by AI — only its folder membership is removed; nothing is deleted.',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'File removed from its group'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function clearGroupKey(int $id, #[CurrentUser] ?User $user): JsonResponse
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

        $file->setGroupKey(null);
        $this->fileRepository->save($file);

        // Keep the vectors — just move them to the ungrouped DEFAULT bucket so
        // the file stays searchable globally but no longer belongs to a folder.
        $chunksUpdated = $this->vectorStorageFacade->updateGroupKey($user->getId(), $file->getId(), 'DEFAULT');

        return $this->json(['success' => true, 'chunksUpdated' => $chunksUpdated]);
    }

    #[Route('/check-stale', name: 'check_stale', methods: ['POST'], priority: 10)]
    #[OA\Post(
        path: '/api/v1/files/check-stale',
        summary: 'Bulk-check which synced source files drifted (are stale) or are missing',
        description: 'Lets a sync client poll the knowledge base cheaply instead of re-uploading everything: it sends its current (source_id, source_etag) list and gets back which knowledge files are stale (etag drifted or explicitly marked), missing (never ingested or deleted), or current. Stale files stay searchable until re-vectorized.',
        tags: ['Files'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(property: 'source', type: 'string', example: 'nextcloud', description: 'Provenance source the ids belong to. Defaults to nextcloud.'),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'source_id', type: 'string', example: '12345'),
                                new OA\Property(property: 'source_etag', type: 'string', example: 'a1b2c3', nullable: true),
                            ],
                            type: 'object',
                        ),
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Per-item status list + counts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'results',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'source_id', type: 'string', example: '12345'),
                                    new OA\Property(property: 'status', type: 'string', enum: ['current', 'stale', 'missing'], example: 'stale'),
                                    new OA\Property(property: 'file_id', type: 'integer', nullable: true, example: 42),
                                    new OA\Property(property: 'stored_etag', type: 'string', nullable: true, example: 'a1b2c3'),
                                ],
                                type: 'object',
                            ),
                        ),
                        new OA\Property(property: 'counts', type: 'object', example: ['current' => 8, 'stale' => 1, 'missing' => 1]),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function checkStale(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $source = isset($data['source']) && is_string($data['source']) && '' !== trim($data['source'])
            ? trim($data['source'])
            : 'nextcloud';

        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return $this->json(['error' => 'items (array of {source_id, source_etag}) is required'], Response::HTTP_BAD_REQUEST);
        }

        // Preserve the caller's order and the first etag seen per id.
        $requested = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['source_id'])) {
                continue;
            }
            $sid = trim((string) $item['source_id']);
            if ('' === $sid || array_key_exists($sid, $requested)) {
                continue;
            }
            $etag = isset($item['source_etag']) && '' !== trim((string) $item['source_etag'])
                ? trim((string) $item['source_etag'])
                : null;
            $requested[$sid] = $etag;
        }

        $found = $this->fileRepository->findByUserSourceIds($user->getId(), $source, array_keys($requested));

        $results = [];
        $counts = ['current' => 0, 'stale' => 0, 'missing' => 0];
        foreach ($requested as $sid => $reportedEtag) {
            $file = $found[$sid] ?? null;
            if (null === $file) {
                $status = 'missing';
                $fileId = null;
                $storedEtag = null;
            } else {
                $storedEtag = $file->getSourceEtag();
                $drifted = $file->isStale()
                    || (null !== $reportedEtag && null !== $storedEtag && $reportedEtag !== $storedEtag);
                $status = $drifted ? 'stale' : 'current';
                $fileId = (int) $file->getId();
            }

            ++$counts[$status];
            $results[] = [
                'source_id' => $sid,
                'status' => $status,
                'file_id' => $fileId,
                'stored_etag' => $storedEtag,
            ];
        }

        return $this->json([
            'success' => true,
            'results' => $results,
            'counts' => $counts,
        ]);
    }

    #[Route('/{id}/mark-stale', name: 'mark_stale', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/mark-stale',
        summary: 'Mark a knowledge file stale (its source changed and it needs re-vectorizing)',
        description: 'Sets the explicit stale marker. The old vectors stay in place and searchable until the file is re-vectorized, so answers degrade gracefully rather than dropping the document.',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'source_etag', type: 'string', example: 'z9y8x7', description: 'Optional new source etag to record alongside the stale marker.')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'File marked stale'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Access denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function markStale(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        $data = json_decode((string) $request->getContent(), true);
        if (is_array($data) && isset($data['source_etag']) && '' !== trim((string) $data['source_etag'])) {
            $file->setSourceEtag(trim((string) $data['source_etag']));
        }

        $file->setStale(true);
        // Reflect staleness in the persisted vector state so the file manager
        // badge + `?vector_state=stale` filter see it immediately. Only a file
        // that actually carries vectors reads as "stale"; one without vectors
        // stays in its current pre-vectorized state.
        if (File::VECTOR_STATE_VECTORIZED === $file->getVectorState() || $file->getChunkCount() > 0) {
            $file->setVectorState(File::VECTOR_STATE_STALE);
        }
        $this->fileRepository->save($file, true);

        return $this->json([
            'success' => true,
            'id' => (int) $file->getId(),
            'stale' => true,
            'vector_state' => $file->getVectorState(),
        ]);
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
}
