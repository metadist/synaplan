<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Provider\Concerns\ChatCompletionsToolSupport;
use Psr\Log\LoggerInterface;

/**
 * TrustedTokens — sovereign OpenAI-compatible inference hosted in Germany
 * by TNG Technology Consulting (EU data residency).
 *
 * Chat and vision share the same `/v1/chat/completions` endpoint. Vision is
 * only offered by models that advertise it (Qwen3.6 and GLM-5.3-Flash);
 * GLM-5.2, GLM-5.3, DeepSeek V4, Chimera and GPT OSS 120B are text-only.
 *
 * @see https://trustedtokens.eu/docs/
 * @see https://api.trustedtokens.eu/v1
 */
class TrustedTokensProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, VisionProviderInterface
{
    use ChatCompletionsToolSupport;
    private const PROVIDER_NAME = 'trustedtokens';
    private const BASE_URI = 'https://api.trustedtokens.eu/v1';
    private const DEFAULT_CHAT_MODEL = 'zai-org/GLM-5.2';
    private const DEFAULT_VISION_MODEL = 'Qwen/Qwen3.6-35B-A3B-FP8';
    private const VISION_MAX_TOKENS = 2048;

    private ?\OpenAI\Client $client = null;

    /** Key the cached client was built with (rebuild on key change). */
    private ?string $clientKey = null;

    /**
     * $apiKey is an explicit override (tests, custom wiring) that wins over
     * the ProviderKeyStore. Production wiring passes only the store, so keys
     * saved in the admin UI (or imported from env) apply without a restart.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
        private readonly ?ProviderKeyStore $keyStore = null,
    ) {
    }

    private function resolveApiKey(): ?string
    {
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            return $this->apiKey;
        }

        return $this->keyStore?->getKey($this->getName());
    }

    /**
     * Lazily build the API client with the CURRENT key; rebuilt when the key
     * changes at runtime (admin UI save / env import).
     */
    private function client(): ?\OpenAI\Client
    {
        $key = $this->resolveApiKey();
        if (null === $key || '' === $key) {
            $this->client = null;
            $this->clientKey = null;

            return null;
        }

        if (null === $this->client || $this->clientKey !== $key) {
            $this->client = \OpenAI::factory()
                ->withApiKey($key)
                ->withBaseUri(self::BASE_URI)
                ->make();
            $this->clientKey = $key;
        }

        return $this->client;
    }

    // ==================== METADATA ====================

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'TrustedTokens';
    }

    public function getDescription(): string
    {
        return 'Sovereign LLM inference on German infrastructure (TNG Technology Consulting). OpenAI-compatible API.';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => self::DEFAULT_CHAT_MODEL,
            'vision' => self::DEFAULT_VISION_MODEL,
        ];
    }

    public function getStatus(): array
    {
        if (!$this->isAvailable()) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 0,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return null !== $this->client();
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'TRUSTEDTOKENS_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://trustedtokens.eu/ (Account → API Access)',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): array
    {
        $this->assertChat($options);

        try {
            $requestOptions = $this->buildChatOptions($messages, $options, false);
            $response = $this->client()->chat()->create($requestOptions);
            $responseArray = $response->toArray();

            return $this->mergeChatCompletionsToolResult([
                'content' => $response->choices[0]->message->content ?? '',
                'usage' => $this->parseUsage($responseArray['usage'] ?? []),
            ], $responseArray['choices'][0] ?? []);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('TrustedTokens chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('TrustedTokens chat error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $this->assertChat($options);

        try {
            $requestOptions = $this->buildChatOptions($messages, $options, true);
            $stream = $this->client()->chat()->createStreamed($requestOptions);

            $usage = $this->parseUsage([]);
            $finishReason = null;

            foreach ($stream as $response) {
                $responseArray = $response->toArray();

                if (isset($responseArray['usage'])) {
                    $usage = $this->parseUsage($responseArray['usage']);
                }

                $chunkFinishReason = $responseArray['choices'][0]['finish_reason'] ?? null;
                if (null !== $chunkFinishReason) {
                    $finishReason = $chunkFinishReason;
                }

                if (isset($response->choices[0]->delta->reasoning_content)) {
                    $callback([
                        'type' => 'reasoning',
                        'content' => $response->choices[0]->delta->reasoning_content,
                    ]);
                }

                if (isset($response->choices[0]->delta->content)) {
                    $callback($response->choices[0]->delta->content);
                }

                $this->emitChatCompletionsToolDeltas($responseArray['choices'][0] ?? [], $callback);
            }

            $callback(['type' => 'finish', 'finish_reason' => $finishReason ?? 'stop']);

            return ['usage' => $usage];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('TrustedTokens streaming error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('TrustedTokens streaming error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        $this->assertApiKey();

        $model = $options['model'] ?? self::DEFAULT_VISION_MODEL;
        $prompt = '' !== $prompt ? $prompt : 'Please describe this image in detail.';

        try {
            $response = $this->client()->chat()->create([
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl)]],
                    ],
                ]],
                'max_tokens' => $options['max_tokens'] ?? self::VISION_MAX_TOKENS,
            ]);

            return $response->choices[0]->message->content ?? '';
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('TrustedTokens vision error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->explainImage(
            $imageUrl,
            'Extract all text from this image. Provide only the extracted text without any commentary.',
        );
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        $this->assertApiKey();

        try {
            $response = $this->client()->chat()->create([
                'model' => self::DEFAULT_VISION_MODEL,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Compare these two images and describe the differences and similarities.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl1)]],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl2)]],
                    ],
                ]],
                'max_tokens' => self::VISION_MAX_TOKENS,
            ]);

            return [
                'comparison' => $response->choices[0]->message->content ?? '',
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('TrustedTokens image comparison error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== HELPERS ====================

    /**
     * @param array<string, mixed> $options
     */
    private function assertChat(array $options): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', self::PROVIDER_NAME);
        }

        $this->assertApiKey();
    }

    private function assertApiKey(): void
    {
        if (null === $this->client()) {
            throw ProviderException::missingApiKey(self::PROVIDER_NAME, 'TRUSTEDTOKENS_API_KEY');
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $request = [
            'model' => $options['model'],
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
        ];

        if (isset($options['temperature'])) {
            $request['temperature'] = $options['temperature'];
        }

        if ($stream) {
            $request['stream'] = true;
            $request['stream_options'] = ['include_usage' => true];
        }

        return $this->applyChatCompletionsToolOptions($request, $options);
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}
     */
    private function parseUsage(array $usage): array
    {
        $details = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];

        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'cached_tokens' => (int) ($details['cached_tokens'] ?? 0),
            'cache_creation_tokens' => 0,
        ];
    }

    private function imageToDataUrl(string $imageUrl): string
    {
        if (str_starts_with($imageUrl, 'data:')) {
            return $imageUrl;
        }

        if (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')) {
            return $imageUrl;
        }

        $fullPath = str_starts_with($imageUrl, '/')
            ? $imageUrl
            : $this->uploadDir.'/'.ltrim($imageUrl, '/');

        if (!is_file($fullPath)) {
            throw new ProviderException("Image file not found: {$fullPath}", self::PROVIDER_NAME);
        }

        $imageData = file_get_contents($fullPath);
        if (false === $imageData) {
            throw new ProviderException("Failed to read image file: {$fullPath}", self::PROVIDER_NAME);
        }

        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';

        return 'data:'.$mimeType.';base64,'.base64_encode($imageData);
    }
}
