<?php

namespace App\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\Entity\Message;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\Service\File\FileHelper;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\RateLimitService;
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

        // Collect attached image paths for pic2pic
        $attachedImagePaths = $this->collectAttachedImagePaths($message);
        $isPic2Pic = !empty($attachedImagePaths);

        $this->logger->info('MediaGenerationHandler: Starting media generation', [
            'user_id' => $message->getUserId(),
            'prompt' => substr($prompt, 0, 100),
            'media_hint' => $promptMediaType,
            'is_pic2pic' => $isPic2Pic,
            'attached_images' => count($attachedImagePaths),
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
                    // `/vid` with an attached image is an image-to-video request
                    // (animate the photo) → use the IMG2VID default; otherwise a
                    // text-to-video request → TEXT2VID.
                    $modelId = $isPic2Pic
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
            } elseif ($isPic2Pic && 'video' === $promptMediaType) {
                // Image-to-video: the user attached an image AND asked for a
                // video/animation. This MUST win over the pic2pic-image branch
                // below (attachment presence alone would otherwise force an
                // image edit). Routes to the IMG2VID default (an image-to-video
                // model); the attached image is published + passed as image_url
                // in the video generation block.
                $modelId = $this->modelConfigService->getDefaultModel('IMG2VID', $effectiveUserId);
                $mediaType = 'video';
                $this->logger->info('MediaGenerationHandler: Image-to-video detected, using IMG2VID default model', [
                    'model_id' => $modelId,
                    'image_count' => count($attachedImagePaths),
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

        // Fallback to OpenAI DALL-E if no model configured
        if (!$provider) {
            $provider = 'openai';
            $modelName = 'dall-e-3';
            $this->logger->warning('MediaGenerationHandler: No model configured, using DALL-E fallback');
        }

        // Guard: a text-to-video request (no attached image) must never be sent
        // to an image-to-video model. i2v models (Higgsfield DoP/Kling/etc.)
        // require a reference frame and the provider rejects the request with
        // "'image_url' is a required property". This happens when an i2v model is
        // configured as the TEXT2VID default. Return an actionable message
        // instead of leaking a raw provider 400 to the user.
        if ('video' === $mediaType && !$isPic2Pic) {
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
        if ('video' === $mediaType && [] !== $attachedImagePaths && !$this->isPublicBaseUrlReachable()) {
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

        try {
            // Generate media based on type
            if ('video' === $mediaType) {
                // Get duration from AI classification (detected by MessageSorter)
                // Priority: explicit option > AI-detected from classification > default (8)
                if (isset($options['duration'])) {
                    $duration = (int) $options['duration'];
                } elseif (isset($classification['duration'])) {
                    $duration = (int) $classification['duration'];
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
                    'progress_callback' => function (array $progress) use ($progressCallback): void {
                        $elapsed = $progress['elapsed_seconds'] ?? 0;
                        $this->notify($progressCallback, 'generating', "Generating video... ({$elapsed}s)");
                    },
                ];

                // Image-to-video: publish the attached image at a public URL so
                // the provider (Higgsfield etc.) can fetch it. i2v providers
                // require a real http(s) image_url — a local path or a base64
                // data-URI will not work.
                if ([] !== $attachedImagePaths) {
                    $publicImageUrl = $this->publishInputImageForRemoteFetch($attachedImagePaths[0], $message->getUserId());
                    if (null !== $publicImageUrl) {
                        $videoOptions['image_url'] = $publicImageUrl;
                        $videoOptions['images'] = [$publicImageUrl];
                        $this->notify($progressCallback, 'generating', 'Preparing your image for animation...');
                        $this->logger->info('MediaGenerationHandler: Image-to-video source published', [
                            'provider' => $provider,
                            'model' => $modelName,
                            'image_url' => $publicImageUrl,
                        ]);
                    } else {
                        $this->logger->warning('MediaGenerationHandler: Could not publish input image for image-to-video; proceeding without a reference frame', [
                            'provider' => $provider,
                            'model' => $modelName,
                        ]);
                    }
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

                // Clean, localized confirmation (the audio player + download is the
                // deliverable). Emitting a token — rendered by the frontend via
                // i18n, like __FILE_GENERATED__ for documents — avoids leaking the
                // raw English "Generated audio:" prefix and the synthesized prompt
                // text (which could contain internal markers) into the bubble.
                $responseText = '__AUDIO_GENERATED__';
                $streamCallback($responseText);

                $this->notify($progressCallback, 'generating', 'Audio generated successfully.');

                return [
                    'metadata' => [
                        'provider' => $result['provider'] ?? $provider,
                        'model' => $result['model'] ?? $modelName,
                        'model_id' => $modelId,
                        'local_path' => $relativePath,
                        'media_prompt' => $prompt,
                        'media_type' => $mediaType,
                        'media_usage' => [
                            'characters' => $result['text_length'] ?? mb_strlen($prompt),
                        ],
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
                $localPath = $this->saveDataUrlAsFile($mediaUrl, $message->getId(), $message->getUserId(), $provider);
                if (!$localPath) {
                    throw new \Exception("Failed to save generated {$mediaType} data URL to disk");
                }
                $this->logger->info('MediaGenerationHandler: Saved data URL to disk', [
                    'path' => $localPath,
                    'type' => $mediaType,
                ]);
            } else {
                // External URL - download to disk
                $localPath = $this->downloadMedia($mediaUrl, $message->getId(), $message->getUserId(), $provider, $mediaType);
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
                    'file' => [
                        'path' => $displayUrl,
                        'type' => $mediaType,
                    ],
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
            $userMessage = $this->errorMessageBuilder->buildErrorMessage($e, $mediaType, $lang);
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
     * Collect absolute paths of image attachments from the message.
     *
     * @return string[] Absolute file paths to attached images
     */
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
     * localhost / loopback / private dev hosts. Used to short-circuit
     * image-to-video requests (whose source frame must be provider-fetchable)
     * with an actionable message instead of a raw provider 400.
     */
    private function isPublicBaseUrlReachable(): bool
    {
        $base = rtrim($this->publicBaseUrl, '/');
        if ('' === $base) {
            return false;
        }

        return 1 !== preg_match('#^https?://(localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]|host\.docker\.internal)(:\d+)?#i', $base);
    }

    private function collectAttachedImagePaths(Message $message): array
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

        return $paths;
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
     * Notify progress callback.
     */
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
