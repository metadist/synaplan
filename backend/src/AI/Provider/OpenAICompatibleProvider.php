<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use Psr\Log\LoggerInterface;

/**
 * Generic "OpenAI Compatible" provider.
 *
 * Speaks the standard OpenAI Chat Completions + Embeddings HTTP API against
 * ANY admin-registered endpoint (LocalAI, vLLM, LiteLLM, Ollama's /v1, …).
 * Deliberately does NOT use OpenAI's proprietary Responses API — self-hosted
 * gateways implement Chat Completions, not Responses.
 *
 * Unlike the fixed providers (OpenAI/Groq/…), this one has no single set of
 * env credentials. A single instance serves every BMODELS row of service
 * {@see OpenAiCompatibleEndpointRegistry::SERVICE}; the concrete endpoint
 * (base URL + key + headers) is resolved per call from the model's providerId
 * via {@see OpenAiCompatibleEndpointRegistry}. This mirrors how the Higgsfield
 * provider resolves per-user credentials at call time.
 */
final class OpenAICompatibleProvider implements ChatProviderInterface, EmbeddingProviderInterface, VisionProviderInterface
{
    /** @var array<string, ClientContract> keyed by endpoint name */
    private array $clients = [];

    public function __construct(
        private readonly OpenAiCompatibleEndpointRegistry $endpoints,
        private readonly LoggerInterface $logger,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
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
        return ['chat', 'embedding', 'vision'];
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
            $request = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
            ];
            if (isset($options['temperature'])) {
                $request['temperature'] = $options['temperature'];
            }

            $response = $client->chat()->create($request);
            $arr = $response->toArray();

            return [
                'content' => $response->choices[0]->message->content ?? '',
                'usage' => $this->normalizeUsage($arr['usage'] ?? []),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('OpenAI-compatible chat error: '.$e->getMessage(), $this->getName(), null, 0, $e);
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $model = $this->requireModel($options);
        $client = $this->clientForCall($options);

        try {
            $request = [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
                'stream_options' => ['include_usage' => true],
                'max_tokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
            ];
            if (isset($options['temperature'])) {
                $request['temperature'] = $options['temperature'];
            }

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

                // Some gateways surface structured reasoning like the o-series.
                if (isset($response->choices[0]->delta->reasoning_content)) {
                    $reasoning = (string) $response->choices[0]->delta->reasoning_content;
                    if ('' !== $reasoning) {
                        $callback(['type' => 'reasoning', 'content' => $reasoning]);
                    }
                }

                if (isset($response->choices[0]->delta->content)) {
                    $content = (string) $response->choices[0]->delta->content;
                    if ('' !== $content) {
                        $callback($content);
                    }
                }
            }

            if (null !== $finishReason) {
                $callback(['type' => 'finish', 'finish_reason' => $finishReason]);
            }

            return ['usage' => $usage];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('OpenAI-compatible streaming error: '.$e->getMessage(), $this->getName(), null, 0, $e);
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

    // ==================== INTERNALS ====================

    /**
     * @param array<string, mixed> $options
     */
    private function requireModel(array $options): string
    {
        $model = $options['model'] ?? null;
        if (!is_string($model) || '' === trim($model)) {
            throw new ProviderException('Model must be specified in options', $this->getName());
        }

        return $model;
    }

    /**
     * Build (and cache) an OpenAI client bound to the endpoint that serves the
     * requested model.
     *
     * @param array<string, mixed> $options
     */
    private function clientForCall(array $options): ClientContract
    {
        $endpoint = $this->endpoints->resolveForModel(
            is_string($options['model'] ?? null) ? $options['model'] : null,
            is_string($options['endpoint'] ?? null) ? $options['endpoint'] : null,
        );

        if (null === $endpoint) {
            throw new ProviderException('No OpenAI-compatible endpoint resolved for this model. Configure an endpoint in Admin and set "endpoint" in the model JSON.', $this->getName());
        }

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
