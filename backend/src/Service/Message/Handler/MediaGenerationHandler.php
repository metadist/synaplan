<?php

namespace App\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
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
class MediaGenerationHandler implements MessageHandlerInterface
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private MediaPromptExtractor $promptExtractor,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function getName(): string
    {
        return 'image_generation'; // Keep legacy name for backward compatibility
    }

    /**
     * Non-streaming handle method (required by interface).
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
    ): array {
        // For media generation, we don't support non-streaming mode
        // Just return a message that it needs streaming
        return [
            'content' => 'Media generation requires streaming mode',
            'metadata' => [],
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

        $this->logger->info('MediaGenerationHandler: Starting media generation', [
            'user_id' => $message->getUserId(),
            'prompt' => substr($prompt, 0, 100),
            'media_hint' => $promptMediaType,
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
            // For slash commands, skip auto-detection and use the detected type
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
                // Default to image if media type cannot be determined
                // The mediamaker prompt should return JSON with BMEDIA, but if it doesn't,
                // we default to image (most common case)
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

        try {
            // Generate media based on type
            if ('video' === $mediaType) {
                // Use video generation API
                $result = $this->aiFacade->generateVideo(
                    $prompt,
                    $message->getUserId(),
                    [
                        'provider' => $provider,
                        'model' => $modelName,
                        'duration' => $options['duration'] ?? 5,
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
                    ]
                );

                // synthesize() returns ['filename' => 'tts_xxx.mp3', 'provider' => 'openai', 'model' => 'tts-1']
                $filename = $result['filename'];

                $this->logger->info('MediaGenerationHandler: TTS audio generated', [
                    'filename' => $filename,
                    'provider' => $result['provider'],
                ]);

                $media = [[
                    'url' => "/api/v1/files/uploads/{$filename}",
                    'type' => 'audio',
                    'format' => pathinfo($filename, PATHINFO_EXTENSION),
                ]];
            } else {
                // Generate image
                $result = $this->aiFacade->generateImage(
                    $prompt,
                    $message->getUserId(),
                    [
                        'provider' => $provider,
                        'model' => $modelName,
                        'modelConfig' => $modelConfig,
                        'quality' => $options['quality'] ?? 'standard',
                        'style' => $options['style'] ?? 'vivid',
                        'size' => $options['size'] ?? '1024x1024',
                    ]
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
            ]);

            // Stream error message
            $errorMessage = "Sorry, {$mediaType} generation failed: ".$e->getMessage();
            $streamCallback($errorMessage);

            $this->notify($progressCallback, 'error', ucfirst($mediaType).' generation failed.');

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
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->logger->error('MediaGenerationHandler: Failed to create upload directory', ['dir' => $dir]);

            return null;
        }

        // Save file
        $bytesWritten = file_put_contents($absolutePath, $content);

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
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP; Synaplan)');

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
            $dir = dirname($absolutePath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                throw new \Exception('Failed to create upload directory: '.$dir);
            }

            // Save to disk
            $bytesWritten = file_put_contents($absolutePath, $content);
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
