<?php

namespace App\Service\Message\Handler;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Destination\RequestedFolderDelivery;
use App\Service\File\ThumbnailService;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Media\RenderedImageSize;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\Message\Handler\Media\MediaModelResolver;
use App\Service\Message\Handler\Media\MediaPromptResolver;
use App\Service\Message\Handler\Media\MediaReferenceResolver;
use App\Service\Message\Handler\Media\MediaUploadStore;
use App\Service\Message\ProgressCallback;
use App\Service\Message\TtsScriptGuard;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
    /** Marker stored on a turn that TtsScriptGuard handed to the chat answer. */
    public const REROUTED_FROM_AUDIO = 'mediamaker:audio';

    public function __construct(
        private AiFacade $aiFacade,
        private MediaModelResolver $modelResolver,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private MediaPromptResolver $promptResolver,
        private MediaUploadStore $uploadStore,
        private ThumbnailService $thumbnailService,
        private RateLimitService $rateLimitService,
        private MediaErrorMessageBuilder $errorMessageBuilder,
        private PendingMemoryExtraction $pendingMemoryExtraction,
        private MediaCancellationStore $cancellationStore,
        private MediaJobConfig $mediaJobConfig,
        private MediaJobService $mediaJobService,
        private MediaJobDispatcher $mediaJobDispatcher,
        private MediaJobMessageSync $mediaJobMessageSync,
        private GeneratedFileRegistrar $generatedFileRegistrar,
        private PremiumFeatureGate $premiumFeatureGate,
        private MediaReferenceResolver $referenceResolver,
        private string $uploadDir = '/var/www/backend/var/uploads',
        // Public base URL that serves /api/v1/files/uploads/* (same value used
        // by OgImageService / shared chat pages). Needed for image-to-video:
        // Higgsfield (and most i2v providers) fetch the source frame from a
        // public http(s) URL, so the user's attached image must be exposed at
        // an absolute, internet-reachable URL — not a local filesystem path.
        #[Autowire('%env(APP_URL)%')]
        private string $publicBaseUrl = '',
        private ?RequestedFolderDelivery $folderDelivery = null,
        // Answers the turn as normal chat when a sorter-routed audio request
        // turns out to carry nothing speakable (see TtsScriptGuard).
        private ?ChatHandler $chatHandler = null,
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
        ProgressCallback::notify($progressCallback, 'analyzing', 'Understanding your request...');

        // Background memory extraction on the user's prompt is dispatched
        // further down, once the turn is confirmed to stay on the media path
        // (after the TtsScriptGuard re-route decision) but still BEFORE the
        // slow, possibly-failing provider call. Mirrors ChatHandler: memories
        // must be picked up from any user turn that carries personal
        // information (issue #880), and a failed image generation still saves
        // "ich liebe Hunde". A turn handed to ChatHandler instead is extracted
        // there — dispatching here as well would queue the same message twice.

        $references = $this->referenceResolver->resolve($message, $thread, $classification, $options);
        $mediaPrompt = $this->promptResolver->resolve($message, $thread, $classification, $references, $progressCallback);
        $choice = $this->modelResolver->choose($message, $classification, $mediaPrompt);
        if (null === $choice) {
            $lang = $classification['language'] ?? 'en';
            $clarification = 'de' === $lang
                ? 'Ich konnte nicht erkennen, welche Art von Medium du erstellen möchtest. '
                    .'Bitte verwende einen der folgenden Befehle: `/pic` für Bilder, `/vid` für Videos, `/tts` für Audio.'
                : 'I couldn\'t determine what type of media you want to generate. '
                    .'Please use one of the following commands: `/pic` for images, `/vid` for videos, `/tts` for audio.';

            $streamCallback($clarification);
            $this->maybeDispatchMemoryExtraction($message, $thread, $classification, $options);

            return [
                'metadata' => [
                    'error' => 'media_type_not_determined',
                ],
            ];
        }

        $prompt = $mediaPrompt->text;
        $mediaType = $choice->mediaType;
        $isSlashCommand = $choice->isSlashCommand;
        $attachedImagePaths = $choice->references->attachedImagePaths;
        $imageUrlsInText = $choice->references->imageUrlsInText;
        $editSource = $choice->references->editSource;
        $isPic2Pic = $choice->references->isPic2Pic();
        $hasVideoReferenceImage = $choice->references->hasVideoReferenceImage();

        // A sorter-routed audio turn whose "script" is just the user's request
        // ("teach me the Persian numbers with a children's song") has nothing
        // to read out: the sorter mistook a content request for TTS. Speaking
        // it would play the user's own question back as an MP3, so answer the
        // request as ordinary chat instead. `/tts` and "Again" re-runs are
        // exempt — there the user explicitly chose to hear that exact text.
        if ('audio' === $mediaType
            && !$isSlashCommand
            && null === $message->getMeta('media_prompt_override')
            && TtsScriptGuard::echoesInstruction($prompt, $message->getText())
        ) {
            return $this->answerAsChatInstead($message, $thread, $classification, $streamCallback, $progressCallback, $options, $prompt);
        }

        // The turn stays on the media path: queue memory extraction now (see
        // the note at the top of this method).
        $this->maybeDispatchMemoryExtraction($message, $thread, $classification, $options);

        $resolved = $this->modelResolver->resolve($message, $choice);
        $modelId = $resolved->id;
        $provider = $resolved->provider;
        $modelName = $resolved->name;
        $modelConfig = $resolved->config;

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

        // Providers implementing SupportsInlineReferenceImage take the reference
        // frame as inline bytes, so they need neither a republished file nor a
        // publicly reachable APP_URL.
        $inlineReferenceImage = 'video' === $mediaType
            && $this->aiFacade->supportsInlineReferenceImage($provider);

        // Guard: image-to-video requires the remote provider to FETCH the
        // attached source frame from a public http(s) URL (we republish it at
        // APP_URL/api/v1/files/uploads/...). When APP_URL points at a
        // local/private host — the default in local dev (http://localhost:8000)
        // — that URL is unreachable from the provider's servers and the request
        // is rejected with a confusing raw "invalid_image_url" 400. Detect this
        // up front and return an actionable message instead of burning a
        // provider call (and credits) on a request that cannot succeed.
        if ('video' === $mediaType && !$inlineReferenceImage && [] !== $attachedImagePaths && [] === $imageUrlsInText && !$this->uploadStore->isPublicBaseUrlReachable()) {
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
        // Structured fields let the client narrate this in the user's language
        // ("Generating your image with …") instead of echoing the English text.
        ProgressCallback::notify($progressCallback, 'generating', $statusMessage, [
            'provider' => $provider,
            'model_name' => $modelName,
            'media_type' => $mediaTypeLabel,
            'stage' => 'request_sent',
        ]);

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
            if ($this->premiumFeatureGate->isCostBudgetEnforced()) {
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
                        ProgressCallback::notify($progressCallback, 'generating', "Generating video... ({$elapsed}s)", [
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
                // http(s) image_url to animate. Most i2v providers require a
                // fetchable URL — a local path or base64 data-URI will not work.
                // Three source types are supported, in priority order:
                //   1. A public image URL the user pasted in their text — already
                //      provider-fetchable, so it is forwarded as-is (no republish,
                //      no internet-reachable APP_URL needed).
                //   2. An attached local file on a SupportsInlineReferenceImage
                //      provider (xAI) — forwarded as the local path, which the
                //      provider inlines as a data URI. No public URL needed.
                //   3. An attached local file otherwise — copied to a public
                //      `ai_`-prefixed URL so the provider can fetch it.
                $referenceImageUrls = $imageUrlsInText;

                foreach ($attachedImagePaths as $attachedImagePath) {
                    if ($inlineReferenceImage) {
                        $referenceImageUrls[] = $attachedImagePath;
                        continue;
                    }

                    $publicImageUrl = $this->uploadStore->publishInputImageForRemoteFetch($attachedImagePath, $message->getUserId());
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

                $effectiveUserId = $this->modelResolver->effectiveUserId($message);
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

                $effectiveUserId = $this->modelResolver->effectiveUserId($message);
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

                $ttsLanguage = is_string($classification['language'] ?? null)
                    ? (string) $classification['language']
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

                ProgressCallback::notify($progressCallback, 'generating', 'Audio generated successfully.');

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

                $effectiveUserId = $this->modelResolver->effectiveUserId($message);
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

            ProgressCallback::notify($progressCallback, 'generating', 'Saving '.$mediaTypeLabel.'...');

            $localPath = null;

            if (str_starts_with($mediaUrl, 'data:')) {
                // Data URL from AI provider - decode and save to disk
                // (message id is only used for the filename; 0 for transient
                // incognito messages)
                $localPath = $this->uploadStore->saveDataUrlAsFile($mediaUrl, $message->getId() ?? 0, $message->getUserId(), $provider);
                if (!$localPath) {
                    throw new \Exception("Failed to save generated {$mediaType} data URL to disk");
                }
                $this->logger->info('MediaGenerationHandler: Saved data URL to disk', [
                    'path' => $localPath,
                    'type' => $mediaType,
                ]);
            } else {
                // External URL - download to disk
                $localPath = $this->uploadStore->downloadMedia($mediaUrl, $message->getId() ?? 0, $message->getUserId(), $provider, $mediaType);
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
            $registeredFile = $this->generatedFileRegistrar->register(
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
                ProgressCallback::notify($progressCallback, 'generating', 'Creating video thumbnail...');
                $thumbnailPath = $this->thumbnailService->generateThumbnail($localPath);
                if ($thumbnailPath) {
                    $this->logger->info('MediaGenerationHandler: Video thumbnail generated', [
                        'video' => $localPath,
                        'thumbnail' => $thumbnailPath,
                    ]);

                    // Persist the poster on the file row so the Generated grid can
                    // serve it via GET /api/v1/files/{id}/thumb (#1499). Without this
                    // the frame is written to disk but BTHUMBPATH stays null, so the
                    // frontend's thumb_url is always null and no poster ever shows.
                    if ($registeredFile instanceof File) {
                        $registeredFile->setThumbPath($thumbnailPath);
                        $this->em->flush();
                    }
                }
            }

            // Store a marker only — the prompt lives in media_prompt meta
            // (see GeneratedMediaTextRenderer). Channels and the web UI localize it.
            $responseText = GeneratedMediaTextRenderer::storageMarker($mediaType);
            $folderNote = $this->maybeDeliverToFolder($message, $localPath, $options);
            if (null !== $folderNote) {
                $responseText .= "\n\n".$folderNote;
            }

            // Stream the response
            $streamCallback($responseText);

            ProgressCallback::notify($progressCallback, 'generating', ucfirst($mediaType).' generated successfully.');

            $mediaUsage = [];
            if ('image' === $mediaType) {
                $mediaUsage['images'] = $result['image_count'] ?? 1;
                // Carry the quality/size so per-tier image models (gpt-image)
                // bill the exact price (#1315). The quality mirrors the default
                // used when building $imageOptions above. An edit leaves the
                // size to the model, so the size it rendered wins over the
                // requested one when the provider reports it (#2404).
                $mediaUsage['quality'] = $options['quality'] ?? ($isPic2Pic ? 'high' : 'standard');
                $mediaUsage['size'] = RenderedImageSize::billingKey($media[0]['size'] ?? null)
                    ?? $options['size'] ?? '1024x1024';
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
                    'folder_delivery' => $folderNote,
                    // Which picture of the conversation this result was edited
                    // from (null for a fresh generation) — the frontend shows it
                    // so the user can see WHICH file the assistant reused.
                    'edit_source_file_id' => $editSource?->fileId,
                    'edit_source_name' => $editSource?->displayName,
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
            // $result is initialised to null before the try, so it is always
            // defined here (null when the abort happened before the provider call
            // returned, the provider array otherwise) — `?? null` was redundant.
            $cancelledMediaUsage = $this->buildCancelledMediaUsage($mediaType, $options, $classification, $result);
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
            ] + ($e instanceof ProviderException ? $e->logContext() : []));

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
     * Route a misclassified "audio" turn to the general chat answer.
     *
     * The mediamaker classification (topic, BMEDIA, model override, the
     * pre-resolved mediamaker prompt bundle) is replaced by a plain `general`
     * chat classification so the reply is written by the chat model with the
     * user's default prompt — the answer the sorter should have produced.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function answerAsChatInstead(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback,
        array $options,
        string $rejectedScript,
    ): array {
        $this->logger->warning('MediaGenerationHandler: audio script only repeats the user request, answering as chat instead', [
            'message_id' => $message->getId(),
            'topic' => $classification['topic'] ?? null,
            'script_preview' => mb_substr($rejectedScript, 0, 120),
        ]);

        if (null === $this->chatHandler) {
            $lang = $classification['language'] ?? 'en';
            $streamCallback('de' === $lang
                ? 'Ich habe in deiner Nachricht keinen Text gefunden, den ich vorlesen könnte. Formuliere die Anfrage bitte als normale Frage, oder gib den Text an, den ich sprechen soll (z. B. „Lies vor: …“).'
                : 'I could not find any text in your message to read aloud. Please ask the question as a normal chat message, or tell me the exact text to speak (e.g. "Read aloud: …").');

            // No `media_type` here on purpose: StreamController bills any
            // response carrying one as generated media, and nothing was made.
            return [
                'metadata' => [
                    'error' => 'tts_script_echoes_request',
                ],
            ];
        }

        $chatClassification = $classification;
        $chatClassification['topic'] = 'general';
        $chatClassification['intent'] = 'chat';
        $chatClassification['rerouted_from'] = self::REROUTED_FROM_AUDIO;
        unset(
            $chatClassification['media_type'],
            $chatClassification['input_mode'],
            $chatClassification['duration'],
            $chatClassification['resolution'],
            $chatClassification['model_id'],
            $chatClassification['prompt_metadata'],
        );

        $chatOptions = $options;
        unset($chatOptions['resolved_prompt_data']);

        ProgressCallback::notify($progressCallback, 'analyzing', 'Answering your request…');

        $result = $this->chatHandler->handleStream($message, $thread, $chatClassification, $streamCallback, $progressCallback, $chatOptions);
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $metadata['rerouted_from'] = self::REROUTED_FROM_AUDIO;
        // MessageProcessor folds this into the classification it hands back,
        // so the persisted topic / media meta and the voice-reply guard see
        // the chat answer that was actually produced, not the audio vote.
        $metadata[MessageHandlerInterface::EFFECTIVE_CLASSIFICATION_KEY] = [
            'topic' => 'general',
            'intent' => 'chat',
            'media_type' => null,
            'rerouted_from' => self::REROUTED_FROM_AUDIO,
        ];
        $result['metadata'] = $metadata;

        return $result;
    }

    /**
     * Queue the memory job for the user's prompt, gated like a chat turn
     * (incognito, widget / `disable_memories`, the user's own setting, the
     * PERF.V2_PIPELINE switch). A media turn's answer is the asset itself, so
     * the extractor gets an empty answer and reads the user turns only.
     */
    private function maybeDispatchMemoryExtraction(
        Message $message,
        array $thread,
        array $classification,
        array $options,
    ): void {
        if ('' === trim($message->getText())) {
            return;
        }

        $incognito = !empty($options['incognito']);
        $disabledByRequest = !empty($options['disable_memories'])
            || ('WIDGET' === ($options['channel'] ?? null))
            || ('widget' === ($classification['source'] ?? null))
            || !empty($classification['is_widget_mode']);

        $disabledByUser = false;
        if (!$incognito && !$disabledByRequest) {
            $user = $this->em->getRepository(User::class)->find($message->getUserId());
            $disabledByUser = !$user || !$user->isMemoriesEnabled();
        }

        $this->pendingMemoryExtraction->dispatch($this->pendingMemoryExtraction->build(
            $message,
            '',
            $thread,
            [],
            $disabledByRequest,
            $disabledByUser,
            incognito: $incognito,
        ));
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
        $effectiveUserId = $this->modelResolver->effectiveUserId($message);
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
            ProgressCallback::notify($progressCallback, 'error', $limitMessage);
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
            ProgressCallback::notify($progressCallback, 'error', $errorMessage, [
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

        ProgressCallback::notify($progressCallback, 'generating', 'Media generation started in the background.', [
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
            // billing must follow what was actually sent to the provider. The
            // size is the request's; SyncMediaJobGenerator replaces it with the
            // size the model rendered when the provider reports one (#2404).
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

    /**
     * When the user asked to file the result in Nextcloud and the multitask
     * plan did not already take that step, upload the just-generated file.
     *
     * @param array<string, mixed> $options
     */
    private function maybeDeliverToFolder(Message $message, string $localPath, array $options): ?string
    {
        if (!empty($options['skip_folder_delivery']) || null === $this->folderDelivery) {
            return null;
        }

        $text = (string) $message->getText();
        if (!$this->folderDelivery->userAskedToSaveToFolder($text)) {
            return null;
        }

        $absolute = $this->uploadDir.'/'.ltrim($localPath, '/');
        if (!is_file($absolute)) {
            return null;
        }

        $result = $this->folderDelivery->send(
            $message->getUserId(),
            [['path' => $absolute, 'name' => basename($localPath)]],
        );

        return $result['message'];
    }
}
