<?php

namespace App\AI\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Provider\OpenAI\OpenAIApiClient;
use App\AI\Provider\OpenAI\OpenAIChat;
use App\AI\Provider\OpenAI\OpenAICurlTransport;
use App\AI\Provider\OpenAI\OpenAIResponses;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\AI\Tool\CatalogToolUse;
use App\Service\Chat\ProviderTranscriptCapture;
use App\Service\File\FileHelper;
use OpenAI;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAIProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, EmbeddingProviderInterface, ImageGenerationProviderInterface, VisionProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface
{
    private readonly OpenAIApiClient $api;
    private readonly OpenAIResponses $responses;
    private readonly OpenAIChat $chat;

    /**
     * $apiKey is an explicit override (tests, custom wiring) that wins over
     * the ProviderKeyStore. Production wiring passes only the store, so keys
     * saved in the admin UI (or imported from env) apply without a restart.
     * $sdkFactory builds the SDK client for a key (`\OpenAI::client()` when
     * absent) and $curl sends the gpt-image request; tests pass doubles of
     * both, so no request leaves the process.
     *
     * @param (\Closure(string): OpenAI\Client)|null $sdkFactory
     */
    public function __construct(
        private LoggerInterface $logger,
        HttpClientInterface $httpClient,
        ?string $apiKey = null,
        private string $uploadDir = '/var/www/backend/var/uploads',
        private bool $storeResponses = false,
        ?ProviderKeyStore $keyStore = null,
        private StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
        private ?ProviderTranscriptCapture $transcripts = null,
        ?\Closure $sdkFactory = null,
        ?OpenAICurlTransport $curl = null,
    ) {
        $this->api = new OpenAIApiClient($httpClient, $curl ?? new OpenAICurlTransport(), $apiKey, $keyStore, $sdkFactory);
        $this->responses = new OpenAIResponses($this->api, $logger, $transcripts);
        $this->chat = new OpenAIChat($this->api, $this->responses, $logger, $structuredOutputTranslator, $storeResponses);
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getDisplayName(): string
    {
        return 'OpenAI';
    }

    public function getDescription(): string
    {
        return 'GPT models for chat, vision, and content generation';
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
        if (null === $this->api->sdk()) {
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
        return null !== $this->api->sdk();
    }

    public function supportsToolCalling(string $model): bool
    {
        if (CatalogToolUse::hasChatRow($this->getName(), $model)) {
            return CatalogToolUse::supports($this->getName(), $model);
        }

        return true;
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'OPENAI_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://platform.openai.com/',
            ],
        ];
    }

    // ==================== CHAT (Responses API) ====================

    public function chat(array $messages, array $options = []): array
    {
        return $this->chat->chat($messages, $options);
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        return $this->chat->chatStream($messages, $callback, $options);
    }

    // ==================== EMBEDDING ====================

    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $params = $this->buildEmbeddingParams($options['model'], $text, $options);

            $response = $this->api->sdk()->embeddings()->create($params);
            $usage = $response->usage;

            return [
                'embedding' => $response['data'][0]['embedding'] ?? [],
                'usage' => [
                    'prompt_tokens' => null !== $usage ? $usage->promptTokens : 0,
                    'total_tokens' => null !== $usage ? $usage->totalTokens : 0,
                ],
            ];
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI embedding error: '.$e->getMessage(), 'openai');
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $params = $this->buildEmbeddingParams($options['model'], $texts, $options);

            $response = $this->api->sdk()->embeddings()->create($params);
            $usage = $response->usage;

            return [
                'embeddings' => array_map(fn ($item) => $item['embedding'], $response['data']),
                'usage' => [
                    'prompt_tokens' => null !== $usage ? $usage->promptTokens : 0,
                    'total_tokens' => null !== $usage ? $usage->totalTokens : 0,
                ],
            ];
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI batch embedding error: '.$e->getMessage(), 'openai');
        }
    }

    public function getDimensions(string $model): int
    {
        // Native output dimensions per the OpenAI embedding catalog. The
        // previous implementation lied here for `text-embedding-3-large`
        // (claimed 1536) because `embed()` used to coerce v3 outputs to
        // 1536 via the API `dimensions` parameter. That coercion is gone
        // (issue #985 — it caused the documents/memories pipeline to
        // recreate Qdrant collections at the WRONG dim because the
        // catalog metadata said 3072 while the actual vectors came back
        // as 1536, resulting in HTTP 400 on every upsert), so this
        // method now reports the true model output.
        return match (true) {
            str_contains($model, 'text-embedding-3-small') => 1536,
            str_contains($model, 'text-embedding-3-large') => 3072,
            str_contains($model, 'text-embedding-ada-002') => 1536,
            default => 1536,
        };
    }

    /**
     * Build the params for an OpenAI embeddings call.
     *
     * Caller may pass `dimensions` in `$options` to opt-in to the v3
     * truncation feature (e.g. shrink `text-embedding-3-large` from
     * 3072 → 1024 to match a fixed collection width). The provider
     * never injects a default truncation on its own — that historical
     * behaviour silently desynchronised catalog `vector_dim` metadata
     * from the actual vector width and corrupted re-vectorize runs.
     * Caller-provided values are forwarded verbatim; an empty/zero
     * value is treated as "no override" so test fixtures stay simple.
     *
     * @param string|list<string>  $input
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildEmbeddingParams(string $model, string|array $input, array $options): array
    {
        $params = [
            'model' => $model,
            'input' => $input,
        ];

        $explicitDimensions = $options['dimensions'] ?? null;
        if (is_int($explicitDimensions) && $explicitDimensions > 0
            && str_contains($model, 'text-embedding-3')) {
            $params['dimensions'] = $explicitDimensions;
        }

        return $params;
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'dall-e-3';
            $inputImages = $options['images'] ?? [];

            switch ($this->selectImageApi($model, $inputImages)) {
                case 'responses':
                    return $this->generateImageWithResponsesApi($prompt, $inputImages, $options);
                case 'gpt_image':
                    return $this->generateImageWithGptImage1($prompt, $options, $model);
            }

            // DALL-E models use Images API
            $requestOptions = [
                'model' => $model,
                'prompt' => $prompt,
                'n' => $options['n'] ?? 1,
                'size' => $options['size'] ?? '1024x1024',
            ];

            // DALL-E 3 specific options
            if ('dall-e-3' === $model) {
                $requestOptions['quality'] = $options['quality'] ?? 'standard'; // standard or hd
                $requestOptions['style'] = $options['style'] ?? 'vivid'; // vivid or natural
            }

            $this->logger->info('OpenAI: Generating image', [
                'model' => $model,
                'prompt_length' => strlen($prompt),
            ]);

            $response = $this->api->sdk()->images()->create($requestOptions);

            $images = [];
            foreach ($response['data'] as $image) {
                $images[] = [
                    'url' => $image['url'] ?? null,
                    'b64_json' => $image['b64_json'] ?? null,
                    'revised_prompt' => $image['revised_prompt'] ?? null,
                ];
            }

            return $images;
        } catch (ProviderException $e) {
            // A text-only reply (and any other provider outcome) already
            // carries its context. Wrapping it here drops the quoted reply
            // and the model, so the chat shows the generic generation error.
            throw $e;
        } catch (\Exception $e) {
            // Check for content policy violations
            if (false !== stripos($e->getMessage(), 'content_policy')
                || false !== stripos($e->getMessage(), 'safety')) {
                throw new ProviderException('Content policy violation: The prompt was rejected by OpenAI safety system', 'openai', ['prompt' => substr($prompt, 0, 100)]);
            }

            throw new ProviderException('OpenAI image generation error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Generate image using gpt-image-* family via Image Generations API.
     *
     * @see https://platform.openai.com/docs/guides/image-generation
     */
    private function generateImageWithGptImage1(string $prompt, array $options, string $model): array
    {
        try {
            $this->logger->info('OpenAI: Generating image with '.$model, [
                'prompt_length' => strlen($prompt),
            ]);

            $requestBody = [
                'model' => $model,
                'prompt' => $prompt,
                'n' => $options['n'] ?? 1,
                'size' => $options['size'] ?? '1024x1024',
            ];

            $quality = $this->gptImageQuality($model, $options['quality'] ?? null);
            if (null !== $quality) {
                $requestBody['quality'] = $quality;
            }
            if (isset($options['background'])) {
                $requestBody['background'] = $options['background'];
            }

            $key = $this->api->key();

            [$responseBody, $httpCode, $curlError] = $this->api->curlPostJson('https://api.openai.com/v1/images/generations', $key, json_encode($requestBody), 120);

            if ($curlError) {
                throw new \Exception('cURL error: '.$curlError);
            }

            if (200 !== $httpCode) {
                $this->logger->error('OpenAI '.$model.': HTTP error', [
                    'http_code' => $httpCode,
                    'response' => substr((string) $responseBody, 0, 500),
                ]);
                throw new \Exception('HTTP '.$httpCode.': '.$responseBody);
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
                    $url = 'data:image/png;base64,'.$base64;
                }

                $images[] = [
                    'url' => $url,
                    'b64_json' => $base64,
                    'revised_prompt' => $item['revised_prompt'] ?? null,
                ];
            }

            if (empty($images)) {
                $this->logger->error('OpenAI '.$model.': No images in response', [
                    'response' => $responseBody,
                ]);
                throw new ProviderException($model.' returned no images. Response format may have changed.', 'openai');
            }

            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI '.$model.' error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Decide which OpenAI image endpoint to dispatch a request to.
     *
     * - `responses`  : Responses API with the `image_generation` tool (pic2pic with reference images).
     * - `gpt_image`  : Images Generations API for the gpt-image-* family (1, 1.5, 2, …).
     * - `dalle`      : Legacy Images API via the OpenAI PHP client (dall-e-2, dall-e-3).
     *
     * @param string[] $inputImages Absolute paths to reference images, empty for text-to-image
     */
    private function selectImageApi(string $model, array $inputImages): string
    {
        if (!empty($inputImages) && $this->supportsResponsesApi($model)) {
            return 'responses';
        }

        if (str_starts_with($model, 'gpt-image-')) {
            return 'gpt_image';
        }

        return 'dalle';
    }

    private function supportsResponsesApi(string $model): bool
    {
        if (str_starts_with($model, 'gpt-image-')) {
            return true;
        }

        $responsesModels = ['gpt-5', 'gpt-5-mini', 'gpt-5.2', 'gpt-4.1', 'gpt-4o'];

        foreach ($responsesModels as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate image using OpenAI Responses API with input images (pic2pic).
     *
     * @param string   $prompt     Text instruction
     * @param string[] $imagePaths Absolute paths to input images
     *
     * @see https://developers.openai.com/api/docs/guides/image-generation
     */
    private function generateImageWithResponsesApi(string $prompt, array $imagePaths, array $options = []): array
    {
        try {
            $model = $options['model'] ?? 'gpt-image-2.5-flare';
            $responsesModel = $this->pickResponsesModel($model);

            $imageDataUrls = [];
            foreach ($imagePaths as $imgPath) {
                $data = file_get_contents($imgPath);
                if (false === $data) {
                    throw new \Exception('Failed to read image: '.basename($imgPath));
                }
                $mime = mime_content_type($imgPath) ?: 'image/png';
                $imageDataUrls[] = 'data:'.$mime.';base64,'.base64_encode($data);
            }

            $requestBody = $this->buildImageEditRequest($prompt, $model, $imageDataUrls, $options);

            $this->logger->info('OpenAI: Pic2pic via Responses API', [
                'model' => $responsesModel,
                'image_model' => $model,
                'image_count' => \count($imagePaths),
                'prompt_length' => \strlen($prompt),
                'quality' => $requestBody['tools'][0]['quality'] ?? null,
            ]);

            $key = $this->api->key();

            $response = $this->api->postJson('https://api.openai.com/v1/responses', $requestBody, $key, 180);
            $httpCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            if (200 !== $httpCode) {
                $this->logger->error('OpenAI Responses API: HTTP error', [
                    'http_code' => $httpCode,
                    'response' => substr((string) $responseBody, 0, 500),
                ]);
                throw new \Exception('HTTP '.$httpCode.': '.$responseBody);
            }

            $response = json_decode((string) $responseBody, true);
            if (!$response || !isset($response['output'])) {
                throw new \Exception('Failed to parse Responses API response');
            }

            $images = [];
            foreach ($response['output'] as $output) {
                if ('image_generation_call' === ($output['type'] ?? null) && !empty($output['result'])) {
                    $images[] = [
                        'url' => 'data:image/png;base64,'.$output['result'],
                        'b64_json' => $output['result'],
                        'revised_prompt' => $output['revised_prompt'] ?? $prompt,
                        // What the model rendered, e.g. 1536x1024: the edit
                        // request leaves the size to the model.
                        'size' => is_string($output['size'] ?? null) ? $output['size'] : null,
                    ];
                }
            }

            if (empty($images)) {
                $exception = ProviderException::noImage('openai', (string) $model, $this->responsesMessageText($response['output']), null);
                $this->logger->error('OpenAI Responses API: No images in output', [
                    'output_types' => array_column($response['output'] ?? [], 'type'),
                ] + $exception->logContext());
                throw $exception;
            }

            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI Responses API pic2pic error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Text of the assistant `message` output item: what the model said when
     * it answered in prose instead of calling the image tool.
     *
     * @param array<mixed> $output Responses API `output` list
     */
    private function responsesMessageText(array $output): ?string
    {
        foreach ($output as $item) {
            if (!is_array($item) || 'message' !== ($item['type'] ?? null)) {
                continue;
            }
            foreach ($item['content'] ?? [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null) && '' !== trim($part['text'])) {
                    return $part['text'];
                }
            }
        }

        return null;
    }

    /**
     * Responses API body for an edit of attached images. Pure: the caller
     * reads and encodes the images.
     *
     * No `size` is sent, so the model keeps the orientation of the photo.
     * No `input_fidelity` either: gpt-image-2 and later always edit at high
     * input fidelity and must not get the key.
     *
     * @param list<string>         $imageDataUrls `data:` URLs of the attached images
     * @param array<string, mixed> $options       quality
     *
     * @return array{model: string, input: list<array<string, mixed>>, tools: list<array<string, mixed>>}
     */
    private function buildImageEditRequest(string $prompt, string $model, array $imageDataUrls, array $options): array
    {
        $content = [['type' => 'input_text', 'text' => $prompt]];
        foreach ($imageDataUrls as $dataUrl) {
            $content[] = ['type' => 'input_image', 'image_url' => $dataUrl];
        }

        // GPT Image 2.5 must be named on the tool; omitting `model` lets the
        // Responses API pick a default that is not the catalog row the user
        // selected.
        $tool = ['type' => 'image_generation', 'model' => $model];
        $quality = $this->gptImageQuality($model, $options['quality'] ?? null);
        if (null !== $quality) {
            $tool['quality'] = $quality;
        }

        return [
            'model' => $this->pickResponsesModel($model),
            'input' => [['role' => 'user', 'content' => $content]],
            'tools' => [$tool],
        ];
    }

    /**
     * The quality tier to send to a gpt-image model, or null when the caller
     * asked for none. Legacy values map onto the gpt-image tiers
     * (standard → medium, hd → high); a tier the model does not accept is
     * sent as high, the tier media billing assumes for an unknown value.
     */
    private function gptImageQuality(string $model, mixed $requested): ?string
    {
        if (null === $requested) {
            return null;
        }

        $quality = strtolower((string) $requested);
        $quality = match ($quality) {
            'standard' => 'medium',
            'hd' => 'high',
            default => $quality,
        };

        if (!in_array($quality, $this->allowedGptImageQualities($model), true)) {
            $this->logger->warning('OpenAI '.$model.': Unsupported quality value, defaulting to high', [
                'provided' => $requested,
            ]);

            return 'high';
        }

        return $quality;
    }

    /**
     * Quality values the Images API accepts for this gpt-image model.
     * GPT Image 2.5 adds `xhigh` / `max`; earlier rows only accept
     * low / medium / high / auto. Sending a 2.5-only tier to gpt-image-1
     * is a provider 400, so those stay off the older allow-list.
     *
     * @return list<string>
     */
    private function allowedGptImageQualities(string $model): array
    {
        if (str_starts_with($model, 'gpt-image-2.5')) {
            return ['low', 'medium', 'high', 'xhigh', 'max', 'auto'];
        }

        return ['low', 'medium', 'high', 'auto'];
    }

    /**
     * Pick the mainline model to use with the Responses API image generation tool.
     * Image-specific models (gpt-image-*) need a mainline model wrapper.
     *
     * The wrapper is GPT-5.6 Terra, the successor ModelCatalog::RETIREMENTS[30]
     * records for gpt-4.1: the catalog stopped carrying gpt-4.1 on 2026-07-27, so
     * that wrapper billed tokens against a model with no price row.
     */
    private function pickResponsesModel(string $imageModel): string
    {
        if (str_starts_with($imageModel, 'gpt-image-')) {
            return 'gpt-5.6-terra';
        }

        return $imageModel;
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Convert URL to file resource
            $imageContent = file_get_contents($imageUrl);
            if (false === $imageContent) {
                throw new \Exception('Failed to download image from URL');
            }

            $tmpFile = tmpfile();
            fwrite($tmpFile, $imageContent);
            $tmpPath = stream_get_meta_data($tmpFile)['uri'];

            $response = $this->api->sdk()->images()->variation([
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
            throw new ProviderException('OpenAI image variations error: '.$e->getMessage(), 'openai');
        }
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Download images
            $imageContent = file_get_contents($imageUrl);
            $maskContent = file_get_contents($maskUrl);

            if (false === $imageContent || false === $maskContent) {
                throw new \Exception('Failed to download image or mask');
            }

            // Create temp files
            $tmpImage = tmpfile();
            $tmpMask = tmpfile();
            fwrite($tmpImage, $imageContent);
            fwrite($tmpMask, $maskContent);
            $tmpImagePath = stream_get_meta_data($tmpImage)['uri'];
            $tmpMaskPath = stream_get_meta_data($tmpMask)['uri'];

            $response = $this->api->sdk()->images()->edit([
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
            throw new ProviderException('OpenAI image edit error: '.$e->getMessage(), 'openai');
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
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Build full paths
            $fullPath1 = $this->uploadDir.'/'.ltrim($imageUrl1, '/');
            $fullPath2 = $this->uploadDir.'/'.ltrim($imageUrl2, '/');

            if (!file_exists($fullPath1) || !file_exists($fullPath2)) {
                throw new \Exception('One or both images not found');
            }

            // Read images and convert to base64
            $imageData1 = file_get_contents($fullPath1);
            $imageData2 = file_get_contents($fullPath2);
            $base64Image1 = base64_encode($imageData1);
            $base64Image2 = base64_encode($imageData2);
            $mimeType1 = mime_content_type($fullPath1);
            $mimeType2 = mime_content_type($fullPath2);

            $response = $this->api->sdk()->chat()->create([
                'model' => 'gpt-4o',
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Compare these two images and describe the similarities and differences.',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType1};base64,{$base64Image1}",
                            ],
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType2};base64,{$base64Image2}",
                            ],
                        ],
                    ],
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
            throw new ProviderException('OpenAI image comparison error: '.$e->getMessage(), 'openai');
        }
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        $model = $options['model'] ?? 'gpt-4o';

        // Build full path
        $fullPath = $this->uploadDir.'/'.ltrim($imagePath, '/');

        // Check if file exists. Keep the absolute path in logs only; the
        // message may be surfaced to API clients (MediaController returns
        // ProviderException::getMessage()), so it must not leak server paths.
        if (!file_exists($fullPath)) {
            $this->logger->error('OpenAI: image file not found', ['path' => $fullPath]);
            throw new ProviderException('OpenAI vision error: Image file not found: '.basename($imagePath), 'openai');
        }

        // Read image and convert to a data URL. Both calls can fail (races,
        // permissions, unreadable content); guard them so we never build a
        // malformed data URL from a false return value.
        $imageData = file_get_contents($fullPath);
        $mimeType = mime_content_type($fullPath);
        if (false === $imageData || false === $mimeType) {
            $this->logger->error('OpenAI: failed to read image or detect MIME type', ['path' => $fullPath]);
            throw new ProviderException('OpenAI vision error: Unable to read image file: '.basename($imagePath), 'openai');
        }

        $base64Image = base64_encode($imageData);
        $dataUrl = "data:{$mimeType};base64,{$base64Image}";

        $this->logger->info('OpenAI: Analyzing image', [
            'model' => $model,
            'image' => basename($imagePath),
            'prompt_length' => strlen($prompt),
        ]);

        // Reasoning-tier models (o-series, gpt-5+) are served by the Responses
        // API. The "pro" tiers in particular (gpt-5-pro, gpt-5.5-pro, o*-pro)
        // are ONLY available there and reject v1/chat/completions with
        // "This is not a chat model and thus not supported in the
        // v1/chat/completions endpoint" — which silently produced empty OCR.
        // Route them through the Responses API exactly like chat()/chatStream()
        // already do; older multimodal chat models (gpt-4o, gpt-4.1, …) keep
        // the Chat Completions vision path.
        if ($this->responses->usesCompletionTokens($model)) {
            return $this->analyzeImageViaResponses($model, $prompt, $dataUrl, $options);
        }

        try {
            $payload = [
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl,
                            ],
                        ],
                    ],
                ]],
                'max_tokens' => $options['max_tokens'] ?? 1000,
            ];

            $response = $this->api->sdk()->chat()->create($payload);

            return $response['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            // Safety net: if a model we treated as chat-compatible turns out to
            // be Responses-only, retry there instead of failing the OCR.
            if ($this->isNotAChatModelError($e)) {
                $this->logger->warning('OpenAI: model rejected by v1/chat/completions, retrying image analysis via Responses API', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                return $this->analyzeImageViaResponses($model, $prompt, $dataUrl, $options);
            }

            throw new ProviderException('OpenAI vision error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Vision via the Responses API — the path for reasoning models (o-series,
     * gpt-5+) and the Responses-only "pro" tiers that v1/chat/completions
     * rejects.
     *
     * Mirrors the request shaping in {@see OpenAIChat::chat()} (input turns,
     * per-model reasoning config, the invalid-reasoning-tier fallback in
     * {@see OpenAIResponses::create()}) but with a single user turn that
     * carries the prompt plus the image as an `input_image` part.
     */
    private function analyzeImageViaResponses(string $model, string $prompt, string $dataUrl, array $options): string
    {
        try {
            // Default OCR/vision to the model's lowest reasoning tier so the
            // output-token budget isn't consumed by chain-of-thought (and
            // latency stays low). Callers may override via reasoning_effort.
            $options['reasoning_effort'] ??= 'lowest';

            $requestOptions = [
                'model' => $model,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                        ['type' => 'input_image', 'image_url' => $dataUrl],
                    ],
                ]],
                'store' => $this->storeResponses,
                // Reasoning tokens count against this budget, so give OCR of a
                // full page enough headroom regardless of the caller's hint.
                'max_output_tokens' => max((int) ($options['max_tokens'] ?? 0), OpenAIResponses::DEFAULT_MAX_TOKENS),
            ];

            $reasoningConfig = $this->responses->resolveReasoningConfig($model, true, $options);
            if (null !== $reasoningConfig) {
                $requestOptions['reasoning'] = $reasoningConfig;
            }

            $response = $this->responses->create($requestOptions);
            $text = $response->outputText ?? '';

            if ('' === trim($text)) {
                // A reasoning model can spend the whole output-token budget on
                // chain-of-thought and return no visible text (status
                // "incomplete", reason "max_output_tokens"). The "pro" tiers
                // force at least `medium` effort and can't be dialed down, so
                // they routinely truncate or time out on synchronous OCR.
                // Surface this as a failure so the caller falls back to another
                // vision provider instead of silently accepting an empty result.
                $arr = $response->toArray();
                $status = (string) ($arr['status'] ?? 'unknown');
                $reason = $arr['incomplete_details']['reason'] ?? null;

                throw new ProviderException(sprintf('OpenAI vision returned no text (model=%s, status=%s%s). Reasoning-heavy models such as the "pro" tiers are a poor fit for synchronous OCR; select a non-pro vision model (e.g. gpt-5.5).', $model, $status, null !== $reason ? ', reason='.$reason : ''), 'openai');
            }

            return $text;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI vision error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Detect the HTTP 400 OpenAI returns when a Responses-only model (e.g. the
     * "pro" reasoning tiers) is called on v1/chat/completions:
     *   "This is not a chat model and thus not supported in the
     *    v1/chat/completions endpoint. Did you mean to use v1/completions?".
     */
    private function isNotAChatModelError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'not a chat model')
            || str_contains($message, 'v1/chat/completions');
    }

    // ==================== SPEECH TO TEXT (Whisper) ====================

    public function transcribe(string $audioPath, array $options = []): array
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'whisper-1';

            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('OpenAI: Transcribing audio', [
                'model' => $model,
                'file' => basename($audioPath),
                'path' => $fullPath,
            ]);

            // Open file and ensure it's a valid resource
            $fileHandle = fopen($fullPath, 'r');
            if (!$fileHandle) {
                throw new \Exception("Failed to open audio file: {$fullPath}");
            }

            try {
                // Build request params - only include language if provided
                $requestParams = [
                    'model' => $model,
                    'file' => $fileHandle,
                    'response_format' => 'verbose_json',
                ];

                if (!empty($options['language'])) {
                    $requestParams['language'] = $options['language'];
                }

                $response = $this->api->sdk()->audio()->transcribe($requestParams);

                return [
                    'text' => $response['text'] ?? '',
                    'language' => $response['language'] ?? 'unknown',
                    'duration' => $response['duration'] ?? 0,
                    'segments' => $response['segments'] ?? [],
                ];
            } finally {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI transcription error: '.$e->getMessage(), 'openai');
        }
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('OpenAI: Translating audio', [
                'file' => basename($audioPath),
                'target_lang' => $targetLang,
            ]);

            // Open file and ensure it's a valid resource
            $fileHandle = fopen($fullPath, 'r');
            if (!$fileHandle) {
                throw new \Exception("Failed to open audio file: {$fullPath}");
            }

            try {
                // Whisper's translate endpoint translates to English only
                $response = $this->api->sdk()->audio()->translate([
                    'model' => 'whisper-1',
                    'file' => $fileHandle,
                ]);

                return $response['text'] ?? '';
            } finally {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI audio translation error: '.$e->getMessage(), 'openai');
        }
    }

    // ==================== TEXT TO SPEECH ====================

    public function synthesize(string $text, array $options = []): string
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'tts-1';
            $voice = $options['voice'] ?? 'alloy'; // alloy, echo, fable, onyx, nova, shimmer

            $this->logger->info('OpenAI: Synthesizing speech', [
                'model' => $model,
                'voice' => $voice,
                'text_length' => strlen($text),
            ]);

            $response = $this->api->sdk()->audio()->speech([
                'model' => $model,
                'voice' => $voice,
                'input' => $text,
                'response_format' => $options['format'] ?? 'mp3', // mp3, opus, aac, flac
                'speed' => $options['speed'] ?? 1.0,
            ]);

            // Save to temporary file with proper permissions
            $filename = 'tts_'.uniqid().'.mp3';
            $outputPath = $this->uploadDir.'/'.$filename;

            if (!FileHelper::createDirectory($this->uploadDir)) {
                throw new \RuntimeException('Unable to create upload directory: '.$this->uploadDir);
            }

            if (!is_writable($this->uploadDir)) {
                throw new \RuntimeException('Upload directory is not writable: '.$this->uploadDir);
            }

            FileHelper::writeFile($outputPath, $response);

            return $filename;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI TTS error: '.$e->getMessage(), 'openai');
        }
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $key = $this->api->key();
        if (!$key) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        $model = $options['model'] ?? 'tts-1';
        $voice = $options['voice'] ?? 'alloy';
        $format = $options['format'] ?? 'mp3';
        $speed = $options['speed'] ?? 1.0;

        $this->logger->info('OpenAI: Streaming TTS', [
            'model' => $model,
            'voice' => $voice,
            'format' => $format,
            'text_length' => strlen($text),
        ]);

        $response = $this->api->postJson('https://api.openai.com/v1/audio/speech', [
            'model' => $model,
            'voice' => $voice,
            'input' => $text,
            'response_format' => $format,
            'speed' => $speed,
        ], $key, 120, ['Content-Type' => 'application/json'], ['buffer' => false]);

        if (200 !== $response->getStatusCode()) {
            throw new ProviderException('OpenAI TTS stream HTTP '.$response->getStatusCode().': '.substr($response->getContent(false), 0, 500), 'openai');
        }

        foreach ($this->api->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if ('' !== $content) {
                yield $content;
            }
        }
    }

    public function getStreamContentType(array $options = []): string
    {
        $format = $options['format'] ?? 'mp3';

        return match ($format) {
            'opus' => 'audio/ogg',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }

    public function supportsStreaming(): bool
    {
        return true;
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
