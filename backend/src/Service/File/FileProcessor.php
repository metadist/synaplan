<?php

namespace App\Service\File;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Service\WhisperService;
use Psr\Log\LoggerInterface;

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
class FileProcessor
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

    private const AUDIO_EXTENSIONS = [
        'ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm', 'aac', 'wma', 'amr',
        'mp4', 'avi', 'mov', 'mkv', 'mpeg', 'mpg',
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
        private LoggerInterface $logger,
        private string $uploadDir,
        private int $tikaMinLength,
        private float $tikaMinEntropy,
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

        // Strategy 3: Audio/Video files -> Whisper.cpp
        if ($this->isAudioExtension($ext)) {
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
     * Check if file extension is audio/video.
     */
    private function isAudioExtension(string $ext): bool
    {
        return in_array($ext, self::AUDIO_EXTENSIONS, true);
    }

    /**
     * Extract text from audio/video file using Whisper.cpp with external API fallback.
     *
     * Strategy order:
     * 1. Local Whisper.cpp (fast, free, no external dependencies)
     * 2. External OpenAI Whisper API (fallback when local not available)
     *
     * @param string $absolutePath Full path to the audio file
     * @param array  $baseMeta     Base metadata for logging
     *
     * @return array [extractedText, meta]
     */
    private function extractFromAudio(string $absolutePath, array $baseMeta, ?int $userId = null): array
    {
        // Try local Whisper.cpp first (preferred)
        if ($this->whisperService->isAvailable()) {
            $this->logger->info('FileProcessor: Transcribing audio with local Whisper', $baseMeta);

            try {
                $result = $this->whisperService->transcribe($absolutePath);
                $text = $result['text'] ?? '';
                $text = $this->textCleaner->clean($text);

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
            } catch (\Throwable $e) {
                $this->logger->warning('FileProcessor: Local Whisper failed, trying external API', [
                    'error' => $e->getMessage(),
                ] + $baseMeta);
                // Fall through to external API
            }
        }

        // Try external speech-to-text provider (user's configured provider or OpenAI)
        return $this->extractFromAudioExternal($absolutePath, $baseMeta, $userId);
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

        // Use AiFacade::transcribe() which respects user's model configuration
        try {
            $result = $this->aiFacade->transcribe($absolutePath, $userId);

            $text = $result['text'] ?? '';
            $text = $this->textCleaner->clean($text);

            $this->logger->info('FileProcessor: External API transcription success', [
                'strategy' => 'whisper_api',
                'provider' => $result['provider'] ?? 'unknown',
                'bytes' => strlen($text),
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
        }
    }
}
