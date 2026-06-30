<?php

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\File\FileProcessor;
use App\Service\File\TikaClient;
use App\Service\RateLimitService;
use App\Service\WhisperService;
use Psr\Log\LoggerInterface;

/**
 * PreProcessor für eingehende Nachrichten.
 *
 * Tasks:
 * - File Download (von WhatsApp, Upload, etc.)
 * - File Parsing (Tika, Whisper, Vision AI)
 * - Message Metadata extraction
 */
final readonly class MessagePreProcessor
{
    // Supported file types for preprocessing.
    //
    // Issue #954: 'md', 'csv', and 'ppt' were missing here even though they
    // are accepted uploads (FileStorageService::ALLOWED_EXTENSIONS) and are
    // fully handled by FileProcessor::extractText() — 'md'/'csv' as native
    // plain text and 'ppt' via Tika using OFFICE_EXT_TO_MIME. Without these
    // entries the chat preprocessor silently skipped the file, leaving
    // BFILETEXT empty and FileAnalysisHandler reporting "unsupported file
    // type" for legitimately uploaded documents.
    public const DOCUMENT_EXTENSIONS = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'txt', 'md', 'csv'];
    public const AUDIO_EXTENSIONS = ['ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm', 'amr'];
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    // Issue #722/#983: video uploads (e.g. an MP4 the user wants summarised)
    // were never converted to text on the chat path, so FileAnalysisHandler
    // reported "This file type cannot be analyzed". FileProcessor::extractText()
    // analyses them by transcribing their audio track via Whisper AND describing
    // a representative key frame via Vision AI, keeping the result in BFILETEXT.
    // 'webm' deliberately stays in AUDIO_EXTENSIONS (browser/WhatsApp voice notes
    // use it) — listing it here too would make the membership checks ambiguous.
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'mpeg', 'mpg'];

    private const OFFICE_EXT_TO_MIME = [
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(
        private MessageRepository $messageRepository,
        private TikaClient $tikaClient,
        private WhisperService $whisperService,
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
        private string $uploadsDir,
        private RateLimitService $rateLimitService,
        private UserRepository $userRepository,
        private FileProcessor $fileProcessor,
    ) {
    }

    /**
     * Prozessiert Message (Files downloaden, parsen, etc.).
     */
    public function process(Message $message, ?callable $progressCallback = null): Message
    {
        // Check for legacy single file
        $hasLegacyFile = $message->getFile() > 0 && $message->getFilePath();

        // Check for new multiple files (File entities)
        $messageFiles = $message->getFiles();
        $hasNewFiles = $messageFiles->count() > 0;

        $this->logger->info('PreProcessor: Starting processing', [
            'message_id' => $message->getId(),
            'has_legacy_file' => $hasLegacyFile,
            'new_files_count' => $messageFiles->count(),
        ]);

        if ($hasLegacyFile) {
            $this->notify($progressCallback, 'preprocessing', 'Processing file...');
            $this->processFile($message);
            $this->notify($progressCallback, 'preprocessing', 'File processing complete.');
        }

        // Process new multiple files (File entities)
        if ($hasNewFiles) {
            $this->logger->info('PreProcessor: Processing multiple files', [
                'count' => $messageFiles->count(),
            ]);

            $this->notify($progressCallback, 'preprocessing', "Processing {$messageFiles->count()} file(s)...");
            $processed = 0;
            foreach ($messageFiles as $messageFile) {
                $this->logger->info('PreProcessor: Processing file', [
                    'file_id' => $messageFile->getId(),
                    'filename' => $messageFile->getFileName(),
                ]);

                $this->processMessageFile($messageFile, $message);
                ++$processed;
                $this->notify($progressCallback, 'preprocessing', "Processed $processed/{$messageFiles->count()} files");
            }
            $this->notify($progressCallback, 'preprocessing', 'All files processed.');

            // CRITICAL: Persist changes to File entities!
            $this->messageRepository->save($message);
        } else {
            $this->logger->warning('PreProcessor: No files to process', [
                'message_id' => $message->getId(),
            ]);
        }

        $this->messageRepository->save($message);

        return $message;
    }

    /**
     * Process a File entity (NEW: multiple files support).
     */
    private function processMessageFile(File $messageFile, Message $message): void
    {
        $filePath = $messageFile->getFilePath();
        $fileType = strtolower($messageFile->getFileType());

        // File existiert lokal?
        $fullPath = $this->uploadsDir.'/'.$filePath;
        if (!file_exists($fullPath)) {
            $this->logger->warning("File not found: {$fullPath}");
            $messageFile->setStatus('error');

            return;
        }

        // Skip extraction if text already exists (e.g., from FileProcessor in upload endpoint)
        // This prevents overwriting robust extraction with simple Tika-only extraction
        if (!empty($messageFile->getFileText())) {
            $this->logger->info('PreProcessor: File text already extracted, skipping re-extraction', [
                'file_id' => $messageFile->getId(),
                'type' => $fileType,
                'text_length' => strlen($messageFile->getFileText()),
            ]);
            // Issue #1191: re-attaching an already-vectorized file must NOT
            // downgrade its status to 'processed' — the Qdrant vectors are
            // still valid, so the DB status would become inconsistent. Only
            // promote files that have not already reached a terminal state.
            if (!in_array($messageFile->getStatus(), ['vectorized', 'processed'], true)) {
                $messageFile->setStatus('processed');
            }

            // Issue #887: even when extraction was already done at upload
            // time, the analysis-billing event fires HERE (the message is
            // actually being sent / consumed). The dedup helper makes this
            // safe across re-runs of the preprocessor.
            $this->billFileAnalysis($messageFile, $message, 'reuse_extracted');

            return;
        }

        $this->logger->info('PreProcessor: Processing File', [
            'file_id' => $messageFile->getId(),
            'type' => $fileType,
            'size' => $messageFile->getFileSize(),
        ]);

        // Parse File mit FileProcessor (Tika + Vision-Fallback für PDFs).
        //
        // Issue #729: this used to call parseWithTika() directly, which has no
        // vision fallback and no MIME normalisation. When Tika reported the
        // DOCX as application/zip or returned an empty body, the file was
        // marked `processed` with empty BFILETEXT and the chat surfaced a
        // generic "Document text extraction failed" error. The shared
        // FileProcessor::extractText() pipeline (Tika -> PDF rasterise +
        // Vision AI fallback for low-quality output) is the same strategy
        // the /api/v1/files upload path already uses, so we get one
        // consistent extraction behaviour across upload entry points.
        // Documents and video files share the same extraction pipeline:
        // FileProcessor::extractText() runs Tika for documents and, for video,
        // transcribes the audio track AND describes a representative key frame
        // (issues #722 + #983), returning plain text we store in BFILETEXT so
        // the chat model can reason about it. This is the single canonical
        // video path — do NOT add a second video branch below.
        if (in_array($fileType, self::DOCUMENT_EXTENSIONS) || in_array($fileType, self::VIDEO_EXTENSIONS)) {
            $isVideo = in_array($fileType, self::VIDEO_EXTENSIONS, true);
            $messageFile->setStatus('extracting');

            try {
                [$text, $extractMeta] = $this->fileProcessor->extractText(
                    $filePath,
                    $fileType,
                    $messageFile->getUserId(),
                );

                if ('' !== trim((string) $text)) {
                    $messageFile->setFileText($text);
                    $messageFile->setStatus('processed');
                    $this->logger->info($isVideo ? 'PreProcessor: Video transcribed' : 'PreProcessor: Document parsed', [
                        'file_id' => $messageFile->getId(),
                        'text_length' => strlen($text),
                        'strategy' => $extractMeta['strategy'] ?? 'unknown',
                    ]);
                } else {
                    $messageFile->setStatus('error');
                    $this->logger->warning($isVideo ? 'PreProcessor: Video transcription produced empty text' : 'PreProcessor: Document extraction produced empty text', [
                        'file_id' => $messageFile->getId(),
                        'strategy' => $extractMeta['strategy'] ?? 'unknown',
                    ]);
                }
            } catch (\Throwable $e) {
                $messageFile->setStatus('error');
                $this->logger->error($isVideo ? 'PreProcessor: Video transcription failed' : 'PreProcessor: Document extraction failed', [
                    'file_id' => $messageFile->getId(),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->billFileAnalysis($messageFile, $message, $isVideo ? 'video' : 'document');
        }

        // Audio mit Whisper (or external STT if user configured one)
        elseif (in_array($fileType, self::AUDIO_EXTENSIONS)) {
            $userId = $messageFile->getUserId();
            $useExternal = $this->aiFacade->hasConfiguredSttProvider($userId);

            if (!$useExternal && !$this->whisperService->isAvailable()) {
                $this->logger->warning('PreProcessor: Whisper not available and no external STT configured, skipping', [
                    'file' => basename($fullPath),
                ]);
                $messageFile->setStatus('processed');

                return;
            }

            try {
                $result = $useExternal
                    ? $this->aiFacade->transcribe($fullPath, $userId)
                    : $this->transcribeWithWhisper($fullPath, null);
                if ($result && !empty($result['text'])) {
                    $transcribedText = $result['text'];
                    $messageFile->setFileText($transcribedText);
                    $messageFile->setStatus('processed');

                    // Update message text for better classification
                    // If message text is placeholder like '[Audio message]', replace it with transcription
                    $currentText = $message->getText();
                    if (empty($currentText) || '[Audio message]' === $currentText || '[Audio]' === $currentText) {
                        $message->setText($transcribedText);
                    }

                    $this->logger->info('PreProcessor: Audio transcribed', [
                        'file_id' => $messageFile->getId(),
                        'text_length' => strlen($transcribedText),
                        'language' => $result['language'],
                    ]);

                    $this->billFileAnalysis($messageFile, $message, 'audio');
                }
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Audio transcription failed', [
                    'file_id' => $messageFile->getId(),
                    'error' => $e->getMessage(),
                ]);
                $messageFile->setStatus('error');
            }
        }

        // Image mit Vision AI
        elseif (in_array($fileType, self::IMAGE_EXTENSIONS)) {
            try {
                // Use file owner as context for Vision AI
                $userId = $messageFile->getUserId() ?? 0;
                $text = $this->processImageWithVision($messageFile->getFilePath(), $userId);
                $messageFile->setFileText($text ?? '');
                $messageFile->setStatus('processed');
                $this->logger->info('PreProcessor: Image processed with Vision AI', [
                    'file_id' => $messageFile->getId(),
                    'text_length' => strlen($text ?? ''),
                ]);

                $this->billFileAnalysis($messageFile, $message, 'image');
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Vision AI failed', [
                    'file_id' => $messageFile->getId(),
                    'error' => $e->getMessage(),
                ]);
                $messageFile->setStatus('error');
            }
        }
    }

    /**
     * Bill exactly one FILE_ANALYSIS event per file the user actually used.
     *
     * Issue #887: the chat upload no longer writes a BUSELOG row up front,
     * so the moment of analysis (here, during the streamed message turn)
     * is the correct billing event. RateLimitService::recordFileAnalysisOnce
     * is idempotent on (user_id, file_id) — re-runs of the preprocessor
     * (chunked stream retries, Vue HMR replays in dev, etc.) are no-ops.
     *
     * Failures here MUST NOT block the message from completing — usage
     * recording is best-effort accounting, not a hard prerequisite. We log
     * and swallow.
     */
    private function billFileAnalysis(File $messageFile, Message $message, string $stage): void
    {
        $fileId = $messageFile->getId();
        if (null === $fileId) {
            return;
        }

        $userId = $messageFile->getUserId() ?: $message->getUserId();
        if ($userId <= 0) {
            return;
        }

        $user = $this->userRepository->find($userId);
        if (null === $user) {
            return;
        }

        try {
            $this->rateLimitService->recordFileAnalysisOnce($user, $fileId, [
                'filename' => $messageFile->getFileName(),
                'source' => 'WEB',
                'stage' => $stage,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('PreProcessor: FILE_ANALYSIS recording failed (non-fatal)', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Legacy: Process file attached directly to Message (OLD format).
     */
    private function processFile(Message $message): void
    {
        $filePath = $message->getFilePath();
        $fileType = strtolower($message->getFileType());

        // File existiert lokal?
        $fullPath = $this->uploadsDir.'/'.$filePath;
        if (!file_exists($fullPath)) {
            $this->logger->warning("File not found: {$fullPath}");

            return;
        }

        // Skip extraction if text already exists (e.g., from WhatsApp FileProcessor)
        // This prevents overwriting robust extraction (with vision fallback) with simple Tika-only extraction
        if (!empty($message->getFileText())) {
            $this->logger->info('PreProcessor: File text already extracted, skipping re-extraction', [
                'file' => basename($fullPath),
                'type' => $fileType,
                'text_length' => strlen($message->getFileText()),
            ]);

            return;
        }

        // Parse File mit Tika (für PDFs, DOCX, etc.)
        if (in_array($fileType, self::DOCUMENT_EXTENSIONS)) {
            $this->logger->info('PreProcessor: Parsing document with Tika', [
                'file' => basename($fullPath),
                'type' => $fileType,
            ]);

            $text = $this->parseWithTika($fullPath);
            if ($text) {
                $message->setFileText($text);
                $this->logger->info('PreProcessor: Document parsed successfully', [
                    'text_length' => strlen($text),
                ]);
            }
        }

        // Audio mit Whisper (or external STT if user configured one)
        if (in_array($fileType, self::AUDIO_EXTENSIONS)) {
            $userId = $message->getUserId();
            $useExternal = $this->aiFacade->hasConfiguredSttProvider($userId);

            if (!$useExternal && !$this->whisperService->isAvailable()) {
                $this->logger->warning('PreProcessor: Whisper not available and no external STT configured, skipping', [
                    'file' => basename($fullPath),
                ]);

                return;
            }

            $this->logger->info('PreProcessor: Transcribing audio', [
                'file' => basename($fullPath),
                'type' => $fileType,
                'strategy' => $useExternal ? 'external_api' : 'whisper_local',
            ]);

            try {
                $result = $useExternal
                    ? $this->aiFacade->transcribe($fullPath, $userId)
                    : $this->transcribeWithWhisper($fullPath, $message->getLanguage());
                if ($result && !empty($result['text'])) {
                    $transcribedText = $result['text'];
                    $message->setFileText($transcribedText);

                    // Update message text for better classification
                    // If message text is placeholder like '[Audio message]', replace it with transcription
                    $currentText = $message->getText();
                    if (empty($currentText) || '[Audio message]' === $currentText || '[Audio]' === $currentText) {
                        $message->setText($transcribedText);
                    }

                    // Update detected language if different
                    if ('unknown' !== $result['language'] && $result['language'] !== $message->getLanguage()) {
                        $message->setLanguage($result['language']);
                    }

                    $this->logger->info('PreProcessor: Audio transcribed successfully', [
                        'text_length' => strlen($transcribedText),
                        'detected_language' => $result['language'],
                        'duration' => $result['duration'].'s',
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Audio transcription failed', [
                    'file' => basename($fullPath),
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the entire process, just skip transcription
            }
        }

        // Video: transcribe the audio track AND describe a representative key
        // frame (issues #722 + #983). FileProcessor::extractText() owns the
        // combined ffmpeg + Whisper + Vision pipeline, so the legacy single-file
        // path reuses it instead of duplicating logic. This is the single
        // canonical video path — do NOT add a second video branch below.
        if (in_array($fileType, self::VIDEO_EXTENSIONS)) {
            $this->logger->info('PreProcessor: Transcribing video', [
                'file' => basename($fullPath),
                'type' => $fileType,
            ]);

            try {
                [$text] = $this->fileProcessor->extractText(
                    $filePath,
                    $fileType,
                    $message->getUserId(),
                );

                if ('' !== trim((string) $text)) {
                    $message->setFileText($text);

                    $currentText = $message->getText();
                    if (empty($currentText) || '[Video message]' === $currentText || '[Video]' === $currentText) {
                        $message->setText($text);
                    }

                    $this->logger->info('PreProcessor: Video transcribed successfully', [
                        'text_length' => strlen($text),
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Video transcription failed', [
                    'file' => basename($fullPath),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Image mit Vision AI (wenn Tika nichts extrahiert hat)
        if (in_array($fileType, self::IMAGE_EXTENSIONS)) {
            $this->logger->info('PreProcessor: Processing image with Vision AI', [
                'file' => basename($fullPath),
                'type' => $fileType,
            ]);

            try {
                $text = $this->processImageWithVision($message->getFilePath(), $message->getUserId());
                $message->setFileText($text ?? '');
                $this->logger->info('PreProcessor: Image processed successfully', [
                    'text_length' => strlen($text ?? ''),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('PreProcessor: Vision AI failed', [
                    'file' => basename($fullPath),
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the entire process, just skip vision analysis
            }
        }
    }

    /**
     * Parse File mit Apache Tika (via TikaClient with correct MIME type).
     */
    private function parseWithTika(string $filePath): ?string
    {
        try {
            $mime = mime_content_type($filePath) ?: null;
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            if (isset(self::OFFICE_EXT_TO_MIME[$ext])) {
                if (!$mime || 'application/zip' === $mime || 'application/octet-stream' === $mime) {
                    $mime = self::OFFICE_EXT_TO_MIME[$ext];
                }
            }

            [$text, $meta] = $this->tikaClient->extractText($filePath, $mime);

            if ($text) {
                return trim($text);
            }

            if (!empty($meta['error'])) {
                $this->logger->warning('Tika extraction returned no text', ['meta' => $meta]);
            }
        } catch (\Exception $e) {
            $this->logger->error("Tika parsing failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Transcribe audio file with Whisper.
     */
    private function transcribeWithWhisper(string $filePath, ?string $languageHint = null): ?array
    {
        try {
            $options = [];

            // Use language hint if available (speeds up transcription)
            if ($languageHint && 2 === strlen($languageHint)) {
                $options['language'] = $languageHint;
            }

            // Use base model by default (good balance of speed/accuracy)
            $options['model'] = 'base';

            return $this->whisperService->transcribe($filePath, $options);
        } catch (\Exception $e) {
            $this->logger->error("Whisper transcription failed: {$e->getMessage()}", [
                'file' => basename($filePath),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Process image with Vision AI.
     */
    private function processImageWithVision(string $relativePath, int $userId): ?string
    {
        try {
            $prompt = 'Extract all text visible in this image. '
                .'Return only the text exactly as it appears, preserving line breaks. '
                .'Do not add descriptions or commentary. '
                .'If no text is visible, return an empty string.';

            $result = $this->aiFacade->analyzeImage($relativePath, $prompt, $userId);
            $text = trim($result['content'] ?? '');
            if ('' !== $text && str_starts_with(strtolower($text), 'test image description:')) {
                $text = preg_replace('/^test image description:\s*/i', '', $text);
                $text = trim($text);
            }

            return '' !== $text ? $text : null;
        } catch (\Exception $e) {
            $this->logger->error("Vision AI analysis failed: {$e->getMessage()}", [
                'file' => basename($relativePath),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function notify(?callable $callback, string $status, string $message): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ]);
        }
    }
}
