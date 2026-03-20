<?php

namespace App\Service\Message\Handler;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\FileHelper;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

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
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function getName(): string
    {
        return 'image_generation'; // Keep legacy name for backward compatibility
    }

    /**
     * Non-streaming handle method (required by interface).
     * Delegates to handleStream with a blocking accumulator.
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
    ): array {
        $content = '';
        $metadata = [];

        // Simple accumulator callback
        $streamCallback = function ($chunk) use (&$content, &$metadata) {
            if (is_array($chunk)) {
                $content .= $chunk['content'] ?? '';
                // Merge metadata if present in chunk
                if (isset($chunk['metadata']) && is_array($chunk['metadata'])) {
                    $metadata = array_merge($metadata, $chunk['metadata']);
                }
            } else {
                $content .= $chunk;
            }
        };

        // Call streaming handler
        $result = $this->handleStream(
            $message,
            $thread,
            $classification,
            $streamCallback,
            $progressCallback
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
        }

        // Priority: Classification override > DB default
        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('MediaGenerationHandler: Using classification override model', [
                'model_id' => $modelId,
            ]);

            // Detect media type from model tag (only if not a slash command)
            if (!$isSlashCommand) {
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
            }
        } else {
            $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);

            if ($isSlashCommand) {
                if ('video' === $mediaType) {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2VID', $effectiveUserId);
                } else {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $effectiveUserId);
                }
                $this->logger->info('MediaGenerationHandler: Using default model for slash command', [
                    'media_type' => $mediaType,
                    'model_id' => $modelId,
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
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $effectiveUserId);
                $mediaType = 'image';

                $this->logger->warning('MediaGenerationHandler: Media type not determined from extractor, defaulting to image', [
                    'model_id' => $modelId,
                    'prompt_preview' => substr($prompt, 0, 100),
                ]);
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

                $this->logger->info('MediaGenerationHandler: Video duration from AI classification', [
                    'duration' => $duration,
                    'source' => isset($options['duration']) ? 'options' : (isset($classification['duration']) ? 'ai_classification' : 'default'),
                ]);

                // Use video generation API
                $result = $this->aiFacade->generateVideo(
                    $prompt,
                    $message->getUserId(),
                    [
                        'provider' => $provider,
                        'model' => $modelName,
                        'duration' => $duration,
                        'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
                    ]
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

                // Stream response
                $responseText = "Generated audio: {$prompt}";
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
                        // StreamController expects this format for 'file' SSE event
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

            // CRITICAL: Always save to disk - never store data URLs in database
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
                    // StreamController expects this format for 'file' SSE event
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
            $userMessage = $this->buildErrorMessage($e, $mediaType, $lang);
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
     * Build a user-friendly, translated error message from the exception.
     */
    private function buildErrorMessage(\Exception $e, string $mediaType, string $lang): string
    {
        if ($e instanceof ProviderException) {
            $ctx = $e->getContext();
            $blockReason = $ctx['block_reason'] ?? null;

            if ($blockReason) {
                return $this->buildContentBlockedMessage($blockReason, $ctx['text_response'] ?? null, $mediaType, $lang);
            }
        }

        return $this->getGenericMediaError($mediaType, $lang);
    }

    private function buildContentBlockedMessage(string $reason, ?string $textResponse, string $mediaType, string $lang): string
    {
        $reasonExplanations = $this->getBlockReasonExplanations($lang);
        $explanation = $reasonExplanations[$reason] ?? $reasonExplanations['OTHER'];

        $mediaLabel = match ($mediaType) {
            'audio' => 'de' === $lang ? 'Audio' : 'audio',
            'video' => 'de' === $lang ? 'Video' : 'video',
            default => 'de' === $lang ? 'Bild' : 'image',
        };

        if ('de' === $lang) {
            $msg = "Google hat die Erstellung des {$mediaLabel}s mit dem Code **{$reason}** abgelehnt.\n\n{$explanation}";
        } else {
            $msg = "Google refused to generate the {$mediaLabel} with code **{$reason}**.\n\n{$explanation}";
        }

        if ($textResponse) {
            $preview = mb_substr($textResponse, 0, 300);
            $msg .= "\n\n> ".str_replace("\n", "\n> ", $preview);
        }

        return $msg;
    }

    /**
     * @return array<string, string>
     */
    private function getBlockReasonExplanations(string $lang): array
    {
        if ('de' === $lang) {
            return [
                'SAFETY' => 'Das bedeutet, dass der Inhalt gegen Googles Sicherheitsrichtlinien verstoesst. '
                    .'Haeufige Gruende: Darstellung realer Personen, Gewalt, anstössige Inhalte, '
                    .'oder Manipulation von Fotos echter Menschen. '
                    .'Tipp: Formuliere die Anfrage um oder verwende ein anderes Modell (z.B. GPT Image).',
                'RECITATION' => 'Das bedeutet, dass die Antwort urheberrechtlich geschuetztes Material enthalten koennte. '
                    .'Google blockiert Inhalte, die zu stark an bestehende Werke erinnern. '
                    .'Tipp: Formuliere die Anfrage origineller oder beschreibe den gewuenschten Stil allgemeiner.',
                'PROHIBITED_CONTENT' => 'Der Inhalt wurde als verboten eingestuft. '
                    .'Google blockiert bestimmte Kategorien grundsaetzlich und ohne Ausnahme. '
                    .'Bitte ueberarbeite deine Anfrage grundlegend.',
                'BLOCKLIST' => 'Die Anfrage enthaelt Begriffe, die auf Googles Sperrliste stehen. '
                    .'Tipp: Verwende andere Begriffe oder formuliere die Anfrage um.',
                'SPII' => 'Die Anfrage scheint sensible persoenliche Daten zu enthalten '
                    .'(z.B. Ausweisnummern, Finanzdaten). Google blockiert solche Anfragen automatisch.',
                'IMAGE_SAFETY' => 'Eines der hochgeladenen Bilder wurde von Google als problematisch eingestuft. '
                    .'Tipp: Verwende ein anderes Bild oder ein anderes Modell.',
                'OTHER' => 'Die Anfrage wurde aus einem unbekannten Grund blockiert. '
                    .'Tipp: Formuliere die Anfrage um oder verwende ein anderes Modell.',
            ];
        }

        return [
            'SAFETY' => 'This means the content violates Google\'s safety policies. '
                .'Common reasons: depicting real people, violence, offensive content, '
                .'or manipulating photos of real individuals. '
                .'Tip: Rephrase your request or try a different model (e.g. GPT Image).',
            'RECITATION' => 'This means the response may contain copyrighted material. '
                .'Google blocks content that closely resembles existing works. '
                .'Tip: Make your request more original or describe the desired style more generally.',
            'PROHIBITED_CONTENT' => 'The content was classified as prohibited. '
                .'Google blocks certain categories unconditionally. '
                .'Please fundamentally rework your request.',
            'BLOCKLIST' => 'Your request contains terms on Google\'s blocklist. '
                .'Tip: Use different terms or rephrase your request.',
            'SPII' => 'Your request appears to contain sensitive personal information '
                .'(e.g. ID numbers, financial data). Google blocks such requests automatically.',
            'IMAGE_SAFETY' => 'One of the uploaded images was flagged as problematic by Google. '
                .'Tip: Try a different image or use a different model.',
            'OTHER' => 'The request was blocked for an unknown reason. '
                .'Tip: Rephrase your request or try a different model.',
        ];
    }

    private function getGenericMediaError(string $mediaType, string $lang): string
    {
        if ('de' === $lang) {
            return match ($mediaType) {
                'audio' => 'Das Audio konnte leider nicht erstellt werden. Bitte versuche es erneut oder waehle ein anderes Modell. Tipp: Verwende fuer Audio eine klare Anweisung wie "Lies diesen Text vor: ...".',
                'video' => 'Das Video konnte leider nicht erstellt werden. Bitte versuche es erneut oder waehle ein anderes Modell.',
                default => 'Das Bild konnte leider nicht erstellt werden. Bitte versuche es erneut oder waehle ein anderes Modell.',
            };
        }

        return match ($mediaType) {
            'audio' => 'Sorry, the audio could not be generated right now. Please try again or use a different model. Tip: For audio, try a clear prompt like "Read this text aloud: ...".',
            'video' => 'Sorry, the video could not be generated right now. Please try again or use a different model.',
            default => 'Sorry, the image could not be generated right now. Please try again or use a different model.',
        };
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
}
