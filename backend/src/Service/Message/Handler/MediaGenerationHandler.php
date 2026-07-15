<?php

namespace App\Service\Message\Handler;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\Entity\Message;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\Service\File\FileHelper;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Media Generation Handler.
 *
 * Handles media generation requests (images, videos, audio) using AI providers
 *
 * User prompts are used directly - frontend has "Enhance Prompt" button for improvements.
 */
#[AutoconfigureTag('app.message.handler')]
final readonly class MediaGenerationHandler implements MessageHandlerInterface
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private MediaPromptExtractor $promptExtractor,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private ThumbnailService $thumbnailService,
        private RateLimitService $rateLimitService,
        private MediaErrorMessageBuilder $errorMessageBuilder,
        private MessageBusInterface $messageBus,
        private PerfPipelineFlag $perfPipelineFlag,
        private MediaCancellationStore $cancellationStore,
        private MediaJobConfig $mediaJobConfig,
        private MediaJobService $mediaJobService,
        private MediaJobDispatcher $mediaJobDispatcher,
        private MediaJobMessageSync $mediaJobMessageSync,
        private GeneratedFileRegistrar $generatedFileRegistrar,
        private string $uploadDir = '/var/www/backend/var/uploads',
        #[Autowire(env: 'default::bool:COST_BUDGET_GATE_ENABLED')]
        private bool $costBudgetGateEnabled = false,
        // Public base URL that serves /api/v1/files/uploads/* (same value used
        // by OgImageService / shared chat pages). Needed for image-to-video:
        // Higgsfield (and most i2v providers) fetch the source frame from a
        // public http(s) URL, so the user's attached image must be exposed at
        // an absolute, internet-reachable URL — not a local filesystem path.
        #[Autowire('%env(APP_URL)%')]
        private string $publicBaseUrl = '',
    ) {
    }

    public function getName(): string
    {
        return 'image_generation'; // Keep legacy name for backward compatibility
    }

    /**
     * Non-streaming handle method (required by interface).
     * Delegates to handleStream with a blocking accumulator.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options        forwarded to {@see handleStream()} so callers (email
     *                                             webhook, generic API) can disable memories or set the
     *                                             channel exactly like the SSE path
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $content = '';
        $metadata = [];

        // Simple accumulator callback. visibleText() keeps only answer text —
        // structured reasoning chunks must never end up in the message (#1067).
        $streamCallback = function ($chunk) use (&$content, &$metadata) {
            $content .= StreamChunk::visibleText($chunk);
            // Merge metadata if present in chunk
            if (is_array($chunk) && isset($chunk['metadata']) && is_array($chunk['metadata'])) {
                $metadata = array_merge($metadata, $chunk['metadata']);
            }
        };

        // Call streaming handler — forward options so non-streaming callers
        // (email/webhook) preserve the same channel/disable flags as SSE.
        $result = $this->handleStream(
            $message,
            $thread,
            $classification,
            $streamCallback,
            $progressCallback,
            $options
        );

        // Return accumulated result
        return [
            'content' => $content,
            'metadata' => $result['metadata'] ?? [],
        ];
    }

    /**
     * Handle image generation with streaming.
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        // Send initial status based on detected media type (will be refined later)
        $this->notify($progressCallback, 'analyzing', 'Understanding your request...');

        // Dispatch background memory extraction on the user's prompt before
        // we start the (slow, possibly-failing) provider call. Mirrors the
        // pattern in ChatHandler::handleStream(): memories must be picked
        // up from any user turn that carries personal information, not just
        // text-chat turns (issue #880). Doing it up-front means a failed
        // image generation still saves "ich liebe Hunde" — and the queue
        // dispatch is cheap (a few ms) so the user doesn't notice.
        $this->maybeDispatchMemoryExtraction($message, $thread, $classification, $options);

        // Extract media prompt via AI (mediamaker prompt)
        $promptData = $this->promptExtractor->extract($message, $thread, $classification);
        $prompt = trim($promptData['prompt'] ?? '');
        $promptMediaType = $promptData['media_type'] ?? null;

        if ('' === $prompt) {
            $prompt = $message->getText();
        }

        if ('' === $prompt) {
            throw new \RuntimeException('Unable to determine media prompt text');
        }

        // Collect attached image paths for pic2pic. Besides the message's own
        // uploads we also accept reference images passed explicitly via options
        // (issue #1144: multitask media-to-media chains, where an upstream node's
        // generated image is the reference for this video/edit node).
        $referenceImagePaths = is_array($options['reference_image_paths'] ?? null) ? $options['reference_image_paths'] : [];
        $attachedImagePaths = $this->collectAttachedImagePaths($message, $referenceImagePaths);
        $isPic2Pic = !empty($attachedImagePaths);

        // Detect public image URLs the user pasted directly into their message
        // text. A request like "make a video from https://…/photo.jpg where the
        // sun sets over the sea" carries NO file attachment, so $isPic2Pic is
        // false and the request would wrongly route to text-to-video (issue:
        // public image-URL → Veo text2vid). Treat an image URL in the text as an
        // image-to-video reference: it is already provider-fetchable, so it is
        // the ideal i2v source — no republish and no internet-reachable APP_URL
        // required (unlike a locally-attached file).
        $imageUrlsInText = FileHelper::extractImageUrls($message->getText());

        // The multitask DAG runner feeds the handler a synthetic message built
        // from the planner's CLEANED prompt (URL stripped) plus the original
        // user message's file attachments only. When the image was supplied as a
        // URL in the original text, it never reaches us via the synthetic
        // message — so the runner forwards those URLs explicitly here.
        foreach ($this->referenceImageUrlsFromOptions($options) as $optionImageUrl) {
            if (!in_array($optionImageUrl, $imageUrlsInText, true)) {
                $imageUrlsInText[] = $optionImageUrl;
            }
        }

        $hasVideoReferenceImage = $isPic2Pic || [] !== $imageUrlsInText;

        $this->logger->info('MediaGenerationHandler: Starting media generation', [
            'user_id' => $message->getUserId(),
            'prompt' => substr($prompt, 0, 100),
            'media_hint' => $promptMediaType,
            'is_pic2pic' => $isPic2Pic,
            'attached_images' => count($attachedImagePaths),
            'image_urls_in_text' => count($imageUrlsInText),
        ]);

        // Get media generation model - detect type from model tag if specified
        $modelId = null;
        $provider = null;
        $modelName = null;
        $mediaType = 'image'; // default

        // Check if this is a slash command (e.g., /pic, /vid)
        $topic = $classification['topic'] ?? null;
        $isSlashCommand = false;
        if ('tools:pic' === $topic) {
            $mediaType = 'image';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /pic command, forcing image generation');
        } elseif ('tools:vid' === $topic) {
            $mediaType = 'video';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /vid command, forcing video generation');
        } elseif ('tools:tts' === $topic) {
            $mediaType = 'audio';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /tts command, forcing audio generation');
        }

        // Priority: Again model_id > Task-prompt aiModel > DB default
        $promptMetadata = $classification['prompt_metadata'] ?? [];
        $promptAiModel = (isset($promptMetadata['aiModel']) && (int) $promptMetadata['aiModel'] > 0)
            ? (int) $promptMetadata['aiModel']
            : null;

        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('MediaGenerationHandler: Using classification override model', [
                'model_id' => $modelId,
            ]);
            [$mediaType, $provider, $modelName] = $this->resolveMediaTypeFromModelId($modelId, $isSlashCommand, $mediaType, $provider, $modelName);
        } elseif ($promptAiModel) {
            $modelId = $promptAiModel;
            $this->logger->info('MediaGenerationHandler: Using task-prompt aiModel override', [
                'model_id' => $modelId,
            ]);
            [$mediaType, $provider, $modelName] = $this->resolveMediaTypeFromModelId($modelId, $isSlashCommand, $mediaType, $provider, $modelName);
        } else {
            $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);

            if ($isSlashCommand) {
                if ('video' === $mediaType) {
                    // `/vid` with an image reference is an image-to-video request
                    // (animate the photo) → use the IMG2VID default; otherwise a
                    // text-to-video request → TEXT2VID. The reference can be an
                    // attached file OR a public image URL pasted in the text.
                    $modelId = $hasVideoReferenceImage
                        ? $this->modelConfigService->getDefaultModel('IMG2VID', $effectiveUserId)
                        : $this->modelConfigService->getDefaultModel('TEXT2VID', $effectiveUserId);
                } elseif ('audio' === $mediaType) {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2SOUND', $effectiveUserId);
                } else {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $effectiveUserId);
                }
                $this->logger->info('MediaGenerationHandler: Using default model for slash command', [
                    'media_type' => $mediaType,
                    'is_pic2pic' => $isPic2Pic,
                    'model_id' => $modelId,
                ]);
            } elseif ($hasVideoReferenceImage && 'video' === $promptMediaType) {
                // Image-to-video: the user supplied an image (attached file OR a
                // public image URL in the text) AND asked for a video/animation.
                // This MUST win over the pic2pic-image branch below (an image
                // reference alone would otherwise force an image edit). Routes to
                // the IMG2VID default (an image-to-video model); the reference is
                // published/forwarded as image_url in the video generation block.
                $modelId = $this->modelConfigService->getDefaultModel('IMG2VID', $effectiveUserId);
                $mediaType = 'video';
                $this->logger->info('MediaGenerationHandler: Image-to-video detected, using IMG2VID default model', [
                    'model_id' => $modelId,
                    'attached_images' => count($attachedImagePaths),
                    'image_urls_in_text' => count($imageUrlsInText),
                ]);
            } elseif ($isPic2Pic) {
                $modelId = $this->modelConfigService->getDefaultModel('PIC2PIC', $effectiveUserId);
                $mediaType = 'image';
                $this->logger->info('MediaGenerationHandler: Pic2pic detected, using PIC2PIC default model', [
                    'model_id' => $modelId,
                    'image_count' => count($attachedImagePaths),
                ]);
            } elseif ('video' === $promptMediaType) {
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2VID', $effectiveUserId);
                $mediaType = 'video';
                $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (video)');
            } elseif ('audio' === $promptMediaType) {
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2SOUND', $effectiveUserId);
                $mediaType = 'audio';
                $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (audio)');
            } elseif ('image' === $promptMediaType) {
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $effectiveUserId);
                $mediaType = 'image';
                $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (image)');
            } else {
                $this->logger->warning('MediaGenerationHandler: Media type not determined from extractor, asking user to clarify', [
                    'prompt_preview' => substr($prompt, 0, 100),
                ]);

                $lang = $classification['language'] ?? 'en';
                $clarification = 'de' === $lang
                    ? 'Ich konnte nicht erkennen, welche Art von Medium du erstellen möchtest. '
                        .'Bitte verwende einen der folgenden Befehle: `/pic` für Bilder, `/vid` für Videos, `/tts` für Audio.'
                    : 'I couldn\'t determine what type of media you want to generate. '
                        .'Please use one of the following commands: `/pic` for images, `/vid` for videos, `/tts` for audio.';

                $streamCallback($clarification);

                return [
                    'metadata' => [
                        'error' => 'media_type_not_determined',
                    ],
                ];
            }
        }

        // Resolve model ID to provider + model name + config
        $modelConfig = [];
        if ($modelId) {
            $model = $this->em->getRepository(\App\Entity\Model::class)->find($modelId);
            if ($model) {
                $provider = strtolower($model->getService());
                $modelName = $model->getProviderId() ?: $model->getName();
                $modelConfig = $model->getJson();
            }

            $this->logger->info('MediaGenerationHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
            ]);
        }

        // No model resolved: the operator has no default configured for this
        // media capability (TEXT2PIC/TEXT2VID/TEXT2SOUND/…). Return an
        // actionable error instead of silently routing to a hardcoded OpenAI
        // model, which would bill an unconfigured vendor and break for installs
        // that don't use OpenAI (#1320).
        if (!$provider) {
            $this->logger->warning('MediaGenerationHandler: No model configured for media generation', [
                'media_type' => $mediaType,
                'model_id' => $modelId,
            ]);

            $lang = $classification['language'] ?? 'en';
            $noModelMessage = 'de' === $lang
                ? 'Für die Medienerstellung ist kein Modell konfiguriert. Bitte lege in den Einstellungen ein Standardmodell fest (Bild, Video oder Audio).'
                : 'No model is configured for media generation. Please set a default model (image, video, or audio) in Settings.';
            $streamCallback($noModelMessage);

            return [
                'metadata' => [
                    'error' => 'no_media_model_configured',
                    'media_type' => $mediaType,
                ],
            ];
        }

        // Guard: a text-to-video request (no attached image) must never be sent
        // to an image-to-video model. i2v models (Higgsfield DoP/Kling/etc.)
        // require a reference frame and the provider rejects the request with
        // "'image_url' is a required property". This happens when an i2v model is
        // configured as the TEXT2VID default. Return an actionable message
        // instead of leaking a raw provider 400 to the user.
        if ('video' === $mediaType && !$hasVideoReferenceImage) {
            $requiresReferenceImage = !empty($modelConfig['requires_reference_image'])
                || (!empty($modelConfig['features']) && in_array('image2video', $modelConfig['features'], true));
            if ($requiresReferenceImage) {
                $this->logger->warning('MediaGenerationHandler: text-to-video routed to an image-to-video model without a reference image', [
                    'model_id' => $modelId,
                    'provider' => $provider,
                    'model' => $modelName,
                ]);

                $lang = $classification['language'] ?? 'en';
                $guardMessage = 'de' === $lang
                    ? sprintf('„%s“ ist ein Bild-zu-Video-Modell und benötigt ein Referenzbild. Hänge ein Bild an, um es zu animieren, oder wähle in den Einstellungen ein Text-zu-Video-Modell (z. B. Veo) als Standard für Video.', $modelName)
                    : sprintf('"%s" is an image-to-video model and needs a reference image. Attach an image to animate it, or choose a text-to-video model (e.g. Veo) as your video default in Settings.', $modelName);
                $streamCallback($guardMessage);

                return [
                    'metadata' => [
                        'error' => 'text2vid_requires_reference_image',
                        'provider' => $provider,
                        'model' => $modelName,
                    ],
                ];
            }
        }

        // Guard: image-to-video requires the remote provider to FETCH the
        // attached source frame from a public http(s) URL (we republish it at
        // APP_URL/api/v1/files/uploads/...). When APP_URL points at a
        // local/private host — the default in local dev (http://localhost:8000)
        // — that URL is unreachable from the provider's servers and the request
        // is rejected with a confusing raw "invalid_image_url" 400. Detect this
        // up front and return an actionable message instead of burning a
        // provider call (and credits) on a request that cannot succeed.
        if ('video' === $mediaType && [] !== $attachedImagePaths && [] === $imageUrlsInText && !$this->isPublicBaseUrlReachable()) {
            $base = rtrim($this->publicBaseUrl, '/');
            $this->logger->warning('MediaGenerationHandler: image-to-video blocked — APP_URL is not internet-reachable so the provider cannot fetch the source image', [
                'app_url' => $base,
                'provider' => $provider,
                'model' => $modelName,
            ]);

            $lang = $classification['language'] ?? 'en';
            $guardMessage = 'de' === $lang
                ? sprintf('Für Bild-zu-Video muss dein Bild über eine öffentliche URL an den Anbieter gesendet werden. Die öffentliche Adresse dieses Servers (APP_URL = „%s") ist jedoch ein lokaler/privater Host, den der Anbieter nicht erreichen kann. Setze APP_URL auf eine über das Internet erreichbare URL (z. B. einen Tunnel) oder verwende ein Text-zu-Video-Modell ohne angehängtes Bild.', $base)
                : sprintf('Image-to-video has to send your image to the provider from a public URL, but this server\'s public address (APP_URL = "%s") is a local/private host the provider can\'t reach. Set APP_URL to an internet-reachable URL (e.g. a tunnel), or use a text-to-video model without an attached image.', $base);
            $streamCallback($guardMessage);

            return [
                'metadata' => [
                    'error' => 'i2v_requires_public_app_url',
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }

        // Send detailed status update with provider and media type
        $providerName = ucfirst($provider);
        $mediaTypeLabel = match ($mediaType) {
            'video' => 'video',
            'audio' => 'audio',
            default => 'image',
        };

        $statusMessage = "AI is crafting your $mediaTypeLabel with $providerName $modelName";
        $this->notify($progressCallback, 'generating', $statusMessage);

        // Check rate limit for media type BEFORE generating (IMAGES, VIDEOS, AUDIOS)
        $mediaAction = match ($mediaType) {
            'image' => 'IMAGES',
            'video' => 'VIDEOS',
            'audio' => 'AUDIOS',
            default => 'IMAGES',
        };

        $userId = $message->getUserId();
        $user = $this->em->getRepository(User::class)->find($userId);

        if ($user) {
            $rateLimitCheck = $this->rateLimitService->checkLimit($user, $mediaAction);
            if (!$rateLimitCheck['allowed']) {
                $errorMessage = "Rate limit exceeded for {$mediaAction}. Used: {$rateLimitCheck['used']}/{$rateLimitCheck['limit']}";
                $this->logger->warning('MediaGenerationHandler: Rate limit exceeded', [
                    'user_id' => $userId,
                    'action' => $mediaAction,
                    'used' => $rateLimitCheck['used'],
                    'limit' => $rateLimitCheck['limit'],
                ]);

                $streamCallback($errorMessage);

                return [
                    'metadata' => [
                        'error' => 'rate_limit_exceeded',
                        'action' => $mediaAction,
                        'used' => $rateLimitCheck['used'],
                        'limit' => $rateLimitCheck['limit'],
                    ],
                ];
            }

            // Cost-budget backstop: media spend (incl. the +markup) counts toward
            // the user's monthly budget. Primary enforcement is at the chat entry
            // (StreamController); this also covers worker/multitask media paths.
            if ($this->costBudgetGateEnabled) {
                $budgetCheck = $this->rateLimitService->checkCostBudget($user);
                if (!$budgetCheck['allowed']) {
                    $lang = $classification['language'] ?? 'en';
                    $budgetMessage = 'de' === $lang
                        ? 'Dein monatliches Nutzungsbudget ist aufgebraucht. Du kannst zusätzliches Guthaben in 100-EUR-Schritten aufladen, um weiterzumachen.'
                        : 'Your monthly usage budget is used up. You can top up in EUR 100 steps to keep generating.';
                    $streamCallback($budgetMessage);

                    return [
                        'metadata' => [
                            'error' => 'cost_budget_exceeded',
                            'code' => 'COST_BUDGET_EXCEEDED',
                            'limit_type' => 'monthly',
                            'budget' => $budgetCheck['budget'],
                            'used' => $budgetCheck['used_cost'],
                            'remaining' => $budgetCheck['remaining'],
                            'topup_available' => true,
                        ],
                    ];
                }
            }
        }

        // Holds the provider result once generation succeeds. Initialised here so
        // the ProviderCancelledException catch can safely inspect it (issue #1146
        // cancelled-cost recording) even when the abort happens mid-call.
        $result = null;

        try {
            // Generate media based on type
            if ('video' === $mediaType) {
                // Get duration from AI classification (detected by MessageSorter)
                // Priority: explicit option > AI-detected from classification > default (8)
                if (isset($options['duration'])) {
                    $duration = (int) $options['duration'];
                } elseif (isset($classification['duration'])) {
                    $duration = (int) $classification['duration'];
                } elseif (isset($modelConfig['default_duration']) && is_numeric($modelConfig['default_duration'])) {
                    // Model-specific standard length (e.g. Higgsfield DoP = 5s).
                    // Keeps "make a video" requests at a length the provider
                    // supports and can render within the time budget.
                    $duration = (int) $modelConfig['default_duration'];
                } else {
                    $duration = 8; // Default to 8 seconds
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
                    'provider' => $provider,
                    'model' => $modelName,
                    'modelConfig' => $modelConfig,
                    'duration' => $duration,
                    'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
                    'resolution' => $resolutionOption,
                    // Forward the provider's poll status as a status update. The
                    // 'message' keeps the legacy single-chat "Generating..." box
                    // working; the structured metadata lets the multitask card
                    // render a live progress bar tagged to its node.
                    'progress_callback' => function (array $progress) use ($progressCallback): void {
                        $elapsed = $progress['elapsed_seconds'] ?? 0;
                        $this->notify($progressCallback, 'generating', "Generating video... ({$elapsed}s)", [
                            'provider_status' => $progress['status'] ?? 'processing',
                            'elapsed_seconds' => $elapsed,
                            'percent' => $progress['percent'] ?? null,
                            'attempt' => $progress['attempt'] ?? null,
                            'max_attempts' => $progress['max_attempts'] ?? null,
                        ]);
                    },
                ];

                // Stop button support: probe the shared cancellation store (set by
                // the global Stop or a per-card Stop on a different worker) plus a
                // client-disconnect check, so the provider poll aborts and tells
                // Higgsfield to cancel instead of finishing an unwanted render.
                $cancelCheck = $this->buildCancelCheck($options);
                if (null !== $cancelCheck) {
                    $videoOptions['cancel_check'] = $cancelCheck;
                }

                // Image-to-video: hand the provider (Higgsfield etc.) a real
                // http(s) image_url to animate. i2v providers require a fetchable
                // URL — a local path or base64 data-URI will not work. Two source
                // types are supported, in priority order:
                //   1. A public image URL the user pasted in their text — already
                //      provider-fetchable, so it is forwarded as-is (no republish,
                //      no internet-reachable APP_URL needed).
                //   2. An attached local file — copied to a public `ai_`-prefixed
                //      URL so the provider can fetch it.
                $referenceImageUrls = $imageUrlsInText;

                foreach ($attachedImagePaths as $attachedImagePath) {
                    $publicImageUrl = $this->publishInputImageForRemoteFetch($attachedImagePath, $message->getUserId());
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
                    $this->notify($progressCallback, 'generating', 'Preparing your image for animation...');
                    $this->logger->info('MediaGenerationHandler: Image-to-video source(s) prepared', [
                        'provider' => $provider,
                        'model' => $modelName,
                        'reference_count' => count($referenceImageUrls),
                        'from_text_url' => [] !== $imageUrlsInText,
                    ]);
                }

                $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
                if ($this->asyncJobsAllowed($effectiveUserId, $options)
                    && $this->aiFacade->supportsAsyncVideo($provider)) {
                    return $this->detachVideoToAsyncJob(
                        $message,
                        $classification,
                        $options,
                        $prompt,
                        $provider,
                        $modelName,
                        $modelId,
                        $videoOptions,
                        $streamCallback,
                        $progressCallback,
                    );
                }

                $result = $this->aiFacade->generateVideo(
                    $prompt,
                    $message->getUserId(),
                    $videoOptions
                );

                $media = $result['videos'] ?? [];
            } elseif ('audio' === $mediaType) {
                // Generate audio using TTS
                $this->logger->info('MediaGenerationHandler: Starting TTS generation', [
                    'provider' => $provider,
                    'model' => $modelName,
                    'text_length' => strlen($prompt),
                ]);

                $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
                if ($this->asyncJobsAllowed($effectiveUserId, $options)) {
                    return $this->detachMediaToAsyncJob(
                        MediaJob::TYPE_AUDIO,
                        $message,
                        $classification,
                        $options,
                        $prompt,
                        $provider,
                        $modelName,
                        $modelId,
                        [],
                        null,
                        $streamCallback,
                        $progressCallback,
                    );
                }

                $result = $this->aiFacade->synthesize(
                    $prompt,
                    $message->getUserId(),
                    [
                        'provider' => $provider,
                        'model' => $modelName,
                        'format' => 'mp3',
                        'language' => $classification['language'] ?? $message->getLanguage(),
                    ]
                );

                // synthesize() returns ['relativePath' => '13/000/00013/2025/01/tts_xxx.mp3', ...]
                // File is already saved to user-based path by AiFacade
                $relativePath = $result['relativePath'];

                $this->logger->info('MediaGenerationHandler: TTS audio generated', [
                    'relativePath' => $relativePath,
                    'provider' => $result['provider'],
                ]);

                // Build display URL for StaticUploadController
                $displayUrl = '/api/v1/files/uploads/'.$relativePath;

                // Register the generated audio as a BFILES row (see image/video
                // branch) so inline TTS output appears in the Generated gallery.
                // Incognito sessions mark it ephemeral for post-session cleanup.
                $this->generatedFileRegistrar->register(
                    $message->getUserId(),
                    $relativePath,
                    $mediaType,
                    $message->getId(),
                    $result['provider'] ?? $provider,
                    ephemeral: !empty($options['incognito']),
                    // #1251: persist the spoken script so Files → describe /
                    // knowledge-base never falls back to Tika MP3 duration.
                    fileText: $prompt,
                );

                // Clean, localized confirmation (the audio player + download is the
                // deliverable). Emitting a token — rendered by the frontend via
                // i18n, like __FILE_GENERATED__ for documents — avoids leaking the
                // raw English "Generated audio:" prefix and the synthesized prompt
                // text (which could contain internal markers) into the bubble.
                $responseText = '__AUDIO_GENERATED__';
                $streamCallback($responseText);

                $this->notify($progressCallback, 'generating', 'Audio generated successfully.');

                $audioMediaUsage = [
                    'characters' => $result['text_length'] ?? mb_strlen($prompt),
                ];

                // Issue #1146: record the cost before returning (see image/video
                // path) so a torn-down worker can't bypass audio billing.
                $recordedMediaUsage = $this->maybeRecordMediaUsage($user, $options, $mediaAction, $modelId, $result['provider'] ?? $provider, $result['model'] ?? $modelName, $audioMediaUsage);

                return [
                    'metadata' => [
                        'provider' => $result['provider'] ?? $provider,
                        'model' => $result['model'] ?? $modelName,
                        'model_id' => $modelId,
                        'local_path' => $relativePath,
                        'media_prompt' => $prompt,
                        'media_type' => $mediaType,
                        'media_usage' => $audioMediaUsage,
                        'usage_recorded' => null !== $recordedMediaUsage,
                        'media_recorded_usage' => $recordedMediaUsage,
                        'file' => [
                            'path' => $displayUrl,
                            'type' => $mediaType,
                        ],
                    ],
                ];
            } else {
                // Generate image (with optional reference images for pic2pic)
                $imageOptions = [
                    'provider' => $provider,
                    'model' => $modelName,
                    'modelConfig' => $modelConfig,
                    'quality' => $options['quality'] ?? ($isPic2Pic ? 'high' : 'standard'),
                    'style' => $options['style'] ?? 'vivid',
                    'size' => $options['size'] ?? '1024x1024',
                ];

                if ($isPic2Pic) {
                    $imageOptions['images'] = $attachedImagePaths;
                    $this->logger->info('MediaGenerationHandler: Passing reference images to provider', [
                        'count' => count($attachedImagePaths),
                        'provider' => $provider,
                        'model' => $modelName,
                    ]);
                }

                $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
                if ($this->asyncJobsAllowed($effectiveUserId, $options)) {
                    $inputRef = $attachedImagePaths[0] ?? null;

                    return $this->detachMediaToAsyncJob(
                        MediaJob::TYPE_IMAGE,
                        $message,
                        $classification,
                        $options,
                        $prompt,
                        $provider,
                        $modelName,
                        $modelId,
                        $imageOptions,
                        is_string($inputRef) ? $inputRef : null,
                        $streamCallback,
                        $progressCallback,
                    );
                }

                // Stop button / disconnect support for the blocking image path
                // (issue #1155). Mirrors the video path above: probe the shared
                // cancellation store plus connection_aborted() so providers that
                // poll for the result (e.g. fal/HuggingFace) abort mid-flight
                // instead of running an unwanted generation to completion. For
                // single-shot providers it is a harmless no-op. Only applied on
                // the synchronous path — never serialized into an async job.
                $cancelCheck = $this->buildCancelCheck($options);
                if (null !== $cancelCheck) {
                    $imageOptions['cancel_check'] = $cancelCheck;
                }

                $result = $this->aiFacade->generateImage(
                    $prompt,
                    $message->getUserId(),
                    $imageOptions
                );

                $media = $result['images'] ?? [];
            }

            $this->logger->info('MediaGenerationHandler: Media generated', [
                'count' => count($media),
                'provider' => $result['provider'],
                'media_type' => $mediaType,
                'raw_result' => json_encode($result),
                'media_sample' => !empty($media) ? json_encode($media[0]) : 'empty',
            ]);

            // Check if media was actually generated
            if (empty($media)) {
                throw new \Exception("No {$mediaType} generated by provider. Response: ".json_encode($result));
            }

            // Download first media and save locally
            $mediaUrl = null;

            if (isset($media[0]['url'])) {
                $mediaUrl = $media[0]['url'];
            } else {
                $this->logger->error('MediaGenerationHandler: No URL in media response', [
                    'media' => json_encode($media[0] ?? null),
                    'result' => json_encode($result),
                ]);
                throw new \Exception("Generated {$mediaType} has no URL. Check provider response format.");
            }

            $this->notify($progressCallback, 'generating', 'Saving '.$mediaTypeLabel.'...');

            $localPath = null;

            if (str_starts_with($mediaUrl, 'data:')) {
                // Data URL from AI provider - decode and save to disk
                // (message id is only used for the filename; 0 for transient
                // incognito messages)
                $localPath = $this->saveDataUrlAsFile($mediaUrl, $message->getId() ?? 0, $message->getUserId(), $provider);
                if (!$localPath) {
                    throw new \Exception("Failed to save generated {$mediaType} data URL to disk");
                }
                $this->logger->info('MediaGenerationHandler: Saved data URL to disk', [
                    'path' => $localPath,
                    'type' => $mediaType,
                ]);
            } else {
                // External URL - download to disk
                $localPath = $this->downloadMedia($mediaUrl, $message->getId() ?? 0, $message->getUserId(), $provider, $mediaType);
                if (!$localPath) {
                    throw new \Exception("Failed to download {$mediaType} from: {$mediaUrl}");
                }
                $this->logger->info('MediaGenerationHandler: Downloaded media to disk', [
                    'path' => $localPath,
                    'type' => $mediaType,
                ]);
            }

            // Build display URL for StaticUploadController
            $displayUrl = '/api/v1/files/uploads/'.$localPath;

            // Register the generated artefact as a first-class BFILES row so it
            // shows in the file manager's Generated gallery. The async path does
            // this via MediaJobMessageSync; the inline (synchronous) path must do
            // it here too, otherwise users on inline rendering never see their
            // chat-generated media in the file world. Best-effort + idempotent.
            // Incognito sessions mark it ephemeral for post-session cleanup.
            $this->generatedFileRegistrar->register(
                $message->getUserId(),
                $localPath,
                $mediaType,
                $message->getId(),
                $result['provider'] ?? $provider,
                ephemeral: !empty($options['incognito']),
            );

            // Generate thumbnail for video files
            $thumbnailPath = null;
            if ('video' === $mediaType) {
                $this->notify($progressCallback, 'generating', 'Creating video thumbnail...');
                $thumbnailPath = $this->thumbnailService->generateThumbnail($localPath);
                if ($thumbnailPath) {
                    $this->logger->info('MediaGenerationHandler: Video thumbnail generated', [
                        'video' => $localPath,
                        'thumbnail' => $thumbnailPath,
                    ]);
                }
            }

            // Stream response with revised prompt
            $revisedPrompt = $media[0]['revised_prompt'] ?? $prompt;
            $responseText = "Generated {$mediaType}: {$revisedPrompt}";

            // Stream the response
            $streamCallback($responseText);

            $this->notify($progressCallback, 'generating', ucfirst($mediaType).' generated successfully.');

            $mediaUsage = [];
            if ('image' === $mediaType) {
                $mediaUsage['images'] = $result['image_count'] ?? 1;
                // Carry the requested quality/size so per-tier image models
                // (gpt-image) bill the exact price (#1315). Mirrors the defaults
                // used when building $imageOptions above.
                $mediaUsage['quality'] = $options['quality'] ?? ($isPic2Pic ? 'high' : 'standard');
                $mediaUsage['size'] = $options['size'] ?? '1024x1024';
            } elseif ('video' === $mediaType) {
                $requestedDuration = $options['duration'] ?? $classification['duration'] ?? 8;
                $duration = $result['duration_seconds'] ?? null;
                if (null === $duration) {
                    $this->logger->warning('MediaGenerationHandler: Provider omitted duration_seconds for video generation. Falling back to requested duration.', [
                        'provider' => $provider,
                        'model' => $modelName,
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

            // Issue #1146: record the cost the moment the provider has billed us,
            // before returning. If the caller owns the cost (record_media_usage),
            // this guarantees BUSELOG is written even if the streaming worker is
            // later torn down by a client disconnect — closing the billing-bypass
            // window. `usage_recorded` tells the caller to skip its own recording.
            $recordedMediaUsage = $this->maybeRecordMediaUsage($user, $options, $mediaAction, $modelId, $result['provider'] ?? $provider, $result['model'] ?? $modelName, $mediaUsage);

            return [
                'metadata' => [
                    'provider' => $result['provider'] ?? $provider,
                    'model' => $result['model'] ?? $modelName,
                    'model_id' => $modelId,
                    'image_url' => $mediaUrl,
                    'local_path' => $localPath,
                    'thumbnail_path' => $thumbnailPath,
                    'media_prompt' => $prompt,
                    'media_type' => $mediaType,
                    'media_usage' => $mediaUsage,
                    'usage_recorded' => null !== $recordedMediaUsage,
                    'media_recorded_usage' => $recordedMediaUsage,
                    'file' => [
                        'path' => $displayUrl,
                        'type' => $mediaType,
                    ],
                ],
            ];
        } catch (ProviderCancelledException $e) {
            // User pressed Stop — neutral outcome, not a failure. We deliberately
            // do NOT stream a content marker (it would leak raw into the single
            // chat bubble and is unhandled by the frontend); the `cancelled`
            // metadata flag is the signal, and the client already renders the
            // cancelled card state from its own Stop action.
            $this->logger->info('MediaGenerationHandler: Generation cancelled by user', [
                'provider' => $provider,
                'model' => $modelName,
                'media_type' => $mediaType,
            ]);

            // Issue #1146: a cancelled generation is NOT free. Video providers
            // bill at job submission and image providers bill once the request
            // is accepted, so the provider already charged us by the time the
            // poll was aborted. Record that cost against the user's budget so a
            // Stop-then-restart loop can't be used to bypass billing. The cost
            // is deterministic from the requested duration/resolution (video) or
            // a single image, mirroring the success-path media_usage shape.
            $cancelledMediaUsage = $this->buildCancelledMediaUsage($mediaType, $options, $classification, $result ?? null);
            $recordedMediaUsage = $this->maybeRecordMediaUsage($user, $options, $mediaAction, $modelId, $provider, $modelName, $cancelledMediaUsage);

            return [
                'metadata' => [
                    'provider' => $provider,
                    'model' => $modelName,
                    'model_id' => $modelId,
                    'media_type' => $mediaType,
                    'media_usage' => $cancelledMediaUsage,
                    'cancelled' => true,
                    'usage_recorded' => null !== $recordedMediaUsage,
                    'media_recorded_usage' => $recordedMediaUsage,
                    'error' => 'cancelled',
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('MediaGenerationHandler: Generation failed', [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'model' => $modelName,
                'media_type' => $mediaType,
                'exception' => $e,
            ]);

            $lang = $classification['language'] ?? 'en';
            // Admins get the raw provider error/cause appended for diagnosis;
            // regular users only see the clean, non-leaky message.
            $userMessage = $this->errorMessageBuilder->buildErrorMessage($e, $mediaType, $lang, $user?->isAdmin() ?? false);
            $streamCallback($userMessage);

            return [
                'metadata' => [
                    'provider' => $provider,
                    'model' => $modelName,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Save a data URL (base64 encoded) as a file on disk.
     *
     * @param string $dataUrl   the data URL (e.g., data:image/png;base64,...)
     * @param int    $messageId the message ID for filename
     * @param int    $userId    the user ID for path generation
     * @param string $provider  the AI provider name (google, openai, etc.)
     *
     * @return string|null relative path from uploads dir or null on failure
     */
    private function saveDataUrlAsFile(string $dataUrl, int $messageId, int $userId, string $provider): ?string
    {
        // Parse: data:image/png;base64,XXXX
        if (!preg_match('#^data:([^;]+);base64,(.+)$#', $dataUrl, $matches)) {
            $this->logger->error('MediaGenerationHandler: Invalid data URL format');

            return null;
        }

        $mimeType = $matches[1];
        $base64Data = $matches[2];
        $content = base64_decode($base64Data, true);

        if (false === $content || '' === $content) {
            $this->logger->error('MediaGenerationHandler: Failed to decode base64 data');

            return null;
        }

        // Determine extension and sanitize provider
        $extension = FileHelper::getExtensionFromMimeType($mimeType);
        $sanitizedProvider = FileHelper::sanitizeProviderName($provider);

        // Generate filename: messageId_provider_timestamp.ext
        $timestamp = time();
        $filename = sprintf('%d_%s_%d.%s', $messageId, $sanitizedProvider, $timestamp, $extension);

        // Build storage path with user subdirectories: {last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}
        $year = date('Y');
        $month = date('m');
        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        // Create directory if not exists
        if (!FileHelper::ensureParentDirectory($absolutePath)) {
            $this->logger->error('MediaGenerationHandler: Failed to create upload directory', ['dir' => dirname($absolutePath)]);

            return null;
        }

        // Save file with proper permissions
        $bytesWritten = FileHelper::writeFile($absolutePath, $content);

        if (false === $bytesWritten) {
            $this->logger->error('MediaGenerationHandler: Failed to write file', ['path' => $absolutePath]);

            return null;
        }

        $this->logger->info('MediaGenerationHandler: Saved data URL as file', [
            'relative_path' => $relativePath,
            'mime_type' => $mimeType,
            'size' => $bytesWritten,
        ]);

        return $relativePath;
    }

    /**
     * Download media from URL to local storage.
     *
     * @param string $url       the media URL to download
     * @param int    $messageId the message ID for filename
     * @param int    $userId    the user ID for path generation
     * @param string $provider  the AI provider name
     * @param string $mediaType the type of media (image, video, audio)
     *
     * @return string|null relative path from uploads dir or null on failure
     */
    private function downloadMedia(string $url, int $messageId, int $userId, string $provider, string $mediaType): ?string
    {
        try {
            $this->logger->info('MediaGenerationHandler: Starting download', [
                'url' => $url,
                'upload_dir' => $this->uploadDir,
            ]);

            // Download with cURL
            $content = null;
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP; Synaplan)');
                // Force fresh DNS lookup and prefer IPv4 to avoid Docker DNS issues
                curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 0);
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $content = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if (false === $content || 200 !== $httpCode) {
                    throw new \Exception("cURL download failed (HTTP {$httpCode}): {$curlError}");
                }
            } else {
                $content = @file_get_contents($url);
                if (false === $content) {
                    throw new \Exception('file_get_contents download failed');
                }
            }

            if (empty($content)) {
                throw new \Exception('Downloaded content is empty');
            }

            // Detect MIME type from content
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($content);

            // Determine extension with media-type-aware fallback
            $fallback = 'image' === $mediaType ? 'png' : ('video' === $mediaType ? 'mp4' : 'bin');
            $extension = FileHelper::getExtensionFromMimeType($mimeType, $fallback);
            $sanitizedProvider = FileHelper::sanitizeProviderName($provider);

            // Generate filename: messageId_provider_timestamp.ext
            $timestamp = time();
            $filename = sprintf('%d_%s_%d.%s', $messageId, $sanitizedProvider, $timestamp, $extension);

            // Build storage path with user subdirectories: {last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}
            $year = date('Y');
            $month = date('m');
            $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
            $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
            $absolutePath = $this->uploadDir.'/'.$relativePath;

            // Create directory if not exists
            if (!FileHelper::ensureParentDirectory($absolutePath)) {
                throw new \Exception('Failed to create upload directory: '.dirname($absolutePath));
            }

            // Save to disk with proper permissions
            $bytesWritten = FileHelper::writeFile($absolutePath, $content);
            if (false === $bytesWritten) {
                throw new \Exception('Failed to save media to disk');
            }

            $this->logger->info('MediaGenerationHandler: Media downloaded successfully', [
                'relative_path' => $relativePath,
                'bytes' => $bytesWritten,
                'mime_type' => $mimeType,
            ]);

            return $relativePath;
        } catch (\Exception $e) {
            $this->logger->error('MediaGenerationHandler: Failed to download media', [
                'error' => $e->getMessage(),
                'url' => FileHelper::redactUrlForLogging($url),
            ]);

            return null;
        }
    }

    /**
     * Publish a locally-stored input image at a public, internet-reachable URL
     * so a remote image-to-video provider can fetch it.
     *
     * The image is copied to an `ai_`-prefixed filename in the user's upload
     * tree. StaticUploadController serves `ai_*` files without authentication
     * (the same public bypass already used for AI-generated media), so the
     * resulting absolute URL is fetchable by the provider. Returns null when
     * the file can't be read/written or no public base URL is configured.
     *
     * @param string $absoluteLocalPath absolute path to the source image on disk
     * @param int    $userId            owner user id (for the upload sub-tree)
     *
     * @return string|null absolute public URL (e.g. https://host/api/v1/files/uploads/...), or null
     */
    private function publishInputImageForRemoteFetch(string $absoluteLocalPath, int $userId): ?string
    {
        $base = rtrim($this->publicBaseUrl, '/');
        if ('' === $base) {
            $this->logger->warning('MediaGenerationHandler: APP_URL not configured; cannot publish input image for image-to-video');

            return null;
        }

        if (!is_file($absoluteLocalPath)) {
            $this->logger->warning('MediaGenerationHandler: Input image not found on disk', ['path' => $absoluteLocalPath]);

            return null;
        }

        // Remote providers can only fetch publicly-resolvable hosts. localhost /
        // private hosts (typical in dev) will be rejected by the provider — warn
        // so the failure is diagnosable, but still return the URL so a public
        // deployment behind a localhost-looking proxy is not blocked here.
        // (The handler also guards this up front via isPublicBaseUrlReachable().)
        if (!$this->isPublicBaseUrlReachable()) {
            $this->logger->warning('MediaGenerationHandler: APP_URL is not internet-reachable; image-to-video providers will not be able to fetch the source image', [
                'app_url' => $base,
            ]);
        }

        $extension = strtolower(pathinfo($absoluteLocalPath, PATHINFO_EXTENSION)) ?: 'jpg';
        // `ai_` prefix => served without auth by StaticUploadController.
        $filename = sprintf('ai_i2vsrc_%d_%d_%s.%s', $userId, time(), bin2hex(random_bytes(6)), $extension);

        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.date('Y').'/'.date('m').'/'.$filename;
        $absoluteTarget = $this->uploadDir.'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($absoluteTarget)) {
            $this->logger->error('MediaGenerationHandler: Failed to create directory for input image copy', ['dir' => dirname($absoluteTarget)]);

            return null;
        }

        if (!@copy($absoluteLocalPath, $absoluteTarget)) {
            $this->logger->error('MediaGenerationHandler: Failed to copy input image to public path', [
                'source' => $absoluteLocalPath,
                'target' => $absoluteTarget,
            ]);

            return null;
        }

        FileHelper::setFilePermissions($absoluteTarget);

        return $base.'/api/v1/files/uploads/'.$relativePath;
    }

    /**
     * Is the configured public base URL (APP_URL) one that a remote AI provider
     * could realistically fetch from? Returns false for an empty value and for
     * hosts that are not routable from the public internet: loopback,
     * RFC 1918 / RFC 4193 private ranges, link-local, and non-public TLDs
     * (.local / .localhost / .internal / .lan / .home / .test). Used to
     * short-circuit image-to-video requests (whose source frame must be
     * provider-fetchable) with an actionable message instead of a raw
     * provider 400 ("invalid_image_url").
     */
    private function isPublicBaseUrlReachable(): bool
    {
        $base = rtrim($this->publicBaseUrl, '/');
        if ('' === $base) {
            return false;
        }

        $host = parse_url($base, \PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return false;
        }

        // Normalise an IPv6 literal ("[::1]" → "::1") before inspection.
        $host = trim($host, '[]');
        $lowerHost = strtolower($host);

        // Obvious local hostnames + non-public TLD suffixes.
        if ('localhost' === $lowerHost || 'host.docker.internal' === $lowerHost) {
            return false;
        }
        foreach (['.local', '.localhost', '.internal', '.lan', '.home', '.test'] as $suffix) {
            if (str_ends_with($lowerHost, $suffix)) {
                return false;
            }
        }

        // If the host is an IP literal, reject private + reserved ranges
        // (covers 10.x, 172.16–31.x, 192.168.x, 169.254.x, 127.x, ::1, fc00::/7, …).
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return false !== filter_var(
                $host,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
            );
        }

        // A non-IP hostname that isn't one of the local cases above is assumed
        // to resolve publicly (a real DNS name behind a proxy/tunnel).
        return true;
    }

    /**
     * Collect absolute paths of image attachments from the message.
     *
     * @param list<string> $extraImagePaths reference images supplied by the caller
     *                                      (issue #1144) — relative to the upload
     *                                      dir, an absolute path, or a public
     *                                      `/api/v1/files/uploads/...` display URL
     *
     * @return list<string> absolute, on-disk image paths to attached images
     */
    private function collectAttachedImagePaths(Message $message, array $extraImagePaths = []): array
    {
        $paths = [];
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        foreach ($message->getFiles() as $file) {
            $ext = strtolower(pathinfo($file->getFilePath(), PATHINFO_EXTENSION));
            if (in_array($ext, $imageExtensions, true)) {
                $absolutePath = $this->uploadDir.'/'.$file->getFilePath();
                if (file_exists($absolutePath)) {
                    $paths[] = $absolutePath;
                }
            }
        }

        if (empty($paths)) {
            $legacyPath = $message->getFilePath();
            if ($legacyPath) {
                $ext = strtolower(pathinfo($legacyPath, PATHINFO_EXTENSION));
                if (in_array($ext, $imageExtensions, true)) {
                    $absolutePath = $this->uploadDir.'/'.$legacyPath;
                    if (file_exists($absolutePath)) {
                        $paths[] = $absolutePath;
                    }
                }
            }
        }

        // Merge caller-supplied reference images (multitask media-to-media chains).
        // Each candidate is normalised to an absolute on-disk path and filtered by
        // image extension + existence, exactly like the message's own uploads.
        foreach ($extraImagePaths as $candidate) {
            if ('' === $candidate) {
                continue;
            }
            $absolutePath = $this->normalizeReferenceImagePath($candidate);
            if (null === $absolutePath) {
                continue;
            }
            $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
            if (in_array($ext, $imageExtensions, true) && !in_array($absolutePath, $paths, true)) {
                $paths[] = $absolutePath;
            }
        }

        return $paths;
    }

    /**
     * Normalise caller-supplied reference image URLs (multitask path: the URL
     * lives in the original user message, not the synthetic node prompt). Each
     * candidate is run through the same allowlist as
     * {@see FileHelper::extractImageUrls()}, so only http(s) URLs ending in a
     * known image extension survive — these values are handed to providers as
     * `image_url`/`images`, so a non-image / non-http(s) target must never pass.
     * De-duplicated in first-seen order.
     *
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function referenceImageUrlsFromOptions(array $options): array
    {
        $candidates = $options['reference_image_urls'] ?? null;
        if (!is_array($candidates)) {
            return [];
        }

        $urls = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            foreach (FileHelper::extractImageUrls($candidate) as $url) {
                if (!in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Resolve a caller-supplied reference image reference to an absolute on-disk
     * path (issue #1144). Accepts an absolute path, a path relative to the upload
     * dir, or a public `/api/v1/files/uploads/...` display URL. Returns null when
     * the file cannot be found.
     */
    private function normalizeReferenceImagePath(string $candidate): ?string
    {
        // Strip the public display prefix so a `$nX.file` `path` resolves to the
        // same relative key the upload dir uses.
        $relative = preg_replace('#^/?api/v1/files/uploads/#', '', $candidate);
        $relative = null === $relative ? $candidate : $relative;

        // Already an absolute, existing path.
        if (str_starts_with($candidate, '/') && file_exists($candidate)) {
            return $candidate;
        }

        $absolute = $this->uploadDir.'/'.ltrim($relative, '/');
        if (file_exists($absolute)) {
            return $absolute;
        }

        $this->logger->warning('MediaGenerationHandler: reference image not found on disk', [
            'candidate' => $candidate,
        ]);

        return null;
    }

    /**
     * Resolve media type, provider, and model name from a model ID.
     *
     * @return array{string, ?string, ?string} [$mediaType, $provider, $modelName]
     */
    private function resolveMediaTypeFromModelId(int $modelId, bool $isSlashCommand, string $mediaType, ?string $provider, ?string $modelName): array
    {
        if ($isSlashCommand) {
            return [$mediaType, $provider, $modelName];
        }

        $model = $this->em->getRepository(\App\Entity\Model::class)->find($modelId);
        if ($model) {
            $tag = $model->getTag();
            if ('text2vid' === $tag) {
                $mediaType = 'video';
            } elseif ('text2sound' === $tag) {
                $mediaType = 'audio';
            }
            $provider = $model->getService();
            $modelName = $model->getName();
        }

        return [$mediaType, $provider, $modelName];
    }

    /**
     * Dispatch a background memory-extraction job for the user's prompt
     * text, when memory extraction is enabled for this user/request.
     *
     * Mirrors the gating in ChatHandler so the contract stays uniform:
     *   - Widget / `disable_memories` requests skip extraction.
     *   - User-disabled memories (`User::isMemoriesEnabled()`) skip.
     *   - PERF.V2_PIPELINE kill-switch skip.
     *   - Empty user text skip (nothing to extract from).
     *
     * The actual extraction LLM call + Qdrant writes happen on the
     * messenger worker — same async queue ChatHandler uses — so a failure
     * here NEVER blocks media generation for the user.
     */
    private function maybeDispatchMemoryExtraction(
        Message $message,
        array $thread,
        array $classification,
        array $options,
    ): void {
        $userText = trim($message->getText());
        if ('' === $userText) {
            return;
        }

        // Incognito: memories are read-only — extraction would write
        // conversation content to Qdrant, which the mode forbids.
        if (!empty($options['incognito'])) {
            $this->logger->debug('MediaGenerationHandler: Skipping memory extraction (incognito)', [
                'user_id' => $message->getUserId(),
            ]);

            return;
        }

        $memoriesDisabledByRequest = !empty($options['disable_memories'])
            || ('WIDGET' === ($options['channel'] ?? null))
            || ('widget' === ($classification['source'] ?? null))
            || !empty($classification['is_widget_mode']);

        if ($memoriesDisabledByRequest) {
            $this->logger->debug('MediaGenerationHandler: Skipping memory extraction (widget/disabled)', [
                'user_id' => $message->getUserId(),
            ]);

            return;
        }

        $userId = $message->getUserId();
        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user || !$user->isMemoriesEnabled()) {
            return;
        }

        if (!$this->perfPipelineFlag->isEnabled($userId)) {
            $this->logger->debug('MediaGenerationHandler: PERF.V2_PIPELINE disabled, skipping memory extraction', [
                'user_id' => $userId,
            ]);

            return;
        }

        try {
            $threadSnapshot = $this->normalizeThreadForQueue($thread);

            // Media generation does not produce a textual assistant
            // response that's useful for memory extraction — the
            // response is the generated asset itself. Pass an empty
            // aiResponse so the extractor only looks at the user
            // turn(s), which is exactly what we want.
            $this->messageBus->dispatch(new ExtractMemoriesCommand(
                messageId: $message->getId(),
                userId: $userId,
                aiResponse: '',
                threadSnapshot: $threadSnapshot,
            ));

            $this->logger->info('MediaGenerationHandler: Dispatched ExtractMemoriesCommand', [
                'message_id' => $message->getId(),
                'user_id' => $userId,
                'thread_length' => count($threadSnapshot),
            ]);
        } catch (\Throwable $e) {
            // Never block media generation on a queue dispatch hiccup.
            $this->logger->warning('MediaGenerationHandler: Failed to dispatch ExtractMemoriesCommand', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Flatten the thread (Message entities + plain arrays) into a JSON-
     * serialisable shape for the messenger queue.
     *
     * Doctrine entities cannot survive the queue boundary; the extractor
     * only needs `role`+`content` so we reduce to that. Mirrors the
     * implementation in ChatHandler::normalizeThreadForQueue() — kept in
     * sync but duplicated to avoid leaking a private helper across
     * handler classes.
     *
     * @param array<int, mixed> $thread
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function normalizeThreadForQueue(array $thread): array
    {
        $out = [];
        foreach ($thread as $entry) {
            if ($entry instanceof Message) {
                $out[] = [
                    'role' => 'IN' === $entry->getDirection() ? 'user' : 'assistant',
                    'content' => $entry->getText(),
                ];
                continue;
            }

            if (is_array($entry)) {
                $role = (string) ($entry['role'] ?? 'user');
                $content = $entry['content'] ?? '';
                if (!is_string($content)) {
                    $encoded = json_encode($content);
                    $content = false === $encoded ? '' : $encoded;
                }
                $out[] = ['role' => $role, 'content' => $content];
            }
        }

        return $out;
    }

    /**
     * Whether this render may detach to a background {@see MediaJob}.
     *
     * The async backbone is the default, but a caller can force the blocking
     * inline path via `options['force_inline_media']`. The multitask DAG uses
     * this for a media node another node depends on: the downstream
     * `file_analysis`/`compose_reply` needs the produced file in the SAME turn,
     * which an async detach could never deliver (#1218).
     *
     * @param array<string, mixed> $options
     */
    private function asyncJobsAllowed(int $effectiveUserId, array $options): bool
    {
        if (!empty($options['force_inline_media'])) {
            return false;
        }

        return $this->mediaJobConfig->isAsyncJobsEnabled($effectiveUserId);
    }

    /**
     * Notify progress callback.
     */
    /**
     * Stage a video render as a background {@see MediaJob} and return immediately
     * with a `running` placeholder (Release 4.0 Sprint B). The advancer owns the
     * provider submit/poll/finalize loop; billing stays on the terminal path (Sprint E).
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{metadata: array<string, mixed>}
     */
    /**
     * Detach a media render (image, video, or audio) to a background job and
     * return a `running` placeholder immediately. Video runs the async
     * submit→poll→finalize loop on the worker; image and audio run as a single
     * synchronous worker step ({@see \App\Service\Media\SyncMediaJobGenerator}).
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     * @param array<string, mixed> $mediaOptions   provider options (image/video/audio specifics)
     *
     * @return array{metadata: array<string, mixed>}|array{text: string, metadata: array<string, mixed>}
     */
    private function detachMediaToAsyncJob(
        string $type,
        Message $message,
        array $classification,
        array $options,
        string $prompt,
        string $provider,
        ?string $modelName,
        ?int $modelId,
        array $mediaOptions,
        ?string $inputRef,
        callable $streamCallback,
        ?callable $progressCallback,
    ): array {
        $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        $mediaOptions['lang'] = is_string($classification['language'] ?? null)
            ? $classification['language']
            : ($message->getLanguage() ?: 'en');
        $lang = (string) $mediaOptions['lang'];

        // Per-user concurrency ceiling: never queue past the limit so one user
        // can't flood the worker or pile up provider cost. The rate-limit gate
        // (checkLimit) already ran upstream; this bounds in-flight parallelism.
        $maxActive = $this->mediaJobConfig->maxActiveJobsPerUser();
        if ($this->mediaJobService->countActiveForUser($effectiveUserId) >= $maxActive) {
            $limitMessage = $this->errorMessageBuilder->buildTooManyJobsMessage($lang);
            $streamCallback($limitMessage);
            $this->notify($progressCallback, 'error', $limitMessage);
            $this->logger->info('MediaGenerationHandler: media job rejected — per-user concurrency limit reached', [
                'user_id' => $effectiveUserId,
                'limit' => $maxActive,
                'type' => $type,
            ]);

            return [
                'text' => $limitMessage,
                'metadata' => [
                    'provider' => $provider,
                    'model' => $modelName,
                    'model_id' => $modelId,
                    'media_prompt' => $prompt,
                    'media_type' => $type,
                    'error' => $limitMessage,
                ],
            ];
        }

        $chatId = null;
        $chat = $message->getChat();
        if (null !== $chat) {
            $chatId = $chat->getId();
        }

        $nodeId = isset($options['node_id']) && is_scalar($options['node_id'])
            ? trim((string) $options['node_id'])
            : null;
        $trackId = isset($options['track_id']) && is_scalar($options['track_id'])
            ? trim((string) $options['track_id'])
            : null;

        // Stash the billable usage payload so the worker can record it when the
        // job completes (Sprint E, #1146). Mirrors the inline path's media_usage
        // shape so RateLimitService computes the identical cost.
        $mediaOptions['media_usage'] = $this->detachMediaUsage($type, $prompt, $classification, $options, $mediaOptions);

        $job = $this->mediaJobService->create([
            'userId' => $effectiveUserId,
            'type' => $type,
            'provider' => $provider,
            'prompt' => $prompt,
            'modelId' => $modelId,
            'model' => $modelName,
            'chatId' => $chatId,
            'messageId' => $message->getId(),
            'nodeId' => '' !== (string) $nodeId ? $nodeId : null,
            'trackId' => '' !== $trackId ? $trackId : null,
            'inputRef' => $inputRef,
            'options' => $mediaOptions,
        ]);

        if (!$this->mediaJobDispatcher->dispatch($job)) {
            // Transport (Redis) unreachable — fail the job synchronously so the
            // user gets a clear, localized error in the same turn instead of an
            // empty bubble that polls forever waiting for a worker that will
            // never be reached.
            // $mediaOptions['lang'] is always set at the top of this method.
            $lang = (string) $mediaOptions['lang'];
            $errorMessage = $this->errorMessageBuilder->buildErrorMessage(
                new \RuntimeException('Background queue unreachable'),
                $type,
                $lang,
            );
            $this->mediaJobService->markFailed($job, $errorMessage);
            $this->mediaJobMessageSync->syncTerminalState($job);

            $streamCallback($errorMessage);
            $this->notify($progressCallback, 'error', $errorMessage, [
                'media_job' => [
                    'job_id' => $job->getJobKey(),
                    'type' => $type,
                    'state' => 'failed',
                    'error' => $errorMessage,
                ],
            ]);

            $this->logger->error('MediaGenerationHandler: detach failed — queue dispatch rejected', [
                'job_key' => $job->getJobKey(),
                'provider' => $provider,
                'model' => $modelName,
                'message_id' => $message->getId(),
                'type' => $type,
            ]);

            return [
                'text' => $errorMessage,
                'metadata' => [
                    'provider' => $provider,
                    'model' => $modelName,
                    'model_id' => $modelId,
                    'media_prompt' => $prompt,
                    'media_type' => $type,
                    'media_job' => [
                        'job_id' => $job->getJobKey(),
                        'type' => $type,
                        'state' => 'failed',
                        'error' => $errorMessage,
                    ],
                    'error' => $errorMessage,
                ],
            ];
        }

        $mediaJob = [
            'job_id' => $job->getJobKey(),
            'type' => $type,
            'state' => 'running',
        ];

        // Placeholder token — the frontend renders a dedicated background-job
        // banner (MediaJobStatus) and persists this marker so reloads are not empty.
        $streamCallback($this->generatingTokenFor($type));

        $this->notify($progressCallback, 'generating', 'Media generation started in the background.', [
            'media_job' => $mediaJob,
        ]);

        $this->logger->info('MediaGenerationHandler: Detached media to async job', [
            'job_key' => $job->getJobKey(),
            'type' => $type,
            'provider' => $provider,
            'model' => $modelName,
            'message_id' => $message->getId(),
            'node_id' => $nodeId,
        ]);

        return [
            'metadata' => [
                'provider' => $provider,
                'model' => $modelName,
                'model_id' => $modelId,
                'media_prompt' => $prompt,
                'media_type' => $type,
                'media_job' => $mediaJob,
            ],
        ];
    }

    /**
     * Video detach: derives the image-to-video reference (if any) and delegates
     * to the generic media-detach path.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     * @param array<string, mixed> $videoOptions
     *
     * @return array{metadata: array<string, mixed>}|array{text: string, metadata: array<string, mixed>}
     */
    private function detachVideoToAsyncJob(
        Message $message,
        array $classification,
        array $options,
        string $prompt,
        string $provider,
        ?string $modelName,
        ?int $modelId,
        array $videoOptions,
        callable $streamCallback,
        ?callable $progressCallback,
    ): array {
        $inputRef = isset($videoOptions['image_url']) && is_string($videoOptions['image_url'])
            ? $videoOptions['image_url']
            : null;

        return $this->detachMediaToAsyncJob(
            MediaJob::TYPE_VIDEO,
            $message,
            $classification,
            $options,
            $prompt,
            $provider,
            $modelName,
            $modelId,
            $videoOptions,
            $inputRef,
            $streamCallback,
            $progressCallback,
        );
    }

    /**
     * Billable usage payload to persist on a detached job, mirroring the
     * inline success-path `media_usage` shape (see {@see maybeRecordMediaUsage}).
     * Values are known at request time: image = 1 asset, audio = prompt length,
     * video = requested duration (the inline path falls back to the same).
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     * @param array<string, mixed> $mediaOptions   resolved provider options (see $imageOptions in handleStream)
     *
     * @return array<string, mixed>
     */
    private function detachMediaUsage(string $type, string $prompt, array $classification, array $options, array $mediaOptions): array
    {
        return match ($type) {
            MediaJob::TYPE_VIDEO => [
                'duration_seconds' => (float) ($options['duration'] ?? $classification['duration'] ?? 8),
            ],
            MediaJob::TYPE_AUDIO => ['characters' => mb_strlen($prompt)],
            // Carry quality/size so per-tier image models (gpt-image) bill the
            // exact price when the async job is later recorded (#1315). Read
            // from the RESOLVED provider options, not the raw request options:
            // $imageOptions already applied the pic2pic default ('high'), and
            // billing must follow what was actually sent to the provider.
            default => [
                'images' => 1,
                'quality' => $mediaOptions['quality'] ?? $options['quality'] ?? 'standard',
                'size' => $mediaOptions['size'] ?? $options['size'] ?? '1024x1024',
            ],
        };
    }

    /**
     * Frontend placeholder token per media type. The dedicated MediaJobStatus
     * banner is the real running UI; this token is the streamed/persisted
     * marker that MessageText maps to a localized "generating…" line so a
     * reload is never an empty bubble.
     */
    private function generatingTokenFor(string $type): string
    {
        return match ($type) {
            MediaJob::TYPE_IMAGE => '__IMAGE_GENERATING__',
            MediaJob::TYPE_AUDIO => '__AUDIO_GENERATING__',
            default => '__VIDEO_GENERATING__',
        };
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
            return $this->rateLimitService->recordUsage($user, $mediaAction, [
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

    private function notify(?callable $callback, string $status, string $message, array $metadata = []): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata,
                'timestamp' => time(),
            ]);
        }
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
