<?php

namespace App\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\ModelConfigService;
use App\Service\Message\MediaPromptExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Media Generation Handler
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
        private string $uploadDir = '/var/www/html/var/uploads'
    ) {}

    public function getName(): string
    {
        return 'image_generation'; // Keep legacy name for backward compatibility
    }
    
    /**
     * Non-streaming handle method (required by interface)
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null
    ): array {
        // For media generation, we don't support non-streaming mode
        // Just return a message that it needs streaming
        return [
            'content' => 'Media generation requires streaming mode',
            'metadata' => []
        ];
    }

    /**
     * Handle image generation with streaming
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = []
    ): array {
        // Send initial status based on detected media type (will be refined later)
        $this->notify($progressCallback, 'analyzing', 'Understanding your request...');

        // Extract media prompt via AI (mediamaker prompt)
        $promptData = $this->promptExtractor->extract($message, $thread, $classification);
        $prompt = trim($promptData['prompt'] ?? '');
        $promptMediaType = $promptData['media_type'] ?? null;
        
        if ($prompt === '') {
            $prompt = $message->getText();
        }
        
        if ($prompt === '') {
            throw new \RuntimeException('Unable to determine media prompt text');
        }
        
        $this->logger->info('MediaGenerationHandler: Starting media generation', [
            'user_id' => $message->getUserId(),
            'prompt' => substr($prompt, 0, 100),
            'media_hint' => $promptMediaType
        ]);

        // Get media generation model - detect type from model tag if specified
        $modelId = null;
        $provider = null;
        $modelName = null;
        $mediaType = 'image'; // default
        
        // Check if this is a slash command (e.g., /pic, /vid)
        $topic = $classification['topic'] ?? null;
        $isSlashCommand = false;
        if ($topic === 'tools:pic') {
            $mediaType = 'image';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /pic command, forcing image generation');
        } elseif ($topic === 'tools:vid') {
            $mediaType = 'video';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /vid command, forcing video generation');
        }
        
        // Check if this is a slash command (e.g., /pic, /vid)
        $topic = $classification['topic'] ?? null;
        $isSlashCommand = false;
        if ($topic === 'tools:pic') {
            $mediaType = 'image';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /pic command, forcing image generation');
        } elseif ($topic === 'tools:vid') {
            $mediaType = 'video';
            $isSlashCommand = true;
            $this->logger->info('MediaGenerationHandler: Detected /vid command, forcing video generation');
        }
        
        // Priority: Classification override > DB default
        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('MediaGenerationHandler: Using classification override model', [
                'model_id' => $modelId
            ]);
            
            // Detect media type from model tag (only if not a slash command)
            if (!$isSlashCommand) {
            $model = $this->em->getRepository(\App\Entity\Model::class)->find($modelId);
            if ($model) {
                $tag = $model->getTag();
                if ($tag === 'text2vid') {
                    $mediaType = 'video';
                } elseif ($tag === 'text2sound') {
                    $mediaType = 'audio';
                }
                $provider = $model->getService();
                $modelName = $model->getName();
                }
            }
        } else {
            // For slash commands, skip auto-detection and use the detected type
            if ($isSlashCommand) {
                if ($mediaType === 'video') {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2VID', $message->getUserId());
                } else {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $message->getUserId());
                }
                $this->logger->info('MediaGenerationHandler: Using default model for slash command', [
                    'media_type' => $mediaType,
                    'model_id' => $modelId
                ]);
            } elseif ($promptMediaType === 'video') {
            // For slash commands, skip auto-detection and use the detected type
            if ($isSlashCommand) {
                if ($mediaType === 'video') {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2VID', $message->getUserId());
                } else {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $message->getUserId());
                }
                $this->logger->info('MediaGenerationHandler: Using default model for slash command', [
                    'media_type' => $mediaType,
                    'model_id' => $modelId
                ]);
            } elseif ($promptMediaType === 'video') {
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2VID', $message->getUserId());
                $mediaType = 'video';
                $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (video)');
            } elseif ($promptMediaType === 'audio') {
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2SOUND', $message->getUserId());
                $mediaType = 'audio';
                $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (audio)');
            } elseif ($promptMediaType === 'image') {
                $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $message->getUserId());
                $mediaType = 'image';
                $this->logger->info('MediaGenerationHandler: Using media type hint from extractor (image)');
            } else {
                // Auto-detect media type from prompt keywords (English only - sorting handles multilingual)
                $isVideo = preg_match('/\b(video|film|movie|clip|animation|animated)\b/i', $prompt);
                $isAudio = preg_match('/\b(audio|sound|music|voice|speech|song|read|aloud|speak|tts|text.?to.?speech|convert.?to.?audio|make.?voice)\b/i', $prompt);
                
                if ($isVideo) {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2VID', $message->getUserId());
                    $mediaType = 'video';
                } elseif ($isAudio) {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2SOUND', $message->getUserId());
                    $mediaType = 'audio';
                } else {
                    $modelId = $this->modelConfigService->getDefaultModel('TEXT2PIC', $message->getUserId());
                    $mediaType = 'image';
                }
                
                $this->logger->info('MediaGenerationHandler: Auto-detected media type', [
                    'media_type' => $mediaType,
                    'model_id' => $modelId
                ]);
            }
        }
        
        // Resolve model ID to provider + model name
        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            
            $this->logger->info('MediaGenerationHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName
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
        $mediaTypeLabel = match($mediaType) {
            'video' => 'video',
            'audio' => 'audio',
            default => 'image'
        };
        
        $statusMessage = "AI is crafting your $mediaTypeLabel with $providerName $modelName";
        $this->notify($progressCallback, 'generating', $statusMessage);

        try {
            // Generate media based on type
            if ($mediaType === 'video') {
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
            } elseif ($mediaType === 'audio') {
                // Generate audio using TTS
                $this->logger->info('MediaGenerationHandler: Starting TTS generation', [
                    'provider' => $provider,
                    'model' => $modelName,
                    'text_length' => strlen($prompt)
                ]);
                
                $result = $this->aiFacade->synthesize(
                    $prompt,
                    $message->getUserId(),
                    [
                        'provider' => $provider,
                        'model' => $modelName,
                        'format' => 'mp3'
                    ]
                );
                
                // synthesize() returns ['filename' => 'tts_xxx.mp3', 'provider' => 'openai', 'model' => 'tts-1']
                $filename = $result['filename'];
                
                $this->logger->info('MediaGenerationHandler: TTS audio generated', [
                    'filename' => $filename,
                    'provider' => $result['provider']
                ]);
                
                $media = [[
                    'url' => "/api/v1/files/uploads/{$filename}",
                    'type' => 'audio',
                    'format' => pathinfo($filename, PATHINFO_EXTENSION)
                ]];
            } else {
                // Generate image
                $result = $this->aiFacade->generateImage(
                    $prompt,
                    $message->getUserId(),
                    [
                        'provider' => $provider,
                        'model' => $modelName,
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
                'media_sample' => !empty($media) ? json_encode($media[0]) : 'empty'
            ]);

            // Check if media was actually generated
            if (empty($media)) {
                throw new \Exception("No {$mediaType} generated by provider. Response: " . json_encode($result));
            }

            // Download first media and save locally
            $mediaUrl = null;
            $localPath = null;
            
            if (isset($media[0]['url'])) {
                $mediaUrl = $media[0]['url'];
            } else {
                $this->logger->error('MediaGenerationHandler: No URL in media response', [
                    'media' => json_encode($media[0] ?? null),
                    'result' => json_encode($result)
                ]);
                throw new \Exception("Generated {$mediaType} has no URL. Check provider response format.");
            }
            
            // Only attempt download for images (videos might be too large or streaming-only)
            if ($mediaType === 'image') {
                $localPath = $this->downloadImage($mediaUrl);
            }
            
            if ($localPath) {
                $this->logger->info('MediaGenerationHandler: Media downloaded', [
                    'path' => $localPath,
                    'type' => $mediaType
                ]);
            } else {
                $this->logger->warning('MediaGenerationHandler: Download failed or skipped, will use original URL', [
                    'original_url' => $mediaUrl,
                    'type' => $mediaType
                ]);
            }

            // Use local path if available, otherwise use original URL
            // CRITICAL: Ensure we always have a valid URL
            $displayUrl = $localPath ? "/api/v1/files/uploads/{$localPath}" : $mediaUrl;
            
            // Fallback safety check
            if (!$displayUrl) {
                throw new \Exception("No valid {$mediaType} URL available (neither local nor remote)");
            }

            // Stream response with revised prompt
            $revisedPrompt = $media[0]['revised_prompt'] ?? $prompt;
            $responseText = "Generated {$mediaType}: {$revisedPrompt}";
            
            // Stream the response
            $streamCallback($responseText);

            $this->notify($progressCallback, 'generating', ucfirst($mediaType) . ' generated successfully.');

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
                'provider' => $provider
            ]);

            // Stream error message
            $errorMessage = "Sorry, {$mediaType} generation failed: " . $e->getMessage();
            $streamCallback($errorMessage);

            $this->notify($progressCallback, 'error', ucfirst($mediaType) . ' generation failed.');

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
     * Download image from URL to local storage
     */
    private function downloadImage(string $url): ?string
    {
        try {
            $this->logger->info('MediaGenerationHandler: Starting download', [
                'url' => $url,
                'upload_dir' => $this->uploadDir
            ]);

            // Ensure upload directory exists and is writable
            if (!is_dir($this->uploadDir)) {
                $this->logger->warning('MediaGenerationHandler: Upload dir does not exist, creating it');
                if (!mkdir($this->uploadDir, 0777, true)) {
                    throw new \Exception('Failed to create upload directory');
                }
            }

            if (!is_writable($this->uploadDir)) {
                throw new \Exception('Upload directory is not writable: ' . $this->uploadDir);
            }

            // Try cURL first (more reliable for external URLs)
            $imageContent = null;
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP; Synaplan)');
                
                $imageContent = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($imageContent === false || $httpCode !== 200) {
                    throw new \Exception("cURL download failed (HTTP {$httpCode}): {$curlError}");
                }
            } else {
                // Fallback to file_get_contents
                $imageContent = @file_get_contents($url);
                if ($imageContent === false) {
                    throw new \Exception('file_get_contents download failed');
                }
            }

            if (empty($imageContent)) {
                throw new \Exception('Downloaded content is empty');
            }

            // Detect image type from content
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($imageContent);
            
            $extension = match($mimeType) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'png'
            };

            // Generate unique filename
            $filename = 'generated_' . uniqid() . '.' . $extension;
            $localPath = $this->uploadDir . '/' . $filename;

            // Save to disk
            $bytesWritten = file_put_contents($localPath, $imageContent);
            if ($bytesWritten === false) {
                throw new \Exception('Failed to save image to disk');
            }

            $this->logger->info('MediaGenerationHandler: Image downloaded successfully', [
                'filename' => $filename,
                'bytes' => $bytesWritten,
                'mime_type' => $mimeType,
                'path' => $localPath
            ]);

            return $filename; // Return relative path (filename only)
        } catch (\Exception $e) {
            $this->logger->error('MediaGenerationHandler: Failed to download image', [
                'error' => $e->getMessage(),
                'url' => $url,
                'upload_dir' => $this->uploadDir,
                'upload_dir_exists' => is_dir($this->uploadDir),
                'upload_dir_writable' => is_writable($this->uploadDir)
            ]);
            return null;
        }
    }

    /**
     * Notify progress callback
     */
    private function notify(?callable $callback, string $status, string $message, array $metadata = []): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata,
                'timestamp' => time()
            ]);
        }
    }
}

