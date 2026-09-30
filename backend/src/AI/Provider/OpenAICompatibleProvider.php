<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Exception\ProviderException;
use App\AI\Exception\ProviderFailureFactory;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Provider\Concerns\ChatCompletionsToolSupport;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use OpenAI\Contracts\ClientContract;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic "OpenAI Compatible" provider.
 *
 * Speaks the standard OpenAI Chat Completions, Embeddings, and Images
 * (`POST /images/generations`) HTTP APIs against ANY admin-registered
 * endpoint (LocalAI, vLLM, LiteLLM, Ollama's /v1, …). Deliberately does
 * NOT use OpenAI's proprietary Responses API — self-hosted gateways
 * implement Chat Completions and the Images API, not Responses.
 *
 * Unlike the fixed providers (OpenAI/Groq/…), this one has no single set of
 * env credentials. A single instance serves every BMODELS row of service
 * {@see OpenAiCompatibleEndpointRegistry::SERVICE}; the concrete endpoint
 * (base URL + key + headers) is resolved per call from the model's providerId
 * via {@see OpenAiCompatibleEndpointRegistry}. This mirrors how the Higgsfield
 * provider resolves per-user credentials at call time.
 */
final class OpenAICompatibleProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, EmbeddingProviderInterface, ImageGenerationProviderInterface, VisionProviderInterface
{
    use ChatCompletionsToolSupport;

    /**
     * Local diffusion (FLUX on CPU) blocks until the PNG is done. 180s matches
     * a patient local box without holding a chat worker for an unbounded render.
     */
    private const IMAGE_TIMEOUT_SECONDS = 180;

    /** @var array<string, ClientContract> keyed by endpoint name */
    private array $clients = [];

    public function __construct(
        private readonly OpenAiCompatibleEndpointRegistry $endpoints,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
        private readonly StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
    ) {
    }

    public function getName(): string
    {
        return OpenAiCompatibleEndpointRegistry::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'OpenAI Compatible';
    }

    public function getDescription(): string
    {
        return 'Any OpenAI-compatible endpoint (LocalAI, vLLM, LiteLLM, self-hosted gateways) configured by the administrator';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (!$this->endpoints->hasAnyEndpoint()) {
            return [
                'healthy' => false,
                'error' => 'No OpenAI-compatible endpoint configured',
            ];
        }

        return [
            'healthy' => true,
            'error' => null,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->endpoints->hasAnyEndpoint();
    }

    public function getRequiredEnvVars(): array
    {
        // Endpoints are configured at runtime (encrypted in BCONFIG), not via
        // env vars — so nothing to declare here.
        return [];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): array
    {
        $model = $this->requireModel($options);
        $client = $this->clientForCall($options);

        try {
            $request = $this->buildChatRequest($messages, $options, $model, false);

            $response = $client->chat()->create($request);
            $arr = $response->toArray();

            return $this->mergeChatCompletionsToolResult([
                'content' => $response->choices[0]->message->content ?? '',
                'usage' => $this->normalizeUsage($arr['usage'] ?? []),
            ], $arr['choices'][0] ?? []);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw (new ProviderFailureFactory())->fromThrowable($e, $this->getName(), 'chat', 'OpenAI-compatible chat error');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $model = $this->requireModel($options);
        $client = $this->clientForCall($options);

        try {
            $request = $this->buildChatRequest($messages, $options, $model, true);

            $stream = $client->chat()->createStreamed($request);

            $usage = $this->normalizeUsage([]);
            $finishReason = null;

            foreach ($stream as $response) {
                $arr = $response->toArray();

                if (isset($arr['usage'])) {
                    $usage = $this->normalizeUsage($arr['usage']);
                }

                $chunkFinish = $arr['choices'][0]['finish_reason'] ?? null;
                if (null !== $chunkFinish) {
                    $finishReason = $chunkFinish;
                }

                // o-series uses reasoning_content. Ollama's OpenAI-compatible API
                // puts Qwen3 thinking in delta.reasoning. Read the raw payload:
                // the SDK object drops fields it does not know.
                $delta = is_array($arr['choices'][0]['delta'] ?? null) ? $arr['choices'][0]['delta'] : [];
                $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;
                if (is_string($reasoning) && '' !== $reasoning) {
                    $callback(['type' => 'reasoning', 'content' => self::toUtf8($reasoning)]);
                }

                if (isset($response->choices[0]->delta->content)) {
                    $content = self::toUtf8((string) $response->choices[0]->delta->content);
                    if ('' !== $content) {
                        $callback($content);
                    }
                }

                $this->emitChatCompletionsToolDeltas($arr['choices'][0] ?? [], $callback);
            }

            if (null !== $finishReason) {
                $callback(['type' => 'finish', 'finish_reason' => $finishReason]);
            }

            $result = ['usage' => $usage];
            if (is_string($finishReason) && '' !== $finishReason) {
                $result['finish_reason'] = $finishReason;
            }

            return $result;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw (new ProviderFailureFactory())->fromThrowable($e, $this->getName(), 'chat_stream', 'OpenAI-compatible streaming error');
        }
    }

    // ==================== EMBEDDING ====================

    public function embed(string $text, array $options = []): array
    {
        $model = $this->requireModel($options);
        $client = $this->clientForCall($options);

        try {
            $response = $client->embeddings()->create($this->buildEmbeddingParams($model, $text, $options));
            $usage = $response->usage;

            return [
                'embedding' => $response->embeddings[0]->embedding ?? [],
                'usage' => [
                    'prompt_tokens' => $usage->promptTokens,
                    'total_tokens' => $usage->totalTokens,
                ],
            ];
        } catch (\Throwable $e) {
            throw new ProviderException('OpenAI-compatible embedding error: '.$e->getMessage(), $this->getName(), null, 0, $e);
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        $model = $this->requireModel($options);
        $client = $this->clientForCall($options);

        try {
            $response = $client->embeddings()->create($this->buildEmbeddingParams($model, array_values($texts), $options));
            $usage = $response->usage;

            $embeddings = array_map(
                static fn ($item): array => $item->embedding,
                $response->embeddings
            );

            return [
                'embeddings' => $embeddings,
                'usage' => [
                    'prompt_tokens' => $usage->promptTokens,
                    'total_tokens' => $usage->totalTokens,
                ],
            ];
        } catch (\Throwable $e) {
            throw new ProviderException('OpenAI-compatible batch embedding error: '.$e->getMessage(), $this->getName(), null, 0, $e);
        }
    }

    public function getDimensions(string $model): int
    {
        // The vector width is a property of the upstream model, which cannot be
        // introspected generically over the OpenAI API. The vectorization
        // pipeline reads the real dimension from the BMODELS JSON
        // (meta.dimensions); this fallback only matters when that metadata is
        // absent. 1024 matches bge-m3 and many common self-hosted embedding
        // models.
        return 1024;
    }

    /**
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

        $dimensions = $options['dimensions'] ?? null;
        if (is_int($dimensions) && $dimensions > 0) {
            $params['dimensions'] = $dimensions;
        }

        return $params;
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        $model = $this->requireModel($options);
        $client = $this->clientForCall($options);

        $fullPath = $this->uploadDir.'/'.ltrim($imageUrl, '/');
        if (!file_exists($fullPath)) {
            throw new ProviderException('OpenAI-compatible vision error: image not found: '.basename($imageUrl), $this->getName());
        }

        $data = file_get_contents($fullPath);
        $mime = mime_content_type($fullPath);
        if (false === $data || false === $mime) {
            throw new ProviderException('OpenAI-compatible vision error: unable to read image: '.basename($imageUrl), $this->getName());
        }

        $dataUrl = 'data:'.$mime.';base64,'.base64_encode($data);
        $text = '' !== trim($prompt) ? $prompt : 'Describe what you see in this image in detail.';

        try {
            $response = $client->chat()->create([
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $text],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ]],
                'max_tokens' => $options['max_tokens'] ?? 1000,
            ]);

            return $response->choices[0]->message->content ?? '';
        } catch (\Throwable $e) {
            throw new ProviderException('OpenAI-compatible vision error: '.$e->getMessage(), $this->getName(), null, 0, $e);
        }
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->explainImage($imageUrl, 'Extract all text from this image. Return only the text, nothing else.');
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        // Not part of the hosting-partner scope; keep the interface satisfied.
        throw new ProviderException('Image comparison is not supported by the OpenAI-compatible provider', $this->getName());
    }

    // ==================== IMAGE GENERATION ====================

    /**
     * Text-to-image via the OpenAI Images API (`POST {base}/images/generations`).
     *
     * The body stays minimal on purpose: DALL-E fields such as `quality` and
     * `style` are rejected by LocalAI and most self-hosted gateways. `response_format`
     * is requested so we can persist bytes ourselves; a gateway that does not
     * know the field is retried once without it.
     *
     * Attached reference images are refused. Editing would need `/images/edits`,
     * which this provider does not speak, and silently dropping the attachment
     * would tell the user a picture was edited when a new one was drawn.
     *
     * @param array<string, mixed> $options
     *
     * @return list<array{url: string, b64_json: ?string, revised_prompt: ?string}>
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $model = $this->requireModel($options);
        $references = $options['images'] ?? [];
        if (is_array($references) && [] !== $references) {
            throw new ProviderException('This OpenAI-compatible endpoint generates a new image from text. It does not edit an attached picture. Remove the attachment, or pick an image model that can edit.', $this->getName());
        }

        $endpoint = $this->endpointForCall($options);
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => $this->imageCount($options),
            'response_format' => 'b64_json',
        ];
        $size = $options['size'] ?? null;
        if (is_string($size) && 1 === preg_match('/^\d+x\d+$/', $size)) {
            $body['size'] = $size;
        }

        $this->logger->info('OpenAI-compatible: generateImage', [
            'endpoint' => $endpoint['name'],
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'n' => $body['n'],
            'size' => $body['size'] ?? null,
        ]);

        $result = $this->postImages($endpoint, $body);
        if ($result['status'] >= 400 && $this->errorMentions($result['payload'], 'response_format')) {
            unset($body['response_format']);
            $result = $this->postImages($endpoint, $body);
        }

        if ($result['status'] >= 400) {
            $this->throwImageFailure($result['status'], $result['payload']);
        }

        return $this->normalizeGeneratedImages($result['payload'], $endpoint['base_url']);
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('Image variations are not supported by the OpenAI-compatible provider. Generate a new image from a prompt instead.', $this->getName());
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        throw new ProviderException('Masked image editing is not supported by the OpenAI-compatible provider. Generate a new image from a prompt instead.', $this->getName());
    }

    // ==================== INTERNALS ====================

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatRequest(array $messages, array $options, string $model, bool $stream): array
    {
        $request = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
        ];

        if ($stream) {
            $request['stream'] = true;
            $request['stream_options'] = ['include_usage' => true];
        }

        if (isset($options['temperature'])) {
            $request['temperature'] = $options['temperature'];
        }

        // Ollama's OpenAI-compatible API. Qwen3 otherwise spends max_tokens on
        // thinking and the JSON answer is cut off (#2264). Only sent when a
        // caller asked — a strict gateway that rejects unknown fields must not
        // see this on ordinary calls.
        if (!empty($options['disable_thinking'])) {
            $request['think'] = false;
        }

        $schema = $options['structured_output'] ?? null;
        if ($schema instanceof StructuredOutputSchema) {
            $request = array_merge($request, $this->structuredOutputTranslator->translate($this->getName(), $model, $stream, $schema));
        }

        return $this->applyChatCompletionsToolOptions($request, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    /**
     * A token limit can cut a multi-byte character in half. Invalid UTF-8
     * later fails the message insert and the reply is never saved (#2264).
     */
    private static function toUtf8(string $text): string
    {
        if ('' === $text || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private function requireModel(array $options): string
    {
        $model = $options['model'] ?? null;
        if (!is_string($model) || '' === trim($model)) {
            throw new ProviderException('Model must be specified in options', $this->getName());
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{name: string, label: string, base_url: string, api_key: string, headers: array<string, string>, capabilities: string[]}
     */
    private function endpointForCall(array $options): array
    {
        $endpoint = $this->endpoints->resolveForModel(
            is_string($options['model'] ?? null) ? $options['model'] : null,
            is_string($options['endpoint'] ?? null) ? $options['endpoint'] : null,
        );

        if (null === $endpoint) {
            throw new ProviderException('No OpenAI-compatible endpoint resolved for this model. Configure an endpoint in Admin and set "endpoint" in the model JSON.', $this->getName());
        }

        return $endpoint;
    }

    private function clientForCall(array $options): ClientContract
    {
        $endpoint = $this->endpointForCall($options);

        $cacheKey = $endpoint['name'];
        if (isset($this->clients[$cacheKey])) {
            return $this->clients[$cacheKey];
        }

        $factory = \OpenAI::factory()
            // Many gateways require SOME bearer token; send a harmless
            // placeholder when the endpoint is unauthenticated (e.g. a
            // localhost LocalAI) so the client still sets the header.
            ->withApiKey('' !== $endpoint['api_key'] ? $endpoint['api_key'] : 'sk-no-key')
            ->withBaseUri($endpoint['base_url']);

        foreach ($endpoint['headers'] as $headerName => $headerValue) {
            $factory = $factory->withHttpHeader($headerName, $headerValue);
        }

        $this->logger->info('OpenAI-compatible: using endpoint', [
            'endpoint' => $endpoint['name'],
            'base_url' => $endpoint['base_url'],
            'model' => $options['model'] ?? null,
        ]);

        return $this->clients[$cacheKey] = $factory->make();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function imageCount(array $options): int
    {
        $count = $options['n'] ?? 1;
        if (!is_int($count) && !(is_string($count) && is_numeric($count))) {
            return 1;
        }

        return max(1, min(4, (int) $count));
    }

    /**
     * @param array{name: string, base_url: string, api_key: string, headers: array<string, string>} $endpoint
     * @param array<string, mixed>                                                                   $body
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function postImages(array $endpoint, array $body): array
    {
        $headers = ['Accept' => 'application/json'];
        if ('' !== $endpoint['api_key']) {
            $headers['Authorization'] = 'Bearer '.$endpoint['api_key'];
        }
        foreach ($endpoint['headers'] as $name => $value) {
            $headers[$name] = $value;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($endpoint['base_url'], '/').'/images/generations', [
                'headers' => $headers,
                'json' => $body,
                'timeout' => self::IMAGE_TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            try {
                $decoded = $response->toArray(false);
            } catch (\Throwable) {
                $decoded = ['error' => ['message' => substr($response->getContent(false), 0, 500)]];
            }
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('OpenAI-compatible image generation could not reach the endpoint: '.$e->getMessage(), $this->getName(), null, 0, $e);
        }

        return [
            'status' => $status,
            'payload' => $decoded,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function errorMentions(array $payload, string $needle): bool
    {
        return str_contains(strtolower($this->upstreamErrorText($payload)), strtolower($needle));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upstreamErrorText(array $payload): string
    {
        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            $message = $error['message'] ?? '';
            if (is_string($message) && '' !== $message) {
                return $message;
            }
        }
        if (is_string($error) && '' !== $error) {
            return $error;
        }

        $encoded = json_encode($payload);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function throwImageFailure(int $status, array $payload): never
    {
        $text = $this->upstreamErrorText($payload);
        if ('' === trim($text)) {
            $text = 'HTTP '.$status;
        }

        if (false !== stripos($text, 'content_policy') || false !== stripos($text, 'safety')) {
            throw ProviderException::contentBlocked($this->getName(), 'SAFETY', substr($text, 0, 300));
        }

        throw new ProviderException('OpenAI-compatible image generation failed: '.substr($text, 0, 300), $this->getName(), ['status_code' => $status], $status >= 400 && $status <= 599 ? $status : 0);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{url: string, b64_json: ?string, revised_prompt: ?string}>
     */
    private function normalizeGeneratedImages(array $payload, string $baseUrl): array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new ProviderException('OpenAI-compatible image generation returned no images.', $this->getName());
        }

        $images = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $b64 = isset($item['b64_json']) && is_string($item['b64_json']) && '' !== $item['b64_json']
                ? $item['b64_json']
                : null;
            $url = isset($item['url']) && is_string($item['url']) && '' !== $item['url']
                ? $item['url']
                : null;

            if (null !== $b64) {
                $url = 'data:image/png;base64,'.$b64;
            } elseif (null !== $url) {
                $url = $this->absoluteImageUrl($url, $baseUrl);
            } else {
                continue;
            }

            $revised = $item['revised_prompt'] ?? null;
            $images[] = [
                'url' => $url,
                'b64_json' => $b64,
                'revised_prompt' => is_string($revised) ? $revised : null,
            ];
        }

        if ([] === $images) {
            throw new ProviderException('OpenAI-compatible image generation returned no images.', $this->getName());
        }

        return $images;
    }

    /**
     * LocalAI sometimes returns a path on the gateway host (`/generated/x.png`)
     * rather than an absolute URL. Join it to the endpoint origin, not to the
     * `/v1` prefix, because generated files are served beside the API.
     */
    private function absoluteImageUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, 'data:')) {
            return $url;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $url;
        }

        $origin = ($parts['scheme'] ?? 'http').'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin.'/'.ltrim($url, '/');
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}
     */
    private function normalizeUsage(array $usage): array
    {
        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'cached_tokens' => (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            'cache_creation_tokens' => 0,
        ];
    }
}
