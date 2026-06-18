<?php

declare(strict_types=1);

namespace App\Service\File;

use App\AI\Service\AiFacade;
use Psr\Log\LoggerInterface;

/**
 * Visual analysis of video files.
 *
 * Issue #983: a video's audio track is transcribed by the existing
 * speech-to-text pipeline (see {@see FileProcessor::extractFromVideo()}),
 * but a silent — or mostly visual — video still needs a description of
 * what is on screen. This service extracts a representative key frame
 * (reusing the ffmpeg-backed {@see ThumbnailService}) and runs it through
 * the Vision AI so the chat model receives the visual context too.
 *
 * It is intentionally narrow and fully fault tolerant: every failure mode
 * (missing ffmpeg, no extractable frame, vision provider error) returns
 * null so the caller can fall back to the transcript-only result instead
 * of failing the whole message.
 */
final readonly class VideoAnalysisService
{
    private const FRAME_DESCRIPTION_PROMPT =
        'This is a still frame captured from a video. '
        .'Describe what is visible: the setting, the main objects, any people and what they appear to be doing, '
        .'and transcribe any clearly legible on-screen text. '
        .'Be concise but informative. If the frame is essentially empty or black, say so briefly.';

    public function __construct(
        private ThumbnailService $thumbnailService,
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Produce a short visual description of a representative frame of the
     * given video, or null when no usable description can be generated.
     *
     * @param string   $videoRelativePath relative path to the video from the upload dir
     * @param int|null $userId            owner used for Vision AI provider selection
     */
    public function describeKeyFrame(string $videoRelativePath, ?int $userId = null): ?string
    {
        // Reuse the persistent player thumbnail when it already exists,
        // otherwise generate one. Both live inside the upload tree so the
        // relative path can be handed straight to AiFacade::analyzeImage().
        $frameRelativePath = $this->thumbnailService->getThumbnailIfExists($videoRelativePath)
            ?? $this->thumbnailService->generateThumbnail($videoRelativePath);

        if (null === $frameRelativePath) {
            $this->logger->warning('VideoAnalysisService: Could not extract a frame for visual analysis', [
                'video' => $videoRelativePath,
            ]);

            return null;
        }

        try {
            $result = $this->aiFacade->analyzeImage($frameRelativePath, self::FRAME_DESCRIPTION_PROMPT, $userId);
            $text = trim((string) ($result['content'] ?? ''));

            // The deterministic TestProvider prefixes its output — strip it
            // so the description reads naturally (same convention used by
            // FileProcessor/MessagePreProcessor image extraction).
            if ('' !== $text && str_starts_with(strtolower($text), 'test image description:')) {
                $text = trim((string) preg_replace('/^test image description:\s*/i', '', $text));
            }

            if ('' === $text) {
                return null;
            }

            $this->logger->info('VideoAnalysisService: Generated visual description', [
                'video' => $videoRelativePath,
                'frame' => $frameRelativePath,
                'length' => strlen($text),
            ]);

            return $text;
        } catch (\Throwable $e) {
            $this->logger->error('VideoAnalysisService: Vision analysis of key frame failed', [
                'video' => $videoRelativePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
