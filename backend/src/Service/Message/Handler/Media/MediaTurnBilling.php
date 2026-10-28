<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\User;
use App\Service\Media\RenderedImageSize;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use Psr\Log\LoggerInterface;

/**
 * Phase 8 of a media turn: what it costs. The usage is read from what the
 * provider made (images at their quality and rendered size, video seconds
 * and resolution) and recorded the moment the provider has billed (#1146),
 * so a torn-down worker cannot skip it. A Stop is billed from the request,
 * because the provider charged at submission.
 */
final readonly class MediaTurnBilling
{
    public function __construct(
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $result the provider's answer
     * @param array<int, mixed>    $media  the images or videos in it
     *
     * @return array<string, mixed>
     */
    public function usageOf(MediaTurnRequest $request, array $result, array $media): array
    {
        $options = $request->options;
        $mediaUsage = [];
        if ('image' === $request->mediaType) {
            $mediaUsage['images'] = $result['image_count'] ?? 1;
            // Carry the quality/size so per-tier image models (gpt-image)
            // bill the exact price (#1315). The quality mirrors the default
            // the image options used. An edit leaves the size to the model,
            // so the size it rendered wins over the requested one when the
            // provider reports it (#2404).
            $mediaUsage['quality'] = $options['quality'] ?? ($request->references->isPic2Pic() ? 'high' : 'standard');
            $mediaUsage['size'] = RenderedImageSize::billingKey($media[0]['size'] ?? null)
                ?? $options['size'] ?? '1024x1024';
        } elseif ('video' === $request->mediaType) {
            $requestedDuration = $options['duration'] ?? $request->classification['duration'] ?? 8;
            $duration = $result['duration_seconds'] ?? null;
            if (null === $duration) {
                $this->logger->warning('MediaGenerationHandler: Provider omitted duration_seconds for video generation. Falling back to requested duration.', [
                    'provider' => $request->model->provider,
                    'model' => $request->model->name,
                    'requested_duration' => $requestedDuration,
                ]);
                $duration = (float) $requestedDuration;
                $mediaUsage['duration_missing_fallback'] = true;
            }
            $mediaUsage['duration_seconds'] = $duration;
            $videoResolution = $this->extractVideoResolution($result);
            if (null !== $videoResolution) {
                $mediaUsage['resolution'] = $videoResolution;
            }
        }

        return $mediaUsage;
    }

    /**
     * @param array<string, mixed> $mediaUsage
     */
    public function record(?User $user, MediaTurnRequest $request, ?string $provider, ?string $modelName, array $mediaUsage): ?RecordedUsage
    {
        return $this->maybeRecordMediaUsage($user, $request->options, $request->mediaAction(), $request->model->id, $provider, $modelName, $mediaUsage);
    }

    /**
     * @param array<string, mixed>|null $result the provider's partial answer, if any
     *
     * @return array<string, mixed>
     */
    public function cancelledUsage(MediaTurnRequest $request, ?array $result): array
    {
        return $this->buildCancelledMediaUsage($request->mediaType, $request->options, $request->classification, $result);
    }

    /**
     * Record media-generation cost into BUSELOG when the caller owns the cost
     * (issue #1146).
     *
     * Gated behind the `record_media_usage` option so ONLY the SSE
     * StreamController and the multitask MediaGenerationRunner record here —
     * legacy callers (webhook / WhatsApp / MCP) keep recording at their own
     * call site and must not pass the flag, which avoids double counting.
     *
     * Recording at this point (right after the provider returns / is cancelled)
     * is the reliable place: the provider has already billed us, so even if the
     * streaming worker dies on a client disconnect afterwards the cost is
     * already persisted and counts toward the user's budget.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $mediaUsage
     *
     * @return RecordedUsage|null the recorded token/cost figures, or null when
     *                            nothing was recorded (caller records itself)
     */
    private function maybeRecordMediaUsage(
        ?User $user,
        array $options,
        string $mediaAction,
        ?int $modelId,
        ?string $provider,
        ?string $modelName,
        array $mediaUsage,
    ): ?RecordedUsage {
        if (empty($options['record_media_usage']) || !$user instanceof User) {
            return null;
        }

        try {
            return \App\Service\Usage\LegacyUsageWriter::record($this->rateLimitService, $user, $mediaAction, [
                'provider' => $provider ?? 'unknown',
                'model' => $modelName ?? 'unknown',
                'model_id' => $modelId,
                'media_usage' => $mediaUsage,
            ]);
        } catch (\Throwable $e) {
            // Never let a billing-record hiccup take down media generation.
            $this->logger->error('MediaGenerationHandler: Failed to record media usage', [
                'action' => $mediaAction,
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build the media_usage payload to bill for a CANCELLED generation
     * (issue #1146).
     *
     * The provider has already charged us (video at submission, image at
     * acceptance), so the cost is deterministic from the request parameters even
     * though we never received the asset. Mirrors the success-path shape so
     * {@see RateLimitService::recordUsage()} computes the same per-second /
     * per-image / per-character cost.
     *
     * @param array<string, mixed>      $options
     * @param array<string, mixed>      $classification
     * @param array<string, mixed>|null $result         provider partial result, if any
     *
     * @return array<string, mixed>
     */
    private function buildCancelledMediaUsage(string $mediaType, array $options, array $classification, ?array $result): array
    {
        if ('video' === $mediaType) {
            $requestedDuration = $options['duration'] ?? $classification['duration'] ?? 8;
            $duration = (float) ($result['duration_seconds'] ?? $requestedDuration);
            $usage = ['duration_seconds' => $duration];

            $resolution = (is_array($result) ? $this->extractVideoResolution($result) : null)
                ?? (isset($options['resolution']) && is_string($options['resolution']) ? $options['resolution'] : null)
                ?? (isset($classification['resolution']) && is_string($classification['resolution']) ? $classification['resolution'] : null);
            if (null !== $resolution) {
                $usage['resolution'] = $resolution;
            }

            return $usage;
        }

        if ('audio' === $mediaType) {
            return ['characters' => mb_strlen((string) ($classification['media_prompt'] ?? ''))];
        }

        // Image providers bill per generated image.
        return ['images' => 1];
    }

    /**
     * Pull the effective resolution from a video provider result.
     *
     * AiFacade::generateVideo() exposes the resolution at the top level, but some
     * provider payloads only nest it inside the first videos[] item. The first
     * videos[] entry can also legitimately be a plain URL string, so we must
     * guard before indexing it.
     *
     * @param array<string, mixed> $result
     */
    private function extractVideoResolution(array $result): ?string
    {
        $top = $result['resolution'] ?? null;
        if (is_string($top) && '' !== $top) {
            return $top;
        }

        $first = $result['videos'][0] ?? null;
        if (is_array($first)) {
            $nested = $first['resolution'] ?? null;
            if (is_string($nested) && '' !== $nested) {
                return $nested;
            }
        }

        return null;
    }
}
