<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Exception\ProviderException;
use OpenAI;
use Psr\Log\LoggerInterface;

class OpenAIProvider implements 
    ChatProviderInterface, 
    EmbeddingProviderInterface,
    ImageGenerationProviderInterface,
    VisionProviderInterface,
    SpeechToTextProviderInterface,
    TextToSpeechProviderInterface
{
    private $client;
    private array $modelCapabilities = [];

    public function __construct(
        private LoggerInterface $logger,
        private ?string $apiKey = null,
        private string $uploadDir = '/var/www/html/var/uploads'
    ) {
        if (!empty($apiKey)) {
            $this->client = OpenAI::client($apiKey);
        }
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'speech_to_text', 'text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (!$this->client) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 50,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && $this->client !== null;
    }

    /**
     * Check if model uses max_completion_tokens instead of max_tokens
     * Based on OpenAI API model capabilities (reasoning models use max_completion_tokens)
     * 
     * @param string $model Model name
     * @return bool True if model uses max_completion_tokens
     */
    private function usesCompletionTokens(string $model): bool
    {
        // Check cache first
        if (isset($this->modelCapabilities[$model])) {
            return $this->modelCapabilities[$model];
        }

        // Try to fetch model details from OpenAI API
        try {
            $modelInfo = $this->client->models()->retrieve($model);
            
            // Check if model has reasoning capabilities or is o-series/gpt-5
            // Reasoning models (o1, o3, gpt-5) use max_completion_tokens
            $isReasoningModel = isset($modelInfo->capabilities['reasoning']) || 
                               str_starts_with($model, 'o1') || 
                               str_starts_with($model, 'o3') ||
                               str_starts_with($model, 'gpt-5');
            
            $this->modelCapabilities[$model] = $isReasoningModel;
            return $isReasoningModel;
            
        } catch (\Exception $e) {
            // If API call fails, use heuristic fallback
            $this->logger->warning('Failed to fetch model capabilities from OpenAI, using heuristic', [
                'model' => $model,
                'error' => $e->getMessage()
            ]);
            
            // Heuristic: o-series and gpt-5 models use max_completion_tokens
            $usesCompletionTokens = str_starts_with($model, 'o1') || 
                                   str_starts_with($model, 'o3') ||
                                   str_starts_with($model, 'gpt-5');
            
            $this->modelCapabilities[$model] = $usesCompletionTokens;
            return $usesCompletionTokens;
        }
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $reasoning = $options['reasoning'] ?? false;
            $model = $options['model'];
            
            $usesCompletionTokensParam = $this->usesCompletionTokens($model);
            
            $requestOptions = [
                'model' => $model,
                'messages' => $messages,
            ];

            // Reasoning models don't support custom temperature (only default 1.0)
            if (!$usesCompletionTokensParam) {
                $requestOptions['temperature'] = $options['temperature'] ?? 0.7;
            }

            // Use correct token parameter based on model capabilities
            if ($usesCompletionTokensParam) {
                $requestOptions['max_completion_tokens'] = $options['max_tokens'] ?? 4096;
            } else {
                $requestOptions['max_tokens'] = $options['max_tokens'] ?? 4096;
            }

            // NOTE: Only o3 models support reasoning_effort parameter
            // o1 models automatically use reasoning without this parameter
            // We don't pass reasoning flag to OpenAI - models handle it automatically

            $response = $this->client->chat()->create($requestOptions);

            $usage = [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0,
            ];

            $this->logger->info('OpenAI: Chat completed', [
                'model' => $options['model'],
                'usage' => $usage
            ]);

            return $response['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI chat error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $reasoning = $options['reasoning'] ?? false;
            $model = $options['model'];
            
            $usesCompletionTokensParam = $this->usesCompletionTokens($model);
            
            $requestOptions = [
                'model' => $model,
                'messages' => $messages,
            ];

            // Reasoning models don't support custom temperature (only default 1.0)
            if (!$usesCompletionTokensParam) {
                $requestOptions['temperature'] = $options['temperature'] ?? 0.7;
            }

            // Use correct token parameter based on model capabilities
            if ($usesCompletionTokensParam) {
                $requestOptions['max_completion_tokens'] = $options['max_tokens'] ?? 4096;
            } else {
                $requestOptions['max_tokens'] = $options['max_tokens'] ?? 4096;
            }

            // NOTE: Reasoning models handle reasoning automatically
            // No need to pass reasoning_effort parameter

            $stream = $this->client->chat()->createStreamed($requestOptions);

            $firstChunk = true;
            foreach ($stream as $response) {
                $responseArray = $response->toArray();
                
                // Debug first chunk to see structure
                if ($firstChunk && $reasoning) {
                    error_log('🧠 OpenAI reasoning stream - First chunk structure:');
                    error_log('  Response keys: ' . implode(', ', array_keys($responseArray)));
                    error_log('  Choices: ' . json_encode($responseArray['choices'] ?? null));
                    if (isset($responseArray['choices'][0]['delta'])) {
                        error_log('  Delta keys: ' . implode(', ', array_keys($responseArray['choices'][0]['delta'])));
                        error_log('  Has reasoning_content: ' . (isset($responseArray['choices'][0]['delta']['reasoning_content']) ? 'YES' : 'NO'));
                    }
                    $firstChunk = false;
                }

                // Handle reasoning content (o1, o3, gpt-5 models)
                $reasoningContent = $responseArray['choices'][0]['delta']['reasoning_content'] ?? null;
                if ($reasoningContent !== null) {
                    $callback(['type' => 'reasoning', 'content' => $reasoningContent]);
                }

                // Handle regular content
                $content = $responseArray['choices'][0]['delta']['content'] ?? '';
                if ($content) {
                    $callback(['type' => 'content', 'content' => $content]);
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI streaming error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    // ==================== EMBEDDING ====================

    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $response = $this->client->embeddings()->create([
                'model' => $options['model'],
                'input' => $text,
            ]);

            return $response['data'][0]['embedding'] ?? [];
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI embedding error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $response = $this->client->embeddings()->create([
                'model' => $options['model'],
                'input' => $texts,
            ]);

            return array_map(fn($item) => $item['embedding'], $response['data']);
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI batch embedding error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    public function getDimensions(string $model): int
    {
        return match(true) {
            str_contains($model, 'text-embedding-3-small') => 1536,
            str_contains($model, 'text-embedding-3-large') => 3072,
            str_contains($model, 'text-embedding-ada-002') => 1536,
            default => 1536
        };
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'dall-e-3';
            
            // GPT-Image-1 uses Chat Completions API with special modalities
            if ($model === 'gpt-image-1') {
                return $this->generateImageWithGptImage1($prompt, $options);
            }
            
            // DALL-E models use Images API
            $requestOptions = [
                'model' => $model,
                'prompt' => $prompt,
                'n' => $options['n'] ?? 1,
                'size' => $options['size'] ?? '1024x1024',
            ];

            // DALL-E 3 specific options
            if ($model === 'dall-e-3') {
                $requestOptions['quality'] = $options['quality'] ?? 'standard'; // standard or hd
                $requestOptions['style'] = $options['style'] ?? 'vivid'; // vivid or natural
            }

            $this->logger->info('OpenAI: Generating image', [
                'model' => $model,
                'prompt_length' => strlen($prompt)
            ]);

            $response = $this->client->images()->create($requestOptions);

            $images = [];
            foreach ($response['data'] as $image) {
                $images[] = [
                    'url' => $image['url'] ?? null,
                    'b64_json' => $image['b64_json'] ?? null,
                    'revised_prompt' => $image['revised_prompt'] ?? null,
                ];
            }

            return $images;
        } catch (\Exception $e) {
            // Check for content policy violations
            if (stripos($e->getMessage(), 'content_policy') !== false || 
                stripos($e->getMessage(), 'safety') !== false) {
                throw new ProviderException(
                    'Content policy violation: The prompt was rejected by OpenAI safety system',
                    'openai',
                    ['prompt' => substr($prompt, 0, 100)]
                );
            }

            throw new ProviderException(
                'OpenAI image generation error: ' . $e->getMessage(),
                'openai'
            );
        }
    }
    
    /**
     * Generate image using gpt-image-1 via Image Generations API
     * @see https://platform.openai.com/docs/guides/image-generation?image-generation-model=gpt-image-1
     */
    private function generateImageWithGptImage1(string $prompt, array $options = []): array
    {
        try {
            $this->logger->info('OpenAI: Generating image with gpt-image-1', [
                'prompt_length' => strlen($prompt)
            ]);
            
            $requestBody = [
                'model' => 'gpt-image-1',
                'prompt' => $prompt,
                'n' => $options['n'] ?? 1,
                'size' => $options['size'] ?? '1024x1024',
            ];
            
            if (isset($options['quality'])) {
                $quality = $options['quality'];
                
                // Map legacy values to supported ones
                $qualityMap = [
                    'standard' => 'medium',
                    'hd' => 'high',
                ];
                if (isset($qualityMap[strtolower((string) $quality)])) {
                    $quality = $qualityMap[strtolower((string) $quality)];
                }
                
                $quality = strtolower((string) $quality);
                $allowedQualities = ['low', 'medium', 'high', 'auto'];
                if (!in_array($quality, $allowedQualities, true)) {
                    $this->logger->warning('OpenAI gpt-image-1: Unsupported quality value, defaulting to high', [
                        'provided' => $options['quality']
                    ]);
                    $quality = 'high';
                }
                
                $requestBody['quality'] = $quality;
            }
            if (isset($options['background'])) {
                $requestBody['background'] = $options['background'];
            }
            
            $ch = curl_init('https://api.openai.com/v1/images/generations');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($requestBody),
                CURLOPT_TIMEOUT => 120,
            ]);
            
            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                throw new \Exception('cURL error: ' . $curlError);
            }
            
            if ($httpCode !== 200) {
                $this->logger->error('OpenAI gpt-image-1: HTTP error', [
                    'http_code' => $httpCode,
                    'response' => substr((string) $responseBody, 0, 500)
                ]);
                throw new \Exception('HTTP ' . $httpCode . ': ' . $responseBody);
            }
            
            $response = json_decode((string) $responseBody, true);
            if (!$response || !isset($response['data'])) {
                throw new \Exception('Failed to parse JSON response');
            }
            
            $images = [];
            foreach ($response['data'] as $item) {
                $base64 = $item['b64_json'] ?? null;
                $url = $item['url'] ?? null;
                
                if (!$url && $base64) {
                    $url = 'data:image/png;base64,' . $base64;
                }
                
                $images[] = [
                    'url' => $url,
                    'b64_json' => $base64,
                    'revised_prompt' => $item['revised_prompt'] ?? null,
                ];
            }
            
            if (empty($images)) {
                $this->logger->error('OpenAI gpt-image-1: No images in response', [
                    'response' => $responseBody
                ]);
                throw new ProviderException(
                    'gpt-image-1 returned no images. Response format may have changed.',
                    'openai'
                );
            }
            
            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI gpt-image-1 error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Convert URL to file resource
            $imageContent = file_get_contents($imageUrl);
            if ($imageContent === false) {
                throw new \Exception('Failed to download image from URL');
            }

            $tmpFile = tmpfile();
            fwrite($tmpFile, $imageContent);
            $tmpPath = stream_get_meta_data($tmpFile)['uri'];

            $response = $this->client->images()->variation([
                'image' => fopen($tmpPath, 'r'),
                'n' => $count,
                'size' => '1024x1024',
            ]);

            fclose($tmpFile);

            $variations = [];
            foreach ($response['data'] as $image) {
                $variations[] = [
                    'url' => $image['url'] ?? null,
                    'b64_json' => $image['b64_json'] ?? null,
                ];
            }

            return $variations;
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI image variations error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Download images
            $imageContent = file_get_contents($imageUrl);
            $maskContent = file_get_contents($maskUrl);
            
            if ($imageContent === false || $maskContent === false) {
                throw new \Exception('Failed to download image or mask');
            }

            // Create temp files
            $tmpImage = tmpfile();
            $tmpMask = tmpfile();
            fwrite($tmpImage, $imageContent);
            fwrite($tmpMask, $maskContent);
            $tmpImagePath = stream_get_meta_data($tmpImage)['uri'];
            $tmpMaskPath = stream_get_meta_data($tmpMask)['uri'];

            $response = $this->client->images()->edit([
                'image' => fopen($tmpImagePath, 'r'),
                'mask' => fopen($tmpMaskPath, 'r'),
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
            ]);

            fclose($tmpImage);
            fclose($tmpMask);

            return $response['data'][0]['url'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI image edit error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        // Use analyzeImage internally
        $defaultPrompt = 'Describe what you see in this image in detail.';
        return $this->analyzeImage($imageUrl, $prompt ?: $defaultPrompt, $options);
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->analyzeImage($imageUrl, 'Extract all text from this image. Return only the text, nothing else.');
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Build full paths
            $fullPath1 = $this->uploadDir . '/' . ltrim($imageUrl1, '/');
            $fullPath2 = $this->uploadDir . '/' . ltrim($imageUrl2, '/');
            
            if (!file_exists($fullPath1) || !file_exists($fullPath2)) {
                throw new \Exception("One or both images not found");
            }

            // Read images and convert to base64
            $imageData1 = file_get_contents($fullPath1);
            $imageData2 = file_get_contents($fullPath2);
            $base64Image1 = base64_encode($imageData1);
            $base64Image2 = base64_encode($imageData2);
            $mimeType1 = mime_content_type($fullPath1);
            $mimeType2 = mime_content_type($fullPath2);

            $response = $this->client->chat()->create([
                'model' => 'gpt-4o',
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Compare these two images and describe the similarities and differences.'
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType1};base64,{$base64Image1}"
                            ]
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType2};base64,{$base64Image2}"
                            ]
                        ]
                    ]
                ]],
                'max_tokens' => 1000,
            ]);

            $comparison = $response['choices'][0]['message']['content'] ?? '';
            
            return [
                'comparison' => $comparison,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI image comparison error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'gpt-4o';
            
            // Build full path
            $fullPath = $this->uploadDir . '/' . ltrim($imagePath, '/');
            
            // Check if file exists
            if (!file_exists($fullPath)) {
                throw new \Exception("Image file not found: {$fullPath}");
            }

            // Read image and convert to base64
            $imageData = file_get_contents($fullPath);
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($fullPath);

            $this->logger->info('OpenAI: Analyzing image', [
                'model' => $model,
                'image' => basename($imagePath),
                'prompt_length' => strlen($prompt)
            ]);

            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Image}"
                            ]
                        ]
                    ]
                ]],
                'max_tokens' => $options['max_tokens'] ?? 1000,
            ]);

            return $response['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI vision error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    // ==================== SPEECH TO TEXT (Whisper) ====================

    public function transcribe(string $audioPath, array $options = []): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'whisper-1';
            
            // Build full path
            $fullPath = $this->uploadDir . '/' . ltrim($audioPath, '/');
            
            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('OpenAI: Transcribing audio', [
                'model' => $model,
                'file' => basename($audioPath)
            ]);

            $response = $this->client->audio()->transcribe([
                'model' => $model,
                'file' => fopen($fullPath, 'r'),
                'response_format' => 'verbose_json',
                'language' => $options['language'] ?? null,
            ]);

            return [
                'text' => $response['text'] ?? '',
                'language' => $response['language'] ?? 'unknown',
                'duration' => $response['duration'] ?? 0,
                'segments' => $response['segments'] ?? [],
            ];
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI transcription error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $fullPath = $this->uploadDir . '/' . ltrim($audioPath, '/');
            
            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('OpenAI: Translating audio', [
                'file' => basename($audioPath),
                'target_lang' => $targetLang
            ]);

            // Whisper's translate endpoint translates to English only
            $response = $this->client->audio()->translate([
                'model' => 'whisper-1',
                'file' => fopen($fullPath, 'r'),
            ]);

            return $response['text'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI audio translation error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    // ==================== TEXT TO SPEECH ====================

    public function synthesize(string $text, array $options = []): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'tts-1';
            $voice = $options['voice'] ?? 'alloy'; // alloy, echo, fable, onyx, nova, shimmer
            
            $this->logger->info('OpenAI: Synthesizing speech', [
                'model' => $model,
                'voice' => $voice,
                'text_length' => strlen($text)
            ]);

            $response = $this->client->audio()->speech([
                'model' => $model,
                'voice' => $voice,
                'input' => $text,
                'response_format' => $options['format'] ?? 'mp3', // mp3, opus, aac, flac
                'speed' => $options['speed'] ?? 1.0,
            ]);

            // Save to temporary file
            $filename = 'tts_' . uniqid() . '.mp3';
            $outputPath = $this->uploadDir . '/' . $filename;
            
            if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
                throw new \RuntimeException('Unable to create upload directory: ' . $this->uploadDir);
            }
            
            if (!is_writable($this->uploadDir)) {
                throw new \RuntimeException('Upload directory is not writable: ' . $this->uploadDir);
            }

            file_put_contents($outputPath, $response);

            return $filename;
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI TTS error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    public function getVoices(): array
    {
        // OpenAI TTS voices (static list)
        return [
            [
                'id' => 'alloy',
                'name' => 'Alloy',
                'description' => 'Neutral and balanced voice',
            ],
            [
                'id' => 'echo',
                'name' => 'Echo',
                'description' => 'Male voice',
            ],
            [
                'id' => 'fable',
                'name' => 'Fable',
                'description' => 'British accent',
            ],
            [
                'id' => 'onyx',
                'name' => 'Onyx',
                'description' => 'Deep male voice',
            ],
            [
                'id' => 'nova',
                'name' => 'Nova',
                'description' => 'Female voice',
            ],
            [
                'id' => 'shimmer',
                'name' => 'Shimmer',
                'description' => 'Warm female voice',
            ],
        ];
    }
}
