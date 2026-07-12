<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use App\Service\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class FileUploadService
{
    public function __construct(
        private FileStorageService $storageService,
        private FileProcessor $fileProcessor,
        private VectorizationService $vectorizationService,
        private VectorStorageFacade $vectorStorageFacade,
        private StorageQuotaService $storageQuotaService,
        private RateLimitService $rateLimitService,
        private FileGroupSorter $groupSorter,
        private FileRepository $fileRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * Process a batch of uploaded files through the pipeline.
     *
     * @param UploadedFile[] $uploadedFiles
     *
     * @return array{success: bool, files: array<mixed>, errors: array<mixed>, total_time_ms: int, process_level: string}
     */
    public function uploadBatch(array $uploadedFiles, User $user, ?string $groupKey, string $processLevel, ?UploadOptions $options = null): array
    {
        $options ??= new UploadOptions();
        $startTime = microtime(true);
        $results = [];
        $errors = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $fileStartTime = microtime(true);

            try {
                $result = $this->processSingleUpload($uploadedFile, $user, $groupKey, $processLevel, $options);

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
                $this->logger->error('FileUploadService: File upload failed', [
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

        return [
            'success' => 0 === count($errors),
            'files' => $results,
            'errors' => $errors,
            'total_time_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'process_level' => $processLevel,
        ];
    }

    /**
     * Pre-flight upload check.
     *
     * Validates rate limit, per-file size, file extension, and storage quota
     * BEFORE the client uploads the file body. Lets the UI show a deterministic
     * error instead of waiting for a slow/timing-out upload near quota.
     *
     * @return array{
     *   allowed: bool,
     *   reason?: 'rate_limit_exceeded'|'file_too_large'|'file_empty'|'extension_not_allowed'|'storage_exceeded',
     *   message?: string,
     *   max_file_size: int,
     *   allowed_extensions: list<string>,
     *   remaining: int,
     *   used?: int,
     *   limit?: int
     * }
     */
    public function checkUpload(User $user, string $filename, int $fileSize): array
    {
        $maxFileSize = FileStorageService::getMaxFileSize();
        $allowedExtensions = FileStorageService::getAllowedExtensions();
        $remaining = $this->storageQuotaService->getRemainingStorage($user);

        $base = [
            'max_file_size' => $maxFileSize,
            'allowed_extensions' => $allowedExtensions,
            'remaining' => $remaining,
        ];

        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'FILE_ANALYSIS');
        if (!$rateLimitCheck['allowed']) {
            return array_merge($base, [
                'allowed' => false,
                'reason' => 'rate_limit_exceeded',
                'message' => "Rate limit exceeded for FILE_ANALYSIS. Used: {$rateLimitCheck['used']}/{$rateLimitCheck['limit']}",
                'used' => (int) $rateLimitCheck['used'],
                'limit' => (int) $rateLimitCheck['limit'],
            ]);
        }

        if ($fileSize <= 0) {
            // Distinct reason from `file_too_large` so the frontend can
            // render an "empty file" copy that actually matches the
            // situation. The shared message hint about
            // Dropbox/iCloud/OneDrive lazy-loading covers the most
            // common cause for a zero-byte upload (the user picked a
            // cloud placeholder instead of the actual file).
            return array_merge($base, [
                'allowed' => false,
                'reason' => 'file_empty',
                'message' => 'File appears to be empty. If using Dropbox, iCloud, or OneDrive, please download the file locally first before uploading.',
            ]);
        }

        if ($fileSize > $maxFileSize) {
            return array_merge($base, [
                'allowed' => false,
                'reason' => 'file_too_large',
                'message' => sprintf('File too large. Maximum size is %d MB.', (int) ($maxFileSize / 1024 / 1024)),
            ]);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ('' === $extension || !in_array($extension, $allowedExtensions, true)) {
            return array_merge($base, [
                'allowed' => false,
                'reason' => 'extension_not_allowed',
                'message' => 'File type not allowed. Allowed: '.implode(', ', $allowedExtensions),
            ]);
        }

        if ($fileSize > $remaining) {
            return array_merge($base, [
                'allowed' => false,
                'reason' => 'storage_exceeded',
                'message' => sprintf(
                    'Storage limit exceeded. You have %s remaining, but the file is %s. Upgrade your plan for more storage.',
                    $this->formatBytes($remaining),
                    $this->formatBytes($fileSize)
                ),
            ]);
        }

        return array_merge($base, ['allowed' => true]);
    }

    /**
     * Format bytes to human-readable text. Kept private and minimal.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 2).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    /**
     * Process a single uploaded file: store, extract text, vectorize.
     *
     * @return array<string, mixed>
     */
    private function processSingleUpload(
        UploadedFile $uploadedFile,
        User $user,
        ?string $groupKey,
        string $processLevel,
        UploadOptions $options,
    ): array {
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

        try {
            $this->storageQuotaService->checkStorageLimit($user, $uploadedFile->getSize());
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'storage_exceeded' => true,
            ];
        }

        $storageResult = $this->storageService->storeUploadedFile($uploadedFile, $user->getId());
        if (!$storageResult['success']) {
            return [
                'success' => false,
                'error' => FileHelper::withAdminDiagnostics(
                    (string) $storageResult['error'],
                    $user->isAdmin(),
                    $storageResult['error_detail'] ?? null,
                ),
            ];
        }

        // After a HEIC->JPEG transcode the stored file is a JPEG, so route
        // extraction/vectorization on the final stored extension, not the
        // client's original ".heic".
        $fileExtension = strtolower($storageResult['extension'] ?? $uploadedFile->getClientOriginalExtension());

        // Overwrite-in-place (CORE-4): when the caller asks to overwrite and the
        // same logical file already exists (matched by source identity, else by
        // group + original name), replace that row's content and keep its id so
        // the KB never accumulates duplicates of a synced source file.
        $existing = $options->overwrite
            ? $this->fileRepository->findForOverwrite($user->getId(), $options->source, $options->sourceId, $groupKey, $options->originalName)
            : null;

        if (null !== $existing) {
            $file = $this->overwriteFileEntity($existing, $uploadedFile, $storageResult, $groupKey, $options);
            $overwritten = true;
        } else {
            $file = $this->createFileEntity($uploadedFile, $user, $storageResult, $groupKey, $options);
            $overwritten = false;
        }

        $result = [
            'success' => true,
            'id' => $file->getId(),
            'filename' => $file->getFileName(),
            'size' => $storageResult['size'],
            'mime' => $storageResult['mime'],
            'path' => $storageResult['path'],
            'group_key' => $groupKey,
            'overwritten' => $overwritten,
            'source_id' => $file->getSourceId(),
        ];

        if ('store' === $processLevel) {
            return $result;
        }

        // Media (image/audio/video) is NOT auto-extracted/vectorized on upload.
        // The user opts in per file via the file manager's "Describe, vectorize
        // & sort" action (FileController::describe), which produces a rich
        // description and files it into a knowledge group. Documents keep the
        // automatic extract+vectorize pipeline below.
        if ($file->isMedia()) {
            $result['media_pending_describe'] = true;

            return $result;
        }

        $result = $this->extractText($file, $storageResult['path'], $fileExtension, $user, $processLevel, $result);
        if (!$result['success'] || 'extract' === $processLevel) {
            return $result;
        }

        $extractedText = $file->getFileText();
        if (in_array($processLevel, ['vectorize', 'full'], true) && '' !== trim($extractedText)) {
            $result = $this->vectorize($file, $extractedText, $user, $groupKey, $fileExtension, $result);

            // Delete-after-embed (CORE-4): when the caller does not want the
            // binary retained, drop it once vectors exist. We keep the row, the
            // extracted text and the vectors, so RAG + re-vectorize (from stored
            // text) still work while the external system stays the source of
            // truth for the binary.
            if (!$options->retainSource && ($result['vectorized'] ?? false)) {
                $this->discardStoredBinary($file);
                $result['source_retained'] = false;
            }
        }

        if ('full' === $processLevel) {
            $result['ai_processed'] = false;
            $result['ai_processing_note'] = 'AI processing not yet implemented';
        }

        // recordFileAnalysisOnce dedups on (user_id, file_id) so a later
        // `processFile()` retry against the same File entity cannot create
        // a second BUSELOG row (issue #887: RAG double-count on retry).
        $this->rateLimitService->recordFileAnalysisOnce($user, (int) $file->getId(), [
            'filename' => $uploadedFile->getClientOriginalName(),
            'source' => 'WEB',
        ]);

        return $result;
    }

    private function createFileEntity(
        UploadedFile $uploadedFile,
        User $user,
        array $storageResult,
        ?string $groupKey,
        UploadOptions $options,
    ): File {
        $convertedFrom = $storageResult['converted_from'] ?? null;

        $file = new File();
        $file->setUserId($user->getId());
        $file->setFilePath($storageResult['path']);
        $file->setFileType(strtolower($storageResult['extension'] ?? $uploadedFile->getClientOriginalExtension()));
        $file->setFileName($storageResult['display_name'] ?? $uploadedFile->getClientOriginalName());
        $file->setFileSize($storageResult['size']);
        $file->setFileMime($storageResult['mime']);
        $file->setGroupKey($groupKey);
        $file->setStatus('uploaded');
        $file->setSource($options->source);
        // External pushes (Outlook/Nextcloud/OpenCloud) land in the Incoming
        // inbox for the user to triage before they join the curated library
        // (03_file-management.md §3.3). Web uploads are never incoming.
        $file->setIncoming(in_array($options->source, File::INCOMING_SOURCES, true));
        // Preserve the original ".heic" filename for provenance when we
        // transcoded the upload and the caller didn't supply its own.
        $file->setOriginalName($options->originalName ?? (null !== $convertedFrom ? $uploadedFile->getClientOriginalName() : null));
        $file->setSourceId($options->sourceId);
        $file->setSourceEtag($options->sourceEtag);

        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    /**
     * Replace an existing file's content in place for CORE-4 overwrite: swap the
     * stored binary, drop the old vectors, refresh provenance/identity and clear
     * the stale marker. The BID is preserved; extraction/vectorization then runs
     * on the new content through the normal pipeline.
     */
    private function overwriteFileEntity(
        File $file,
        UploadedFile $uploadedFile,
        array $storageResult,
        ?string $groupKey,
        UploadOptions $options,
    ): File {
        $convertedFrom = $storageResult['converted_from'] ?? null;
        $newPath = $storageResult['path'];
        $oldPath = $file->getFilePath();

        // Drop the superseded binary and the stale vectors so a re-vectorize
        // cannot double-count chunks (mirrors reVectorize()/describe()).
        if ('' !== $oldPath && $oldPath !== $newPath) {
            $this->storageService->deleteFile($oldPath);
        }
        $this->vectorStorageFacade->deleteByFile($file->getUserId(), (int) $file->getId());

        $file->setFilePath($newPath);
        $file->setFileType(strtolower($storageResult['extension'] ?? $uploadedFile->getClientOriginalExtension()));
        $file->setFileName($storageResult['display_name'] ?? $uploadedFile->getClientOriginalName());
        $file->setFileSize($storageResult['size']);
        $file->setFileMime($storageResult['mime']);
        if (null !== $groupKey) {
            $file->setGroupKey($groupKey);
        }
        $file->setStatus('uploaded');
        // Force re-extraction from the new binary: the pipeline only re-extracts
        // when the stored text is empty.
        $file->setFileText('');
        $file->setSource($options->source);
        $file->setOriginalName($options->originalName ?? (null !== $convertedFrom ? $uploadedFile->getClientOriginalName() : $file->getOriginalName()));
        $file->setSourceId($options->sourceId ?? $file->getSourceId());
        $file->setSourceEtag($options->sourceEtag);
        // Fresh content supersedes any prior "source changed" marker.
        $file->setStale(false);
        $file->setVectorState(File::VECTOR_STATE_PENDING);

        $this->em->flush();

        return $file;
    }

    /**
     * Discard the stored binary for a delete-after-embed upload, keeping the
     * BFILES row, extracted text and vectors intact (CORE-4 retain_source=0).
     */
    private function discardStoredBinary(File $file): void
    {
        $path = $file->getFilePath();
        if ('' === $path) {
            return;
        }

        try {
            $this->storageService->deleteFile($path);
        } catch (\Throwable $e) {
            $this->logger->warning('FileUploadService: delete-after-embed binary discard failed', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractText(
        File $file,
        string $relativePath,
        string $fileExtension,
        User $user,
        string $processLevel,
        array $result,
    ): array {
        try {
            [$extractedText, $extractMeta] = $this->fileProcessor->extractText(
                $relativePath,
                $fileExtension,
                $user->getId(),
            );

            $file->setFileText($extractedText);

            // For audio/video files an empty transcript means transcription
            // failed — the file may be silent, in an unsupported codec, or the
            // STT provider rejected it.  Signal this clearly so the UI can show
            // a distinct error badge instead of "Extracted (0 chars)".
            if ('' === trim($extractedText) && FileProcessor::isTranscribableMediaExtension($fileExtension)) {
                $file->setStatus('error');
                $this->em->flush();

                return ['success' => false, 'error' => 'Transcription produced no text — the file may be silent or in an unsupported codec.'];
            }

            $file->setStatus('extracted');
            $this->em->flush();

            $result['extracted_text_length'] = strlen($extractedText);
            $result['extraction_strategy'] = $extractMeta['strategy'] ?? 'unknown';

            if ('extract' === $processLevel) {
                return $result;
            }
        } catch (\Throwable $e) {
            $this->logger->error('FileUploadService: Text extraction failed', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Text extraction failed: '.$e->getMessage()];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function vectorize(
        File $file,
        string $extractedText,
        User $user,
        ?string $groupKey,
        string $fileExtension,
        array $result,
    ): array {
        try {
            $vectorResult = $this->vectorizationService->vectorizeAndStore(
                $extractedText,
                $user->getId(),
                $file->getId(),
                $groupKey ?? '',
                FileHelper::getFileTypeCode($fileExtension),
            );

            if ($vectorResult['success']) {
                $file->setStatus('vectorized');
                $this->em->flush();
                $result['chunks_created'] = $vectorResult['chunks_created'];
                $result['vectorized'] = true;
            } else {
                $this->logger->warning('FileUploadService: Vectorization failed', [
                    'file_id' => $file->getId(),
                    'error' => $vectorResult['error'],
                ]);
                $result['vectorized'] = false;
                $result['vectorization_error'] = $vectorResult['error'];
            }
        } catch (\Throwable $e) {
            $this->logger->error('FileUploadService: Vectorization exception', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);
            $result['vectorized'] = false;
            $result['vectorization_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Run extraction + vectorization for a stored file (used for async processing after fast upload).
     *
     * @return array{success: bool, status: string, error?: string, extracted_text_length?: int, chunks_created?: int}
     */
    public function processFile(File $file, User $user): array
    {
        if (in_array($file->getStatus(), ['extracting', 'vectorizing'], true)) {
            return ['success' => true, 'status' => $file->getStatus(), 'message' => 'File is already being processed'];
        }

        if ('error' === $file->getStatus()) {
            return ['success' => false, 'status' => 'error', 'error' => 'File is in error state'];
        }

        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'FILE_ANALYSIS');
        if (!$rateLimitCheck['allowed']) {
            return [
                'success' => false,
                'status' => $file->getStatus(),
                'error' => "Rate limit exceeded for FILE_ANALYSIS. Used: {$rateLimitCheck['used']}/{$rateLimitCheck['limit']}",
            ];
        }

        $fileExtension = strtolower($file->getFileType() ?: (string) pathinfo($file->getFilePath(), PATHINFO_EXTENSION));

        if ('uploaded' === $file->getStatus()) {
            $file->setStatus('extracting');
            $this->em->flush();

            try {
                [$extractedText, $extractMeta] = $this->fileProcessor->extractText(
                    $file->getFilePath(),
                    $fileExtension,
                    $user->getId(),
                );

                $file->setFileText($extractedText);
                $file->setStatus('extracted');
                $this->em->flush();
            } catch (\Throwable $e) {
                $file->setStatus('error');
                $this->em->flush();
                $this->logger->error('FileUploadService: Async extraction failed', [
                    'file_id' => $file->getId(),
                    'error' => $e->getMessage(),
                ]);

                return ['success' => false, 'status' => 'error', 'error' => 'Text extraction failed: '.$e->getMessage()];
            }
        }

        $extractedText = $file->getFileText();
        if ('' === trim($extractedText)) {
            // Audio/video with no transcript: the STT pipeline returned nothing
            // (provider rejection, silent file, unsupported codec).  Mark as
            // error so the UI shows a clear status instead of silently treating
            // the file as "ready".  Non-media files (blank PDFs, etc.) follow
            // the old path — a zero-length extraction is legitimate for them.
            if (FileProcessor::isTranscribableMediaExtension($fileExtension)) {
                $file->setStatus('error');
                $this->em->flush();

                return ['success' => false, 'status' => 'error', 'error' => 'Transcription produced no text — the file may be silent or in an unsupported codec.'];
            }

            $file->setStatus('vectorized');
            $this->em->flush();

            // Dedup on (user_id, file_id) — see issue #887. If the
            // earlier processSingleUpload() (or a previous /process retry)
            // already wrote a BUSELOG row for this file, this is a no-op.
            $this->rateLimitService->recordFileAnalysisOnce($user, (int) $file->getId(), [
                'filename' => $file->getFileName(),
                'source' => 'WEB_ASYNC',
            ]);

            return ['success' => true, 'status' => 'vectorized', 'extracted_text_length' => 0, 'chunks_created' => 0];
        }

        $file->setStatus('vectorizing');
        $this->em->flush();

        $groupKey = $file->getGroupKey() ?? '';

        try {
            $vectorResult = $this->vectorizationService->vectorizeAndStore(
                $extractedText,
                $user->getId(),
                $file->getId(),
                $groupKey,
                FileHelper::getFileTypeCode($fileExtension),
            );

            if ($vectorResult['success']) {
                $file->setStatus('vectorized');
                $this->em->flush();

                // Dedup on (user_id, file_id) — issue #887 RAG double-count.
                $this->rateLimitService->recordFileAnalysisOnce($user, (int) $file->getId(), [
                    'filename' => $file->getFileName(),
                    'source' => 'WEB_ASYNC',
                ]);

                return [
                    'success' => true,
                    'status' => 'vectorized',
                    'extracted_text_length' => strlen($extractedText),
                    'chunks_created' => $vectorResult['chunks_created'],
                ];
            }

            $file->setStatus('extracted');
            $this->em->flush();

            return ['success' => false, 'status' => 'extracted', 'error' => $vectorResult['error'] ?? 'Vectorization failed'];
        } catch (\Throwable $e) {
            $file->setStatus('extracted');
            $this->em->flush();
            $this->logger->error('FileUploadService: Async vectorization failed', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'status' => 'extracted', 'error' => 'Vectorization failed: '.$e->getMessage()];
        }
    }

    /**
     * Delete existing vectors, re-extract text if needed, and re-vectorize.
     *
     * @return array{success: bool, error?: string, errorType?: string, chunksCreated?: int, extractedTextLength?: int, groupKey?: string, provider?: string}
     */
    public function reVectorize(File $file, User $user, string $groupKey): array
    {
        if ('' !== $groupKey && 'DEFAULT' !== $groupKey) {
            $file->setGroupKey($groupKey);
            $this->em->flush();
        }

        $this->vectorStorageFacade->deleteByFile($user->getId(), $file->getId());

        $extractedText = $file->getFileText();
        $fileExtension = strtolower($file->getFileType() ?: (string) pathinfo($file->getFilePath(), PATHINFO_EXTENSION));

        if ('' === trim($extractedText)) {
            $absolutePath = $this->uploadDir.'/'.ltrim($file->getFilePath(), '/');
            if (!FileHelper::fileExistsNfs($absolutePath)) {
                return ['success' => false, 'error' => 'File not found on disk', 'errorType' => 'not_found'];
            }

            try {
                [$extractedText] = $this->fileProcessor->extractText($file->getFilePath(), $fileExtension, $user->getId());
                $file->setFileText($extractedText);
                $file->setStatus('extracted');
                $this->em->flush();
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Text extraction failed: '.$e->getMessage(), 'errorType' => 'extraction_failed'];
            }
        }

        if ('' === trim($extractedText)) {
            return ['success' => false, 'error' => 'Text extraction produced no content', 'errorType' => 'empty_content'];
        }

        try {
            $vectorResult = $this->vectorizationService->vectorizeAndStore(
                $extractedText,
                $user->getId(),
                $file->getId(),
                $groupKey,
                FileHelper::getFileTypeCode($fileExtension),
            );

            if ($vectorResult['success']) {
                $file->setStatus('vectorized');
                // Fresh vectors clear any prior "source changed" marker (CORE-4).
                $file->setStale(false);
                $this->em->flush();

                return [
                    'success' => true,
                    'chunksCreated' => $vectorResult['chunks_created'],
                    'extractedTextLength' => strlen($extractedText),
                    'groupKey' => $groupKey,
                    'provider' => $vectorResult['provider'] ?? 'unknown',
                ];
            }

            return ['success' => false, 'error' => 'Vectorization failed: '.($vectorResult['error'] ?? 'Unknown error')];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Vectorization failed: '.$e->getMessage()];
        }
    }

    /**
     * The file manager's "Describe, vectorize & sort" action: (re)extract a
     * RAG-ready description (rich scene description for images, transcript +
     * visual for audio/video, normal text for documents), vectorize it, and
     * file the result into an AI-chosen knowledge group when the user has not
     * already picked one. Idempotent — existing vectors are cleared first.
     *
     * @return array{success: bool, error?: string, errorType?: string, chunksCreated?: int, extractedTextLength?: int, groupKey?: string}
     */
    public function describeVectorizeAndSort(File $file, User $user): array
    {
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'FILE_ANALYSIS');
        if (!$rateLimitCheck['allowed']) {
            return [
                'success' => false,
                'error' => "Rate limit exceeded for FILE_ANALYSIS. Used: {$rateLimitCheck['used']}/{$rateLimitCheck['limit']}",
                'errorType' => 'rate_limited',
            ];
        }

        $absolutePath = $this->uploadDir.'/'.ltrim($file->getFilePath(), '/');
        if (!FileHelper::fileExistsNfs($absolutePath)) {
            return ['success' => false, 'error' => 'File not found on disk', 'errorType' => 'not_found'];
        }

        $fileExtension = strtolower($file->getFileType() ?: (string) pathinfo($file->getFilePath(), PATHINFO_EXTENSION));

        $file->setStatus('extracting');
        $this->em->flush();

        try {
            [$extractedText] = $this->fileProcessor->extractText(
                $file->getFilePath(),
                $fileExtension,
                $user->getId(),
                $file->isMedia(),
            );
        } catch (\Throwable $e) {
            $file->setStatus('error');
            $this->em->flush();

            return ['success' => false, 'error' => 'Description failed: '.$e->getMessage(), 'errorType' => 'extraction_failed'];
        }

        if ('' === trim($extractedText)) {
            $file->setStatus('error');
            $this->em->flush();

            return ['success' => false, 'error' => 'Could not derive any searchable content from this file.', 'errorType' => 'empty_content'];
        }

        $file->setFileText($extractedText);
        $file->setStatus('extracted');
        $this->em->flush();

        // Clear any prior vectors so a re-run cannot double-count chunks.
        $this->vectorStorageFacade->deleteByFile($user->getId(), $file->getId());

        // Sort: keep a user-chosen group, otherwise let the AI pick one.
        $groupKey = $file->getGroupKey() ?? '';
        if ('' === trim($groupKey) || 'DEFAULT' === $groupKey) {
            $existingGroups = array_keys($this->fileRepository->getGroupCountsByUser($user->getId()));
            $suggested = $this->groupSorter->suggestGroup($extractedText, $existingGroups, $user->getId());
            if (null !== $suggested) {
                $groupKey = $suggested;
            }
        }

        $file->setStatus('vectorizing');
        $this->em->flush();

        try {
            $vectorResult = $this->vectorizationService->vectorizeAndStore(
                $extractedText,
                $user->getId(),
                (int) $file->getId(),
                $groupKey,
                FileHelper::getFileTypeCode($fileExtension),
            );
        } catch (\Throwable $e) {
            $file->setStatus('extracted');
            $this->em->flush();

            return ['success' => false, 'error' => 'Vectorization failed: '.$e->getMessage(), 'errorType' => 'vectorization_failed'];
        }

        if (!$vectorResult['success']) {
            $file->setStatus('extracted');
            $this->em->flush();

            return ['success' => false, 'error' => 'Vectorization failed: '.($vectorResult['error'] ?? 'Unknown error'), 'errorType' => 'vectorization_failed'];
        }

        if ('' !== trim($groupKey) && 'DEFAULT' !== $groupKey) {
            $file->setGroupKey($groupKey);
        }
        $file->setStatus('vectorized');
        $this->em->flush();

        $this->rateLimitService->recordFileAnalysisOnce($user, (int) $file->getId(), [
            'filename' => $file->getFileName(),
            'source' => 'WEB_DESCRIBE',
        ]);

        return [
            'success' => true,
            'chunksCreated' => $vectorResult['chunks_created'],
            'extractedTextLength' => strlen($extractedText),
            'groupKey' => $groupKey,
        ];
    }

    /**
     * "Add prompt to knowledge base" for an AI-generated file: vectorize the
     * supplied generation prompt so the artefact becomes findable by what the
     * user asked for. Idempotent — existing vectors are cleared first.
     *
     * @return array{success: bool, error?: string, errorType?: string, chunksCreated?: int, groupKey?: string}
     */
    public function indexGenerationPrompt(File $file, User $user, string $prompt): array
    {
        $prompt = trim($prompt);
        if ('' === $prompt) {
            return ['success' => false, 'error' => 'No generation prompt available for this file.', 'errorType' => 'empty_content'];
        }

        $file->setFileText($prompt);
        $this->em->flush();

        $this->vectorStorageFacade->deleteByFile($user->getId(), $file->getId());

        $groupKey = $file->getGroupKey() ?? '';
        if ('' === trim($groupKey) || 'DEFAULT' === $groupKey) {
            $existingGroups = array_keys($this->fileRepository->getGroupCountsByUser($user->getId()));
            $suggested = $this->groupSorter->suggestGroup($prompt, $existingGroups, $user->getId());
            if (null !== $suggested) {
                $groupKey = $suggested;
            }
        }

        $fileExtension = strtolower($file->getFileType() ?: (string) pathinfo($file->getFilePath(), PATHINFO_EXTENSION));

        try {
            $vectorResult = $this->vectorizationService->vectorizeAndStore(
                $prompt,
                $user->getId(),
                (int) $file->getId(),
                $groupKey,
                FileHelper::getFileTypeCode($fileExtension),
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Vectorization failed: '.$e->getMessage(), 'errorType' => 'vectorization_failed'];
        }

        if (!$vectorResult['success']) {
            return ['success' => false, 'error' => 'Vectorization failed: '.($vectorResult['error'] ?? 'Unknown error'), 'errorType' => 'vectorization_failed'];
        }

        if ('' !== trim($groupKey) && 'DEFAULT' !== $groupKey) {
            $file->setGroupKey($groupKey);
        }
        $file->setStatus('vectorized');
        $this->em->flush();

        return [
            'success' => true,
            'chunksCreated' => $vectorResult['chunks_created'],
            'groupKey' => $groupKey,
        ];
    }
}
