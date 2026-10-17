<?php

namespace App\Service\Message\Handler\Media;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJob;
use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\Message\ProgressCallback;
use Psr\Log\LoggerInterface;

/**
 * Phase 6 of a media turn: renders it. The provider options are built per
 * media type (a video's length, resolution, progress and reference frame:
 * an image URL from the text, an inline file or a public copy), the render
 * goes to a background job where allowed or to the provider right away, and
 * the result is kept and billed. A Stop is a neutral outcome that is still
 * billed (#1146); any other failure is told in one sentence, with the
 * provider's cause for an admin.
 */
final readonly class MediaGenerationRun
{
    public function __construct(
        private AiFacade $aiFacade,
        private MediaJobDetach $jobs,
        private GeneratedMediaStore $store,
        private MediaTurnBilling $billing,
        private MediaUploadStore $uploadStore,
        private MediaCancellationStore $cancellationStore,
        private MediaErrorMessageBuilder $errorMessageBuilder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(MediaTurnRequest $request, ?User $user, callable $streamCallback, ?callable $progressCallback): array
    {
        // Holds the provider result once generation succeeds. Initialised here so
        // the ProviderCancelledException catch can safely inspect it (issue #1146
        // cancelled-cost recording) even when the abort happens mid-call.
        $result = null;

        try {
            if ('audio' === $request->mediaType) {
                return $this->speech($request, $user, $streamCallback, $progressCallback);
            }

            if ('video' === $request->mediaType) {
                $videoOptions = $this->withReferenceFrames($request, $this->videoOptions($request, $progressCallback), $progressCallback);
                if ($this->jobs->allowed($request)
                    && $this->aiFacade->supportsAsyncVideo((string) $request->model->provider)) {
                    $inputRef = isset($videoOptions['image_url']) && is_string($videoOptions['image_url'])
                        ? $videoOptions['image_url']
                        : null;

                    return $this->jobs->detach(MediaJob::TYPE_VIDEO, $request, $videoOptions, $inputRef, $streamCallback, $progressCallback);
                }

                $result = $this->aiFacade->generateVideo($request->prompt, $request->message->getUserId(), $videoOptions);
                $media = $result['videos'] ?? [];
            } else {
                $imageOptions = $this->imageOptions($request);
                if ($this->jobs->allowed($request)) {
                    $inputRef = $request->references->attachedImagePaths[0] ?? null;

                    return $this->jobs->detach(MediaJob::TYPE_IMAGE, $request, $imageOptions, is_string($inputRef) ? $inputRef : null, $streamCallback, $progressCallback);
                }

                // Stop and disconnect for the blocking image path (#1155):
                // providers that poll for the result abort mid-flight; for a
                // single-shot provider it is a no-op. Never serialized into a job.
                $cancelCheck = $this->buildCancelCheck($request->options);
                if (null !== $cancelCheck) {
                    $imageOptions['cancel_check'] = $cancelCheck;
                }

                $result = $this->aiFacade->generateImage($request->prompt, $request->message->getUserId(), $imageOptions);
                $media = $result['images'] ?? [];
            }

            return $this->deliver($request, $user, $result, $media, $streamCallback, $progressCallback);
        } catch (ProviderCancelledException $e) {
            return $this->cancelled($request, $user, $result);
        } catch (\Exception $e) {
            return $this->failed($request, $user, $e, $streamCallback);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function speech(MediaTurnRequest $request, ?User $user, callable $streamCallback, ?callable $progressCallback): array
    {
        $message = $request->message;
        $prompt = $request->prompt;
        $provider = (string) $request->model->provider;
        $modelName = $request->model->name;
        $this->logger->info('MediaGenerationHandler: Starting TTS generation', [
            'provider' => $provider,
            'model' => $modelName,
            'text_length' => strlen($prompt),
        ]);

        if ($this->jobs->allowed($request)) {
            return $this->jobs->detach(MediaJob::TYPE_AUDIO, $request, [], null, $streamCallback, $progressCallback);
        }

        $ttsLanguage = is_string($request->classification['language'] ?? null)
            ? (string) $request->classification['language']
            : ($message->getLanguage() ?: 'en');

        // Facade sanitizes; pass the answer language explicitly (#2283).
        $result = $this->aiFacade->synthesize(
            $prompt,
            $ttsLanguage,
            $message->getUserId(),
            [
                'provider' => $provider,
                'model' => $modelName,
                'format' => 'mp3',
            ]
        );

        // The facade already saved the file under the user's upload path.
        $relativePath = $result['relativePath'];

        $this->logger->info('MediaGenerationHandler: TTS audio generated', [
            'relativePath' => $relativePath,
            'provider' => $result['provider'],
        ]);

        $this->store->keepSpeech($request, $relativePath, $result['provider'] ?? $provider);

        // The player is the deliverable; the frontend renders this token in the
        // user's language instead of an English prefix and the raw script.
        $streamCallback('__AUDIO_GENERATED__');

        ProgressCallback::notify($progressCallback, 'generating', 'Audio generated successfully.');

        $audioMediaUsage = [
            'characters' => $result['text_length'] ?? mb_strlen($prompt),
        ];

        // Issue #1146: record the cost before returning, so a torn-down worker can't bypass audio billing.
        $recordedMediaUsage = $this->billing->record($user, $request, $result['provider'] ?? $provider, $result['model'] ?? $modelName, $audioMediaUsage);

        return [
            'metadata' => [
                'provider' => $result['provider'] ?? $provider,
                'model' => $result['model'] ?? $modelName,
                'model_id' => $request->model->id,
                'local_path' => $relativePath,
                'media_prompt' => $prompt,
                'media_type' => $request->mediaType,
                'media_usage' => $audioMediaUsage,
                'usage_recorded' => null !== $recordedMediaUsage,
                'media_recorded_usage' => $recordedMediaUsage,
                'file' => [
                    'path' => '/api/v1/files/uploads/'.$relativePath,
                    'type' => $request->mediaType,
                ],
            ],
        ];
    }

    /**
     * Length: an explicit option, else the sorter's, else the model's standard
     * length (Higgsfield DoP renders 5 seconds), else eight seconds.
     *
     * @return array<string, mixed>
     */
    private function videoOptions(MediaTurnRequest $request, ?callable $progressCallback): array
    {
        $options = $request->options;
        $classification = $request->classification;
        $modelConfig = $request->model->config;
        if (isset($options['duration'])) {
            $duration = (int) $options['duration'];
        } elseif (isset($classification['duration'])) {
            $duration = (int) $classification['duration'];
        } elseif (isset($modelConfig['default_duration']) && is_numeric($modelConfig['default_duration'])) {
            $duration = (int) $modelConfig['default_duration'];
        } else {
            $duration = 8;
        }

        $resolutionOption = isset($options['resolution']) && is_string($options['resolution'])
            ? $options['resolution']
            : (isset($classification['resolution']) && is_string($classification['resolution']) ? $classification['resolution'] : null);

        $this->logger->info('MediaGenerationHandler: Video parameters from AI classification', [
            'duration' => $duration,
            'duration_source' => isset($options['duration']) ? 'options' : (isset($classification['duration']) ? 'ai_classification' : 'default'),
            'resolution' => $resolutionOption,
        ]);

        $videoOptions = [
            'provider' => (string) $request->model->provider,
            'model' => $request->model->name,
            'modelConfig' => $modelConfig,
            'duration' => $duration,
            'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
            'resolution' => $resolutionOption,
            // The poll status: the message keeps the single chat's "Generating..."
            // box working; the metadata drives a multitask card's progress bar.
            'progress_callback' => function (array $progress) use ($progressCallback): void {
                $elapsed = $progress['elapsed_seconds'] ?? 0;
                ProgressCallback::notify($progressCallback, 'generating', "Generating video... ({$elapsed}s)", [
                    'provider_status' => $progress['status'] ?? 'processing',
                    'elapsed_seconds' => $elapsed,
                    'percent' => $progress['percent'] ?? null,
                    'attempt' => $progress['attempt'] ?? null,
                    'max_attempts' => $progress['max_attempts'] ?? null,
                ]);
            },
        ];

        // Stop button support: the shared cancellation store (the global Stop or
        // a card's Stop on another worker) and a client disconnect abort the poll,
        // and the provider is told to cancel the render.
        $cancelCheck = $this->buildCancelCheck($options);
        if (null !== $cancelCheck) {
            $videoOptions['cancel_check'] = $cancelCheck;
        }

        return $videoOptions;
    }

    /**
     * Image-to-video needs a frame the provider can fetch, in this order: a
     * public image URL from the text (forwarded as it is), an attached file
     * on a provider that takes inline frames (xAI), else a public `ai_` copy
     * of the attached file.
     *
     * @param array<string, mixed> $videoOptions
     *
     * @return array<string, mixed>
     */
    private function withReferenceFrames(MediaTurnRequest $request, array $videoOptions, ?callable $progressCallback): array
    {
        $provider = (string) $request->model->provider;
        $modelName = $request->model->name;
        $imageUrlsInText = $request->references->imageUrlsInText;
        $inlineReferenceImage = $this->aiFacade->supportsInlineReferenceImage($provider);
        $referenceImageUrls = $imageUrlsInText;

        foreach ($request->references->attachedImagePaths as $attachedImagePath) {
            if ($inlineReferenceImage) {
                $referenceImageUrls[] = $attachedImagePath;
                continue;
            }

            $publicImageUrl = $this->uploadStore->publishInputImageForRemoteFetch($attachedImagePath, $request->message->getUserId());
            if (null !== $publicImageUrl) {
                $referenceImageUrls[] = $publicImageUrl;
            } else {
                $this->logger->warning('MediaGenerationHandler: Could not publish input image for image-to-video; skipping that attachment', [
                    'provider' => $provider,
                    'model' => $modelName,
                ]);
            }
        }

        if ([] !== $referenceImageUrls) {
            $videoOptions['image_url'] = $referenceImageUrls[0];
            $videoOptions['images'] = $referenceImageUrls;
            ProgressCallback::notify($progressCallback, 'generating', 'Preparing your image for animation...');
            $this->logger->info('MediaGenerationHandler: Image-to-video source(s) prepared', [
                'provider' => $provider,
                'model' => $modelName,
                'reference_count' => count($referenceImageUrls),
                'from_text_url' => [] !== $imageUrlsInText,
                'inline' => $inlineReferenceImage,
            ]);
        }

        return $videoOptions;
    }

    /**
     * @return array<string, mixed>
     */
    private function imageOptions(MediaTurnRequest $request): array
    {
        $options = $request->options;
        $isPic2Pic = $request->references->isPic2Pic();
        $imageOptions = [
            'provider' => (string) $request->model->provider,
            'model' => $request->model->name,
            'modelConfig' => $request->model->config,
            'quality' => $options['quality'] ?? ($isPic2Pic ? 'high' : 'standard'),
            'style' => $options['style'] ?? 'vivid',
            'size' => $options['size'] ?? '1024x1024',
        ];

        if ($isPic2Pic) {
            $imageOptions['images'] = $request->references->attachedImagePaths;
            $this->logger->info('MediaGenerationHandler: Passing reference images to provider', [
                'count' => count($request->references->attachedImagePaths),
                'provider' => $imageOptions['provider'],
                'model' => $imageOptions['model'],
            ]);
        }

        return $imageOptions;
    }

    /**
     * The marker is stored, not the prompt (that lives in `media_prompt`);
     * channels and the web chat render it in the user's language.
     *
     * @param array<string, mixed> $result the provider's answer
     * @param array<int, mixed>    $media  the images or videos in it
     *
     * @return array<string, mixed>
     */
    private function deliver(MediaTurnRequest $request, ?User $user, array $result, array $media, callable $streamCallback, ?callable $progressCallback): array
    {
        $mediaType = $request->mediaType;
        $provider = (string) $request->model->provider;
        $modelName = $request->model->name;
        $this->logger->info('MediaGenerationHandler: Media generated', [
            'count' => count($media),
            'provider' => $result['provider'],
            'media_type' => $mediaType,
            'raw_result' => json_encode($result),
            'media_sample' => !empty($media) ? json_encode($media[0]) : 'empty',
        ]);

        if (empty($media)) {
            throw new \Exception("No {$mediaType} generated by provider. Response: ".json_encode($result));
        }

        if (!isset($media[0]['url'])) {
            $this->logger->error('MediaGenerationHandler: No URL in media response', [
                'media' => json_encode($media[0] ?? null),
                'result' => json_encode($result),
            ]);
            throw new \Exception("Generated {$mediaType} has no URL. Check provider response format.");
        }
        $mediaUrl = $media[0]['url'];

        $stored = $this->store->keep($request, $result, $mediaUrl, $progressCallback);

        $responseText = GeneratedMediaTextRenderer::storageMarker($mediaType);
        $folderNote = $this->store->deliverToFolder($request->message, $stored->localPath, $request->options);
        if (null !== $folderNote) {
            $responseText .= "\n\n".$folderNote;
        }

        $streamCallback($responseText);

        ProgressCallback::notify($progressCallback, 'generating', ucfirst($mediaType).' generated successfully.');

        $mediaUsage = $this->billing->usageOf($request, $result, $media);

        // Issue #1146: record the cost the moment the provider has billed us,
        // so a client disconnect that tears the worker down cannot skip it.
        // `usage_recorded` tells the caller to skip its own recording.
        $recordedMediaUsage = $this->billing->record($user, $request, $result['provider'] ?? $provider, $result['model'] ?? $modelName, $mediaUsage);

        return [
            'metadata' => [
                'provider' => $result['provider'] ?? $provider,
                'model' => $result['model'] ?? $modelName,
                'model_id' => $request->model->id,
                'image_url' => $mediaUrl,
                'local_path' => $stored->localPath,
                'thumbnail_path' => $stored->thumbnailPath,
                'media_prompt' => $request->prompt,
                'media_type' => $mediaType,
                'media_usage' => $mediaUsage,
                'usage_recorded' => null !== $recordedMediaUsage,
                'media_recorded_usage' => $recordedMediaUsage,
                'file' => [
                    'path' => $stored->displayUrl,
                    'type' => $mediaType,
                ],
                'folder_delivery' => $folderNote,
                // Which picture of the conversation this result was edited
                // from (null for a fresh generation), shown to the user.
                'edit_source_file_id' => $request->references->editSource?->fileId,
                'edit_source_name' => $request->references->editSource?->displayName,
            ],
        ];
    }

    /**
     * The user pressed Stop: a neutral outcome, signalled by the `cancelled`
     * flag, not a content marker. The provider billed at submission or
     * acceptance, so the cost is recorded from the request (#1146); a
     * Stop-then-restart loop cannot bypass billing.
     *
     * @param array<string, mixed>|null $result the provider's answer, null when the abort came first
     *
     * @return array<string, mixed>
     */
    private function cancelled(MediaTurnRequest $request, ?User $user, ?array $result): array
    {
        $provider = (string) $request->model->provider;
        $modelName = $request->model->name;
        $this->logger->info('MediaGenerationHandler: Generation cancelled by user', [
            'provider' => $provider,
            'model' => $modelName,
            'media_type' => $request->mediaType,
        ]);

        $cancelledMediaUsage = $this->billing->cancelledUsage($request, $result);
        $recordedMediaUsage = $this->billing->record($user, $request, $provider, $modelName, $cancelledMediaUsage);

        return [
            'metadata' => [
                'provider' => $provider,
                'model' => $modelName,
                'model_id' => $request->model->id,
                'media_type' => $request->mediaType,
                'media_usage' => $cancelledMediaUsage,
                'cancelled' => true,
                'usage_recorded' => null !== $recordedMediaUsage,
                'media_recorded_usage' => $recordedMediaUsage,
                'error' => 'cancelled',
            ],
        ];
    }

    /**
     * Admins get the raw provider error appended for diagnosis; everyone
     * else reads the clean sentence.
     *
     * @return array<string, mixed>
     */
    private function failed(MediaTurnRequest $request, ?User $user, \Exception $e, callable $streamCallback): array
    {
        $provider = (string) $request->model->provider;
        $modelName = $request->model->name;
        $this->logger->error('MediaGenerationHandler: Generation failed', [
            'error' => $e->getMessage(),
            'provider' => $provider,
            'model' => $modelName,
            'media_type' => $request->mediaType,
            'exception' => $e,
        ] + ($e instanceof ProviderException ? $e->logContext() : []));

        $userMessage = $this->errorMessageBuilder->buildErrorMessage($e, $request->mediaType, $request->language(), $user?->isAdmin() ?? false);
        $streamCallback($userMessage);

        return [
            'metadata' => [
                'provider' => $provider,
                'model' => $modelName,
                'error' => $e->getMessage(),
            ],
        ];
    }

    /**
     * Build a cancellation probe for the provider poll loop from the processing
     * options, or null when there is nothing to watch.
     *
     * Two triggers, both cheap to check every poll interval:
     *   - the shared {@see MediaCancellationStore} (set by the Stop button on a
     *     different worker — track-scoped for global Stop, node-scoped for a
     *     single multitask step);
     *   - a client disconnect on the streaming worker.
     *
     * @param array<string, mixed> $options
     */
    private function buildCancelCheck(array $options): ?callable
    {
        $trackId = isset($options['track_id']) && is_scalar($options['track_id'])
            ? trim((string) $options['track_id'])
            : '';
        $nodeId = isset($options['node_id']) && is_scalar($options['node_id'])
            ? trim((string) $options['node_id'])
            : null;

        if ('' === $trackId) {
            return null;
        }

        return function () use ($trackId, $nodeId): bool {
            if (\function_exists('connection_aborted') && 1 === connection_aborted()) {
                return true;
            }

            return $this->cancellationStore->isCancelled($trackId, '' !== (string) $nodeId ? $nodeId : null);
        };
    }
}
