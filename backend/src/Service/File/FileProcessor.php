<?php

namespace App\Service\File;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Service\WhisperService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Universal File Processor.
 *
 * Extracts text from files using multiple strategies:
 * 1. Native (plain text files)
 * 2. Tika (documents: PDF, DOCX, XLSX, etc.)
 * 3. Rasterize + Vision AI (fallback for PDFs with low-quality Tika extraction)
 * 4. Vision AI (images)
 * 5. Speech-to-Text (audio/video files via Whisper.cpp)
 *
 * Strategy from legacy: Native -> Tika -> Rasterize+Vision fallback
 */
final readonly class FileProcessor
{
    private const PLAIN_TEXT_MIMES = [
        'text/plain',
        'text/markdown',
        'text/x-markdown',
        'text/csv',
        'text/html',
    ];

    private const PDF_MIMES = [
        'application/pdf',
        'application/x-pdf',
    ];

    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private const TRANSCRIBABLE_MEDIA_EXTENSIONS = [
        'ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm', 'aac', 'wma', 'amr',
        'mp4', 'avi', 'mov', 'mkv', 'mpeg', 'mpg',
    ];

    /**
     * Video container formats. They get both audio-track transcription AND a
     * visual key-frame description (issue #983), and their audio track is
     * always stripped before being sent to an external STT API — even though
     * some containers (mp4, mkv) appear in API_SUPPORTED_AUDIO_FORMATS. A full
     * video file can easily be 40+ MB while the extracted audio-only payload is
     * a few MB; every hosted Whisper endpoint enforces a ~25 MB ceiling.
     *
     * `webm` is deliberately excluded: browser/WhatsApp voice notes are
     * recorded as audio/webm, so it stays on the audio-only transcription path
     * to avoid a needless vision call.
     */
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'mpeg', 'mpg'];

    /**
     * Audio formats supported by external APIs (OpenAI/Groq Whisper).
     * Formats not in this list need to be converted before sending.
     *
     * @see https://console.groq.com/docs/speech-to-text
     * @see https://platform.openai.com/docs/api-reference/audio
     */
    private const API_SUPPORTED_AUDIO_FORMATS = [
        'flac', 'mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'ogg', 'wav', 'webm',
    ];

    private const OFFICE_EXT_TO_MIME = [
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html',
    ];

    public function __construct(
        private TikaClient $tikaClient,
        private PdfRasterizer $rasterizer,
        private TextCleaner $textCleaner,
        private AiFacade $aiFacade,
        private WhisperService $whisperService,
        private VideoAnalysisService $videoAnalysisService,
        private LoggerInterface $logger,
        private string $uploadDir,
        private int $tikaMinLength,
        private float $tikaMinEntropy,
        private string $ffmpegBinary = '/usr/bin/ffmpeg',
    ) {
    }

    /**
     * Extract text from a file.
     *
     * @param string   $relativePath  Relative path to file (from upload dir)
     * @param string   $fileExtension File extension (e.g. 'pdf', 'docx')
     * @param int|null $userId        User ID for Vision AI fallback
     *
     * @return array [extractedText, meta] where meta contains strategy, mime, ext, etc
     */
    public function extractText(string $relativePath, string $fileExtension, ?int $userId = null): array
    {
        $absolutePath = $this->resolveAbsolutePath($relativePath);
        $mime = mime_content_type($absolutePath) ?: '';
        $ext = strtolower($fileExtension);
        $mime = $this->ensureOfficeMime($mime, $ext);

        $meta = [
            'mime' => $mime,
            'ext' => $ext,
            'file' => basename($absolutePath),
        ];

        $this->logger->info('FileProcessor: Starting extraction', $meta);

        // Strategy 1: Native plain text
        if ($this->isPlainTextMime($mime)) {
            $text = @file_get_contents($absolutePath) ?: '';
            $text = $this->textCleaner->clean($text);

            $this->logger->info('FileProcessor: Native text extraction', [
                'strategy' => 'native_text',
                'bytes' => strlen($text),
            ]);

            return [$text, ['strategy' => 'native_text'] + $meta];
        }

        // Strategy 2: Image files -> Vision AI
        if ($this->isImageMime($mime)) {
            return $this->extractFromImage($relativePath, $userId, $meta);
        }

        // Strategy 3: Audio/Video files -> Whisper transcription
        if ($this->isTranscribableMedia($ext)) {
            // Real videos additionally get a visual key-frame description so
            // silent or mostly-visual clips still produce usable content.
            if ($this->isVideo($ext)) {
                return $this->extractFromVideo($relativePath, $absolutePath, $meta, $userId);
            }

            return $this->extractFromAudio($absolutePath, $meta, $userId);
        }

        // Strategy 4: Tika for documents
        if (!$this->tikaClient->isEnabled()) {
            // Tika disabled: Only support PDFs via vision
            if ($this->isPdfMime($mime) || 'pdf' === $ext) {
                return $this->extractFromPdfViaVision($absolutePath, $userId, $meta);
            }

            $this->logger->warning('FileProcessor: Tika disabled, cannot extract', $meta);

            return ['', ['strategy' => 'tika_disabled'] + $meta];
        }

        // Try Tika first for documents
        [$tikaText, $tikaMeta] = $this->tikaClient->extractText($absolutePath, $mime);

        if (is_string($tikaText)) {
            $tikaText = $this->textCleaner->clean($tikaText);
            $isPdf = $this->isPdfMime($mime) || 'pdf' === $ext;

            // Check quality for PDFs
            $lowQuality = $isPdf
                ? $this->textCleaner->isLowQuality($tikaText, $this->tikaMinLength, $this->tikaMinEntropy)
                : false;

            if (mb_strlen(trim($tikaText)) > 0 && !$lowQuality) {
                $this->logger->info('FileProcessor: Tika extraction success', [
                    'strategy' => 'tika',
                    'bytes' => strlen($tikaText),
                ]);

                return [$tikaText, ['strategy' => 'tika'] + $meta + $tikaMeta];
            }

            // Low-quality Tika output for PDF -> fallback to vision
            if ($isPdf) {
                $this->logger->info('FileProcessor: Tika quality too low, trying vision fallback');

                return $this->extractFromPdfViaVision($absolutePath, $userId, $meta);
            }
        }

        // Tika failed or produced unusable output
        $this->logger->warning('FileProcessor: Extraction failed', ['strategy' => 'tika_failed'] + $meta);

        return ['', ['strategy' => 'tika_failed'] + $meta];
    }

    /**
     * Extract text from PDF using rasterization + Vision AI.
     */
    private function extractFromPdfViaVision(string $absolutePath, ?int $userId, array $baseMeta): array
    {
        $images = $this->rasterizer->pdfToPng($absolutePath);

        if (empty($images)) {
            $this->logger->warning('FileProcessor: PDF rasterization failed');

            return ['', ['strategy' => 'rasterize_failed'] + $baseMeta];
        }

        $this->logger->info('FileProcessor: PDF rasterized', ['pages' => count($images)]);

        $fullText = $this->aggregateVisionResults($images, $userId);
        $fullText = $this->textCleaner->clean($fullText);

        if (mb_strlen(trim($fullText)) > 0) {
            $this->logger->info('FileProcessor: Vision extraction success', [
                'strategy' => 'rasterize_vision',
                'pages' => count($images),
                'bytes' => strlen($fullText),
            ]);

            return [$fullText, [
                'strategy' => 'rasterize_vision',
                'pages' => count($images),
                'engine' => $this->rasterizer->getLastEngine(),
            ] + $baseMeta];
        }

        return ['', ['strategy' => 'rasterize_vision'] + $baseMeta];
    }

    /**
     * Extract text from image using Vision AI.
     */
    private function extractFromImage(string $relativePath, ?int $userId, array $baseMeta): array
    {
        $this->logger->info('FileProcessor: Using Vision AI for image');

        try {
            // Use Vision AI provider
            $prompt = 'Extract all visible text from this image. '
                .'Return only the text exactly as it appears, preserving line breaks. '
                .'Do not add descriptions or commentary. '
                .'If no text is present, return empty string.';
            $result = $this->aiFacade->analyzeImage($relativePath, $prompt, $userId);

            $text = $result['content'] ?? '';
            $text = $this->textCleaner->clean($text);
            if (0 === stripos($text, 'test image description:')) {
                $text = preg_replace('/^test image description:\s*/i', '', $text);
            }

            $this->logger->info('FileProcessor: Vision AI extraction', [
                'strategy' => 'vision_ai',
                'bytes' => strlen($text),
            ]);

            return [$text, ['strategy' => 'vision_ai', 'provider' => $result['provider'] ?? 'unknown'] + $baseMeta];
        } catch (\Throwable $e) {
            $this->logger->error('FileProcessor: Vision AI failed', [
                'error' => $e->getMessage(),
            ]);

            return ['', ['strategy' => 'vision_failed', 'error' => $e->getMessage()] + $baseMeta];
        }
    }

    /**
     * Aggregate Vision AI results from multiple images (PDF pages).
     */
    private function aggregateVisionResults(array $imagePaths, ?int $userId): string
    {
        $fullText = '';

        foreach ($imagePaths as $imgPath) {
            $relativePath = $this->absoluteToRelative($imgPath);

            try {
                $prompt = 'Extract every piece of written text from this PDF page. '
                    .'Return only the text exactly as it appears, preserving line breaks. '
                    .'Do not provide any descriptions. '
                    .'If no text is present, return an empty string.';
                $result = $this->aiFacade->analyzeImage($relativePath, $prompt, $userId);
                $text = $result['content'] ?? '';

                if (!empty($text)) {
                    $text = trim($text);
                    $text = preg_replace('/^test image description:\s*/i', '', $text);
                    if (strlen($fullText) > 0) {
                        $fullText .= "\n\n";
                    }
                    $fullText .= $text;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FileProcessor: Vision AI failed for page', [
                    'image' => basename($imgPath),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return trim($fullText);
    }

    /**
     * Resolve absolute file path from relative path.
     */
    private function resolveAbsolutePath(string $relativePath): string
    {
        $uploadBase = rtrim($this->uploadDir, '/').'/';
        $candidates = [
            $uploadBase.$relativePath,
            $relativePath, // Already absolute?
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        $this->logger->warning('FileProcessor: File not found', [
            'relative' => $relativePath,
            'tried' => $candidates,
        ]);

        return $candidates[0]; // Return first candidate even if not found
    }

    /**
     * Convert absolute path to relative (from upload dir).
     */
    private function absoluteToRelative(string $absolutePath): string
    {
        $uploadBase = rtrim($this->uploadDir, '/').'/';

        if (str_starts_with($absolutePath, $uploadBase)) {
            return substr($absolutePath, strlen($uploadBase));
        }

        return basename($absolutePath);
    }

    /**
     * Check if MIME type is plain text.
     */
    private function isPlainTextMime(string $mime): bool
    {
        return in_array($mime, self::PLAIN_TEXT_MIMES, true);
    }

    /**
     * Check if MIME type is PDF.
     */
    private function isPdfMime(string $mime): bool
    {
        return in_array($mime, self::PDF_MIMES, true);
    }

    /**
     * Check if MIME type is image.
     */
    private function isImageMime(string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIMES, true);
    }

    /**
     * Ensure correct MIME type for Office documents
     * (Some environments report XLSX/DOCX as application/zip).
     */
    private function ensureOfficeMime(string $mime, string $ext): string
    {
        if (isset(self::OFFICE_EXT_TO_MIME[$ext])) {
            if (empty($mime) || 'application/zip' === $mime || 'application/octet-stream' === $mime) {
                return self::OFFICE_EXT_TO_MIME[$ext];
            }
        }

        return $mime;
    }

    /**
     * Check if the file extension is a media type from which audio can be extracted for transcription.
     */
    private function isTranscribableMedia(string $ext): bool
    {
        return in_array($ext, self::TRANSCRIBABLE_MEDIA_EXTENSIONS, true);
    }

    /**
     * Check if the extension is a video format that should also receive a
     * visual key-frame description on top of audio transcription.
     */
    private function isVideo(string $ext): bool
    {
        return in_array($ext, self::VIDEO_EXTENSIONS, true);
    }

    /**
     * Extract text from a video file: transcribe its audio track (reusing
     * the full local/external speech-to-text pipeline) AND describe a
     * representative key frame via Vision AI (issue #983).
     *
     * Audio is stripped from the video container before being sent to any
     * external STT API. This prevents external providers (Groq, OpenAI) from
     * rejecting the request with "file too large" — a WhatsApp video is
     * typically 30–80 MB while its audio-only track is 2–4 MB (issue #983).
     *
     * The two results are merged into one labelled block so the chat model
     * can both "read" what was said and "see" what was shown. Either part
     * may be empty (a silent clip yields visual-only; a video the vision
     * provider cannot read yields transcript-only); only when BOTH are
     * empty does extraction count as failed.
     *
     * @param string   $relativePath relative path from the upload dir (for Vision AI)
     * @param string   $absolutePath resolved absolute path (for audio extraction)
     * @param array    $baseMeta     base metadata for logging
     * @param int|null $userId       owner used for provider selection
     *
     * @return array [extractedText, meta]
     */
    private function extractFromVideo(string $relativePath, string $absolutePath, array $baseMeta, ?int $userId = null): array
    {
        // 1. Strip video stream before calling the STT pipeline so that external
        //    APIs never receive the full container file (issue #983).
        $audioOnlyPath = $this->extractAudioTrack($absolutePath);
        $audioInputPath = $audioOnlyPath ?? $absolutePath;

        $audioMeta = [];

        try {
            [$transcript, $audioMeta] = $this->extractFromAudio($audioInputPath, $baseMeta, $userId);
        } finally {
            if (null !== $audioOnlyPath && file_exists($audioOnlyPath)) {
                @unlink($audioOnlyPath);
            }
        }

        $transcript = trim((string) $transcript);

        // 2. Representative frame -> visual description (fault tolerant).
        $visual = trim((string) $this->videoAnalysisService->describeKeyFrame($relativePath, $userId));

        $parts = [];
        if ('' !== $visual) {
            $parts[] = "[Visual description]\n".$visual;
        }
        if ('' !== $transcript) {
            $parts[] = "[Audio transcript]\n".$transcript;
        }

        $combined = trim(implode("\n\n", $parts));

        $strategy = match (true) {
            '' !== $visual && '' !== $transcript => 'video_transcript_vision',
            '' !== $transcript => 'video_transcript',
            '' !== $visual => 'video_vision',
            default => 'video_failed',
        };

        $meta = [
            'strategy' => $strategy,
            'has_transcript' => '' !== $transcript,
            'has_visual' => '' !== $visual,
            'audio_strategy' => $audioMeta['strategy'] ?? null,
        ] + $baseMeta;

        if ('' === $combined) {
            $this->logger->warning('FileProcessor: Video produced neither transcript nor visual description', $baseMeta);
        } else {
            $this->logger->info('FileProcessor: Video extraction complete', [
                'strategy' => $strategy,
                'bytes' => strlen($combined),
            ] + $baseMeta);
        }

        return [$combined, $meta];
    }

    /**
     * Extract audio track from a video file into a compact MP3 for STT.
     *
     * Runs ffmpeg with -vn to strip the video stream so that external STT
     * providers never receive the full container (issue #983: WhatsApp videos
     * are typically 30–80 MB but the audio-only track is 2–4 MB).
     *
     * @return string|null absolute path to temp MP3, or null when ffmpeg is
     *                     unavailable or the extraction fails (caller falls
     *                     back to the original video path)
     */
    private function extractAudioTrack(string $videoPath): ?string
    {
        if (!file_exists($this->ffmpegBinary) || !is_executable($this->ffmpegBinary)) {
            $this->logger->debug('FileProcessor: FFmpeg unavailable, skipping audio-track extraction', [
                'video' => basename($videoPath),
            ]);

            return null;
        }

        $tempPath = sys_get_temp_dir().'/audio_track_'.uniqid().'.mp3';

        $process = new Process([
            $this->ffmpegBinary,
            '-i', $videoPath,
            '-vn',          // strip video stream
            '-ar', '16000', // 16 kHz (optimal for speech recognition)
            '-ac', '1',     // mono
            '-b:a', '64k',  // 64 kbps (good balance of size and speech quality)
            '-f', 'mp3',
            '-y',
            $tempPath,
        ]);
        $process->setTimeout(120);

        try {
            $process->run();

            if (!$process->isSuccessful() || !file_exists($tempPath) || filesize($tempPath) < 100) {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
                $this->logger->warning('FileProcessor: Audio track extraction failed', [
                    'video' => basename($videoPath),
                    'exit_code' => $process->getExitCode(),
                ]);

                return null;
            }

            $this->logger->info('FileProcessor: Extracted audio track from video', [
                'video' => basename($videoPath),
                'video_size' => filesize($videoPath),
                'audio_size' => filesize($tempPath),
            ]);

            return $tempPath;
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            $this->logger->warning('FileProcessor: Audio track extraction exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Public static variant used by FileUploadService to distinguish a
     * transcription failure (audio/video → empty result is an error) from a
     * legitimate no-content result (e.g. a blank PDF has no extractable text).
     */
    public static function isTranscribableMediaExtension(string $ext): bool
    {
        return in_array(strtolower($ext), self::TRANSCRIBABLE_MEDIA_EXTENSIONS, true);
    }

    /**
     * Extract text from audio/video file using Whisper.cpp with external API fallback.
     *
     * Strategy when an external STT provider is configured:
     *   1. External provider (faster, handles many languages)
     *   2. Local Whisper.cpp if external returns empty or fails (fallback) — this
     *      also rescues oversized files already reduced by extractAudioTrack()
     *      whose audio the external provider still could not handle (issue #983)
     *
     * Strategy when no external provider is configured:
     *   1. Local Whisper.cpp (free, no network dependency)
     *   2. External provider as last resort
     *
     * @param string   $absolutePath Full path to the audio/video file
     * @param array    $baseMeta     Base metadata for logging
     * @param int|null $userId       User ID for provider selection
     *
     * @return array [extractedText, meta]
     */
    private function extractFromAudio(string $absolutePath, array $baseMeta, ?int $userId = null): array
    {
        if ($this->aiFacade->hasConfiguredSttProvider($userId)) {
            $this->logger->info('FileProcessor: Trying external STT provider first', $baseMeta);
            $externalResult = $this->extractFromAudioExternal($absolutePath, $baseMeta, $userId);

            if ('' !== trim((string) ($externalResult[0] ?? ''))) {
                return $externalResult;
            }

            // External returned empty (provider error, file too large, etc.) —
            // fall back to local Whisper before giving up (issue #983).
            $this->logger->info('FileProcessor: External STT returned empty, falling back to local Whisper', $baseMeta);
            $localResult = $this->transcribeLocally($absolutePath, $baseMeta);
            if (null !== $localResult) {
                return $localResult;
            }

            return $externalResult;
        }

        // No external provider configured — prefer local Whisper, fall back to external.
        $localResult = $this->transcribeLocally($absolutePath, $baseMeta);
        if (null !== $localResult) {
            return $localResult;
        }

        return $this->extractFromAudioExternal($absolutePath, $baseMeta, $userId);
    }

    /**
     * Attempt transcription with local Whisper.cpp.
     *
     * Returns the [text, meta] pair on success, or null when Whisper is
     * unavailable, throws, or produces an empty transcript.  Callers treat
     * null as "not available — try something else".
     *
     * @param string $absolutePath Full path to the audio/video file
     * @param array  $baseMeta     Base metadata for logging
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function transcribeLocally(string $absolutePath, array $baseMeta): ?array
    {
        if (!$this->whisperService->isAvailable()) {
            return null;
        }

        $this->logger->info('FileProcessor: Transcribing with local Whisper', $baseMeta);

        try {
            $result = $this->whisperService->transcribe($absolutePath);
            $text = $this->textCleaner->clean($result['text'] ?? '');

            if ('' !== trim($text)) {
                $this->logger->info('FileProcessor: Local Whisper transcription success', [
                    'strategy' => 'whisper_local',
                    'bytes' => strlen($text),
                    'language' => $result['language'] ?? 'unknown',
                    'duration' => $result['duration'] ?? 0,
                ]);

                return [$text, [
                    'strategy' => 'whisper_local',
                    'language' => $result['language'] ?? 'unknown',
                    'duration' => $result['duration'] ?? 0,
                    'model' => $result['model'] ?? 'base',
                ] + $baseMeta];
            }

            $this->logger->warning('FileProcessor: Local Whisper returned empty text', $baseMeta);
        } catch (\Throwable $e) {
            $this->logger->warning('FileProcessor: Local Whisper failed', [
                'error' => $e->getMessage(),
            ] + $baseMeta);
        }

        return null;
    }

    /**
     * Extract text from audio using external API (user's configured provider or OpenAI).
     *
     * Used as fallback when local Whisper.cpp is not available or fails.
     * Uses AiFacade::transcribe() to respect user's model configuration (e.g., Groq whisper).
     *
     * @param string   $absolutePath Full path to the audio file
     * @param array    $baseMeta     Base metadata for logging
     * @param int|null $userId       User ID for provider selection
     *
     * @return array [extractedText, meta]
     */
    private function extractFromAudioExternal(string $absolutePath, array $baseMeta, ?int $userId = null): array
    {
        $this->logger->info('FileProcessor: Transcribing audio with external API', [
            'user_id' => $userId,
        ] + $baseMeta);

        // Convert audio if format is not supported by external APIs
        $processedPath = $this->ensureApiCompatibleFormat($absolutePath);
        $needsCleanup = $processedPath !== $absolutePath;

        // Use AiFacade::transcribe() which respects user's model configuration
        try {
            $result = $this->aiFacade->transcribe($processedPath, $userId);

            $text = $result['text'] ?? '';
            $text = $this->textCleaner->clean($text);

            $this->logger->info('FileProcessor: External API transcription success', [
                'strategy' => 'whisper_api',
                'provider' => $result['provider'] ?? 'unknown',
                'bytes' => strlen($text),
                'converted' => $needsCleanup,
            ]);

            return [$text, [
                'strategy' => 'whisper_api',
                'provider' => $result['provider'] ?? 'unknown',
            ] + $baseMeta];
        } catch (ProviderException $e) {
            $this->logger->error('FileProcessor: External API transcription failed (provider error)', [
                'error' => $e->getMessage(),
            ] + $baseMeta);

            return ['', [
                'strategy' => 'audio_api_failed',
                'error' => $e->getMessage(),
            ] + $baseMeta];
        } catch (\Throwable $e) {
            $this->logger->error('FileProcessor: External API transcription failed', [
                'error' => $e->getMessage(),
            ] + $baseMeta);

            return ['', [
                'strategy' => 'audio_api_failed',
                'error' => $e->getMessage(),
            ] + $baseMeta];
        } finally {
            // Cleanup temporary converted file
            if ($needsCleanup && file_exists($processedPath)) {
                @unlink($processedPath);
            }
        }
    }

    /**
     * Ensure audio file is in a format supported by external APIs.
     *
     * WhatsApp and other sources may send audio in formats not supported
     * by OpenAI/Groq Whisper APIs (e.g., AMR, 3GP). This method converts
     * unsupported formats to MP3 using FFmpeg.
     *
     * Note: video files (.mp4 etc.) reach this method only in the pure-audio
     * path (no video extension). For video files the audio track is stripped
     * earlier in extractAudioTrack(), so by the time we arrive here the input
     * is already a compact MP3, and this method is a no-op.
     *
     * @param string $absolutePath Full path to the audio file
     *
     * @return string Path to the compatible file (original or converted)
     */
    private function ensureApiCompatibleFormat(string $absolutePath): string
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        // Video files must always be converted to an audio-only MP3 even when
        // their container extension (mp4, mkv…) is listed in
        // API_SUPPORTED_AUDIO_FORMATS.  The full video stream easily exceeds
        // the ~25 MB ceiling enforced by Groq, OpenAI, and every other hosted
        // Whisper endpoint; the extracted audio-only payload is typically just
        // a few MB.  The -vn flag in the ffmpeg call below strips the video
        // track so the conversion applies universally to all future providers.
        $isVideo = in_array($ext, self::VIDEO_EXTENSIONS, true);

        if (!$isVideo && in_array($ext, self::API_SUPPORTED_AUDIO_FORMATS, true)) {
            return $absolutePath;
        }

        // Check if FFmpeg is available
        if (!file_exists($this->ffmpegBinary) || !is_executable($this->ffmpegBinary)) {
            $this->logger->warning('FileProcessor: FFmpeg not available, cannot convert audio', [
                'format' => $ext,
                'ffmpeg' => $this->ffmpegBinary,
            ]);

            return $absolutePath; // Try anyway, API will reject if unsupported
        }

        // Convert to MP3 (universally supported, good balance of size/quality)
        $tempPath = sys_get_temp_dir().'/audio_convert_'.uniqid().'.mp3';

        $this->logger->info('FileProcessor: Converting audio for API compatibility', [
            'from' => $ext,
            'to' => 'mp3',
            'original' => basename($absolutePath),
        ]);

        $process = new Process([
            $this->ffmpegBinary,
            '-i', $absolutePath,
            '-vn',                    // No video (extract audio only)
            '-ar', '16000',           // 16kHz sample rate (optimal for speech)
            '-ac', '1',               // Mono
            '-b:a', '64k',            // 64kbps bitrate (good for speech)
            '-f', 'mp3',              // Force MP3 format
            '-y',                     // Overwrite output
            $tempPath,
        ]);

        $process->setTimeout(120); // 2 minutes max

        try {
            $process->run();

            if (!$process->isSuccessful() || !file_exists($tempPath)) {
                $this->logger->warning('FileProcessor: Audio conversion failed', [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $process->getErrorOutput(),
                ]);

                // Clean up partial temp file if it exists
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }

                return $absolutePath; // Fallback to original
            }

            $this->logger->info('FileProcessor: Audio converted successfully', [
                'original_size' => filesize($absolutePath),
                'converted_size' => filesize($tempPath),
            ]);

            return $tempPath;
        } catch (\Throwable $e) {
            $this->logger->warning('FileProcessor: Audio conversion exception', [
                'error' => $e->getMessage(),
            ]);

            // Clean up partial temp file if it exists
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return $absolutePath;
        }
    }
}
