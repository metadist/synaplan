<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\User;
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
    public function uploadBatch(array $uploadedFiles, User $user, ?string $groupKey, string $processLevel): array
    {
        $startTime = microtime(true);
        $results = [];
        $errors = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $fileStartTime = microtime(true);

            try {
                $result = $this->processSingleUpload($uploadedFile, $user, $groupKey, $processLevel);

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
            return ['success' => false, 'error' => $storageResult['error']];
        }

        $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
        $file = $this->createFileEntity($uploadedFile, $user, $storageResult, $groupKey);

        $result = [
            'success' => true,
            'id' => $file->getId(),
            'filename' => $uploadedFile->getClientOriginalName(),
            'size' => $storageResult['size'],
            'mime' => $storageResult['mime'],
            'path' => $storageResult['path'],
            'group_key' => $groupKey,
        ];

        if ('store' === $processLevel) {
            return $result;
        }

        $result = $this->extractText($file, $storageResult['path'], $fileExtension, $user, $processLevel, $result);
        if (!$result['success'] || 'extract' === $processLevel) {
            return $result;
        }

        $extractedText = $file->getFileText();
        if (in_array($processLevel, ['vectorize', 'full'], true) && '' !== trim($extractedText)) {
            $result = $this->vectorize($file, $extractedText, $user, $groupKey, $fileExtension, $result);
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
    ): File {
        $file = new File();
        $file->setUserId($user->getId());
        $file->setFilePath($storageResult['path']);
        $file->setFileType(strtolower($uploadedFile->getClientOriginalExtension()));
        $file->setFileName($uploadedFile->getClientOriginalName());
        $file->setFileSize($storageResult['size']);
        $file->setFileMime($storageResult['mime']);
        $file->setGroupKey($groupKey);
        $file->setStatus('uploaded');

        $this->em->persist($file);
        $this->em->flush();

        return $file;
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
}
