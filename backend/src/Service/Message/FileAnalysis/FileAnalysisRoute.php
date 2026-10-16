<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

/**
 * Which path the attached files travel down. Returns a `kind` discriminator
 * plus the bucket that path consumes.
 *
 * Priority rules — a partial bundle is never analysed, because acting on
 * whichever file was ready first silently drops the others (#978, PR #986):
 *  1. Any audio still being transcribed → `audio_transcription_pending`.
 *  2. Any audio finished without a transcript → `audio_transcription_failed`,
 *     with copy that does not promise a retry can work (#1908).
 *  3. Any video still being transcribed → `video_transcription_pending` (#722).
 *  4. Any document still being extracted → `document_extraction_pending`.
 *  5. Any video without a transcript → `video_transcription_failed`.
 *  6. Any document without usable text → `document_extraction_failed`.
 *  7. Documents or videos with text → `documents`; transcribed audio and
 *     images that carry extracted text ride along as virtual documents (#983).
 *  8. Audio only → `audio`, the conversational voice-reply path (#955).
 *  9. Images → `images`, the vision path. Mixed with audio and no document,
 *     the images win and the transcript is dropped — vision only takes the
 *     user prompt as side text.
 * 10. Otherwise → `unsupported`.
 */
final class FileAnalysisRoute
{
    private const PENDING_STATUSES = ['uploaded', 'extracting'];

    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $filesInfo
     *
     * @return array{
     *     kind: string,
     *     documents?: list<array<string, mixed>>,
     *     audio?: list<array<string, mixed>>,
     *     images?: list<array<string, mixed>>,
     *     audio_files?: list<array<string, mixed>>,
     *     video_files?: list<array<string, mixed>>,
     *     pending_documents?: list<array<string, mixed>>,
     *     failed_documents?: list<array<string, mixed>>
     * }
     */
    public static function of(array $filesInfo): array
    {
        $buckets = [];
        foreach (['audio', 'video', 'document'] as $kind) {
            $buckets[$kind] = ['ready' => [], 'pending' => [], 'failed' => []];
        }
        $images = [];

        foreach ($filesInfo as $info) {
            $kind = match (true) {
                (bool) $info['is_audio'] => 'audio',
                (bool) ($info['is_video'] ?? false) => 'video',
                (bool) $info['is_document'] => 'document',
                default => null,
            };
            if (null !== $kind) {
                $buckets[$kind][self::readiness($info)][] = $info;
            } elseif ($info['is_image']) {
                $images[] = $info;
            }
        }

        [$audio, $video, $documents] = [$buckets['audio'], $buckets['video'], $buckets['document']];

        $blocked = match (true) {
            [] !== $audio['pending'] => ['kind' => 'audio_transcription_pending', 'audio_files' => $audio['pending']],
            [] !== $audio['failed'] => ['kind' => 'audio_transcription_failed', 'audio_files' => $audio['failed']],
            [] !== $video['pending'] => ['kind' => 'video_transcription_pending', 'video_files' => $video['pending']],
            [] !== $documents['pending'] => ['kind' => 'document_extraction_pending', 'pending_documents' => $documents['pending'], 'failed_documents' => $documents['failed']],
            [] !== $video['failed'] => ['kind' => 'video_transcription_failed', 'video_files' => $video['failed']],
            [] !== $documents['failed'] => ['kind' => 'document_extraction_failed', 'pending_documents' => $documents['pending'], 'failed_documents' => $documents['failed']],
            default => null,
        };
        if (null !== $blocked) {
            return $blocked;
        }

        if ([] !== $documents['ready'] || [] !== $video['ready']) {
            $bundle = $documents['ready'];
            foreach ($video['ready'] as $file) {
                $bundle[] = self::asDocument($file, 'video', ' (video transcript)', 'video', ' transcript', 'Video transcript:');
            }
            foreach ($audio['ready'] as $file) {
                $bundle[] = self::asDocument($file, 'voice-message', ' (voice transcript)', 'audio', ' transcript', 'Voice message transcript:');
            }
            foreach ($images as $file) {
                if ('' !== trim((string) ($file['text'] ?? ''))) {
                    $bundle[] = self::asDocument($file, 'image', ' (image text)', 'image', ' image', 'Text extracted from image:');
                }
            }

            return ['kind' => 'documents', 'documents' => $bundle];
        }

        if ([] !== $audio['ready'] && [] === $images) {
            return ['kind' => 'audio', 'audio' => $audio['ready']];
        }

        if ([] !== $images) {
            return ['kind' => 'images', 'images' => $images];
        }

        return ['kind' => 'unsupported'];
    }

    /**
     * @param array<string, mixed> $info
     */
    private static function readiness(array $info): string
    {
        if ('' !== trim((string) ($info['text'] ?? ''))) {
            return 'ready';
        }

        return in_array((string) ($info['status'] ?? ''), self::PENDING_STATUSES, true) ? 'pending' : 'failed';
    }

    /**
     * A transcribed audio or video, or an image with extracted text, as a
     * virtual document so the document prompt includes it without a branch
     * of its own.
     *
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    private static function asDocument(array $file, string $defaultName, string $nameSuffix, string $defaultType, string $typeSuffix, string $heading): array
    {
        return [
            'id' => $file['id'] ?? null,
            'name' => (string) ($file['name'] ?? $defaultName).$nameSuffix,
            'type' => (string) ($file['type'] ?? $defaultType).$typeSuffix,
            'path' => $file['path'] ?? '',
            'text' => $heading."\n".trim((string) ($file['text'] ?? '')),
            'status' => $file['status'] ?? null,
            'is_image' => false,
            'is_audio' => false,
            'is_document' => true,
            'is_video' => false,
        ];
    }
}
