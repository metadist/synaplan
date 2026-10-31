<?php

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDefaults;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderPricing;
use App\AI\Exception\ProviderException;
use App\AI\Exception\ProviderFailureFactory;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputDialect;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

#[AsAiProvider]
class OllamaProvider implements ChatProviderInterface, EmbeddingProviderInterface, ResetInterface, DescribesProviderInterface
{
    /**
     * Status, availability and the pulled-model list run on every health check
     * and model listing. An unreachable host that drops packets must cost this
     * bound once per request, not the library's 10 s connect timeout per call.
     */
    private const PROBE_TIMEOUT_SECONDS = 2.0;

    /** The longest a chat waits when the caller does not name a shorter deadline. */
    private const MAX_CHAT_SECONDS = 300;

    /** @var list<array{name: string, size: int, family: string}>|null */
    private ?array $probedModels = null;

    private ?\Throwable $probeFailure = null;

    public function __construct(
        private LoggerInterface $logger,
        private string $baseUrl,
        private HttpClientInterface $httpClient,
        private StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
    ) {
    }

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'ollama',
            displayName: 'Ollama',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Embedding],
            // The host is OLLAMA_BASE_URL; the Messages gateway needs no key for it.
            endpoints: [ProviderEndpoint::localChatCompletions()],
            key: null,
            dialects: new ProviderDialects(structuredOutput: StructuredOutputDialect::OLLAMA_FORMAT),
            // Last resort on first run, when a chat-capable model is already
            // pulled. gpt-oss:20b is what the entrypoint downloads, so these
            // bindings reference a model that can exist on a self-hosted install;
            // the providerId "gpt-oss:20b" normalises to "gpt-oss-20b" in keys.
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'ollama:gpt-oss-20b:chat',
                'TOOLS' => 'ollama:gpt-oss-20b:chat',
                'ANALYZE' => 'ollama:gpt-oss-20b:chat',
                'SORT' => 'ollama:gpt-oss-20b:chat',
                'PLAN' => 'ollama:gpt-oss-20b:chat',
                'SUMMARIZE' => 'ollama:gpt-oss-20b:chat',
                'MEM' => 'ollama:gpt-oss-20b:mem',
            ], preferenceRank: 12),
            pricing: ProviderPricing::none(),
            liveVoiceSessions: null,
            branding: ProviderBranding::local('simple-icons:ollama', helpKey: 'ollama', helpUrl: 'https://ollama.com/download', helpIsDownload: true, helpFields: ['OLLAMA_BASE_URL']),
        );
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function getDisplayName(): string
    {
        return 'Ollama';
    }

    public function getDescription(): string
    {
        return 'Local AI model runner for privacy-first deployments';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding'];
    }

    public function getDefaultModels(): array
    {
        return []; // Models come from DB (BMODELS), not provider
    }

    public function getStatus(): array
    {
        if (!$this->isConfigured()) {
            return [
                'healthy' => false,
                'error' => 'Server URL not configured',
            ];
        }

        try {
            $start = microtime(true);
            $models = $this->probeModels();
            $latency = (microtime(true) - $start) * 1000;

            return [
                'healthy' => true,
                'latency_ms' => round($latency, 2),
                'error_rate' => 0.0,
                'active_connections' => 0,
                'models' => count($models),
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isAvailable(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->probeModels();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function reset(): void
    {
        $this->probedModels = null;
        $this->probeFailure = null;
    }

    /**
     * One bounded `/api/tags` call per request, shared by every status check.
     *
     * @return list<array{name: string, size: int, family: string}>
     */
    private function probeModels(): array
    {
        if (null !== $this->probeFailure) {
            throw $this->probeFailure;
        }
        if (null !== $this->probedModels) {
            return $this->probedModels;
        }

        try {
            return $this->probedModels = $this->listPulledModels(self::PROBE_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            throw $this->probeFailure = $e;
        }
    }

    /**
     * Local AI is opt-in (the `local-ai` compose profile), so an empty
     * OLLAMA_BASE_URL is the normal state of a stock install, not a failure.
     */
    private function isConfigured(): bool
    {
        return '' !== trim($this->baseUrl);
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'OLLAMA_BASE_URL' => [
                'required' => true,
                'hint' => 'Ollama server URL (e.g., http://ollama:11434)',
            ],
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'ollama');
        }

        try {
            $model = $options['model'];

            $this->logger->info('Ollama chat request', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $requestBody = $this->buildChatRequestBody($messages, $options, $model);

            return $this->chatWithin($requestBody, self::requestedTimeout($options) ?? self::MAX_CHAT_SECONDS);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Ollama chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            $errorMsg = $e->getMessage();
            if (false !== stripos($errorMsg, '404')
                || false !== stripos($errorMsg, 'not found')
                || false !== stripos($errorMsg, 'model')) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model, $e);
            }

            throw (new ProviderFailureFactory())->fromThrowable($e, 'ollama', 'chat');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'ollama');
        }

        try {
            $model = $options['model'];
            $modelFeatures = $options['modelFeatures'] ?? [];
            $supportsReasoning = in_array('reasoning', $modelFeatures, true);

            // Check if model exists before attempting to use it
            $availableModels = $this->getAvailableModels();
            if (empty($availableModels)) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model);
            }

            $modelExists = false;
            foreach ($availableModels as $availableModel) {
                if (false !== stripos($availableModel, $model) || false !== stripos($model, $availableModel)) {
                    $modelExists = true;
                    break;
                }
            }

            if (!$modelExists) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model);
            }

            $this->logger->info('Ollama streaming chat START', [
                'model' => $model,
                'message_count' => count($messages),
                'supportsReasoning' => $supportsReasoning,
            ]);

            $ollamaMessages = $this->convertMessages($messages);

            $requestBody = [
                'model' => $model,
                'messages' => $ollamaMessages,
                'stream' => true,
            ];

            if (isset($options['max_tokens'])) {
                $requestBody['options'] = [
                    'num_predict' => $options['max_tokens'],
                ];
            }

            $schema = $options['structured_output'] ?? null;
            if ($schema instanceof StructuredOutputSchema) {
                $requestBody = array_merge($requestBody, $this->structuredOutputTranslator->translate($this->getName(), $model, true, $schema));
            }

            // Stream directly via HttpClient so we can read the `thinking` field
            // that the Ollama PHP SDK (ChatMessageResponse) silently drops.
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/chat', self::streamHttpOptions($requestBody, $options));

            $chunkCount = 0;
            $fullResponse = '';
            $promptTokens = 0;
            $completionTokens = 0;
            $lineBuffer = '';

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isFirst()) {
                    try {
                        $statusCode = $response->getStatusCode();
                    } catch (\Throwable $e) {
                        throw new ProviderException('Ollama API HTTP error: '.$e->getMessage(), 'ollama', null, 0, $e);
                    }
                    if (200 !== $statusCode) {
                        throw new ProviderException(sprintf('Ollama API HTTP error %d', $statusCode), 'ollama');
                    }
                }

                if (null !== $chunk->getError()) {
                    throw new ProviderException('Ollama transport error: '.$chunk->getError(), 'ollama');
                }

                $content = $chunk->getContent();

                if ($chunk->isLast() && '' === $content) {
                    break;
                }

                if ('' === $content) {
                    continue;
                }

                $lineBuffer .= $content;

                while (false !== ($newlinePos = strpos($lineBuffer, "\n"))) {
                    $line = substr($lineBuffer, 0, $newlinePos);
                    $lineBuffer = substr($lineBuffer, $newlinePos + 1);

                    $line = trim($line);
                    if ('' === $line) {
                        continue;
                    }

                    try {
                        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        $this->logger->warning('Ollama: Skipping malformed JSON line', [
                            'line' => substr($line, 0, 200),
                        ]);
                        continue;
                    }

                    if (isset($data['error'])) {
                        throw new ProviderException('Ollama API error: '.$data['error'], 'ollama');
                    }

                    $msg = $data['message'] ?? [];
                    $textChunk = $msg['content'] ?? '';
                    $thinkingChunk = $msg['thinking'] ?? '';

                    // Sanitize UTF-8
                    if ('' !== $thinkingChunk) {
                        $thinkingChunk = mb_convert_encoding($thinkingChunk, 'UTF-8', 'UTF-8');
                    }
                    if ('' !== $textChunk) {
                        $textChunk = mb_convert_encoding($textChunk, 'UTF-8', 'UTF-8');
                    }

                    // Forward thinking tokens as reasoning
                    if ('' !== $thinkingChunk) {
                        $callback(['type' => 'reasoning', 'content' => $thinkingChunk]);
                        ++$chunkCount;
                    }

                    // Forward content tokens
                    if ('' !== $textChunk) {
                        $fullResponse .= $textChunk;
                        $callback(['type' => 'content', 'content' => $textChunk]);
                        ++$chunkCount;

                        if (1 === $chunkCount || ('' === $fullResponse && strlen($textChunk) > 0)) {
                            $this->logger->info('Ollama: First content chunk sent', [
                                'length' => strlen($textChunk),
                                'preview' => substr($textChunk, 0, 50),
                            ]);
                        }
                    }

                    if (!empty($data['done'])) {
                        $promptTokens = $data['prompt_eval_count'] ?? 0;
                        $completionTokens = $data['eval_count'] ?? 0;

                        $doneReason = $data['done_reason'] ?? 'stop';
                        $finishReason = ('length' === $doneReason) ? 'length' : 'stop';
                        $callback(['type' => 'finish', 'finish_reason' => $finishReason]);

                        $this->logger->info('Ollama: Stream done', [
                            'prompt_tokens' => $promptTokens,
                            'completion_tokens' => $completionTokens,
                            'done_reason' => $doneReason,
                        ]);
                        break 2;
                    }
                }
            }

            $this->logger->info('Ollama: Streaming complete', [
                'chunks_sent' => $chunkCount,
                'total_length' => strlen($fullResponse),
            ]);

            return [
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                    'cached_tokens' => 0,
                    'cache_creation_tokens' => 0,
                ],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Ollama streaming error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMsg = $e->getMessage();
            if (false !== stripos($errorMsg, '404')
                || false !== stripos($errorMsg, 'not found')
                || false !== stripos($errorMsg, 'model')) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model, $e);
            }

            throw (new ProviderFailureFactory())->fromThrowable($e, 'ollama', 'chat_stream', 'Ollama streaming error');
        }
    }

    /**
     * Build the non-streaming `/api/chat` request body sent through the
     * Ollama SDK client.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatRequestBody(array $messages, array $options, string $model): array
    {
        $requestBody = [
            'model' => $model,
            'messages' => $this->convertMessages($messages),
        ];

        $schema = $options['structured_output'] ?? null;
        if ($schema instanceof StructuredOutputSchema) {
            $requestBody = array_merge($requestBody, $this->structuredOutputTranslator->translate($this->getName(), $model, false, $schema));
        }

        return $requestBody;
    }

    /**
     * A research step names how long this call may block. Absent, chat keeps
     * the provider default. Never longer than the five-minute cap.
     *
     * @param array<string, mixed> $options
     */
    private static function requestedTimeout(array $options): ?int
    {
        $requested = $options['timeout'] ?? null;
        if (!is_int($requested) || $requested < 1) {
            return null;
        }

        return min(self::MAX_CHAT_SECONDS, $requested);
    }

    /**
     * Idle timeout alone lets a slow stream run past a named deadline.
     * The five-minute default stays idle-only, as it was.
     *
     * @param array<string, mixed> $requestBody
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function streamHttpOptions(array $requestBody, array $options): array
    {
        $named = self::requestedTimeout($options);
        $http = [
            'json' => $requestBody,
            'buffer' => false,
            'timeout' => $named ?? self::MAX_CHAT_SECONDS,
        ];
        if (null !== $named) {
            $http['max_duration'] = $named;
        }

        return $http;
    }

    /**
     * One non-streaming chat that stops at $timeoutSeconds. The SDK client
     * has no per-request deadline, so this goes through the HTTP client.
     *
     * @param array<string, mixed> $requestBody
     *
     * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}}
     */
    private function chatWithin(array $requestBody, int $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/chat', [
            'json' => $requestBody + ['stream' => false],
            'timeout' => $timeoutSeconds,
            'max_duration' => $timeoutSeconds,
        ]);
        $payload = $response->toArray();
        $message = $payload['message'] ?? null;
        $content = is_array($message) && is_string($message['content'] ?? null) ? $message['content'] : '';
        $promptTokens = is_numeric($payload['prompt_eval_count'] ?? null) ? (int) $payload['prompt_eval_count'] : 0;
        $completionTokens = is_numeric($payload['eval_count'] ?? null) ? (int) $payload['eval_count'] : 0;

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ],
        ];
    }

    /**
     * Convert OpenAI-style messages to Ollama Chat API format.
     *
     * Handles multimodal content (images) by extracting base64 data from
     * OpenAI's content array format into Ollama's `images` field.
     *
     * @return array<array{role: string, content: string, images?: list<string>}>
     */
    private function convertMessages(array $messages): array
    {
        $ollamaMessages = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            $ollamaMessage = ['role' => $role];

            if (is_array($content)) {
                $textParts = [];
                $images = [];

                foreach ($content as $part) {
                    if (is_string($part)) {
                        $textParts[] = $part;
                    } elseif (is_array($part)) {
                        $type = $part['type'] ?? '';
                        if ('text' === $type) {
                            $textParts[] = $part['text'] ?? '';
                        } elseif ('image_url' === $type) {
                            $url = $part['image_url']['url'] ?? '';
                            if (str_starts_with($url, 'data:')) {
                                $base64 = preg_replace('/^data:[^;]+;base64,/', '', $url);
                                if ($base64) {
                                    $images[] = $base64;
                                }
                            }
                        }
                    }
                }

                $ollamaMessage['content'] = implode("\n", $textParts);
                if (!empty($images)) {
                    $ollamaMessage['images'] = $images;
                }
            } else {
                $ollamaMessage['content'] = $content;
            }

            $ollamaMessages[] = $ollamaMessage;
        }

        return $ollamaMessages;
    }

    /**
     * Whether a specific model is actually pulled on the Ollama server.
     *
     * isAvailable() only proves the server answers — a fresh install has a
     * reachable Ollama with zero (or embedding-only) models, so anything that
     * wants to route CHAT here must check the concrete model first.
     * Tags may carry an implicit ":latest" suffix.
     */
    public function hasModel(string $model): bool
    {
        $model = strtolower(trim($model));
        if ('' === $model) {
            return false;
        }
        $wanted = [$model];
        if (!str_contains($model, ':')) {
            $wanted[] = $model.':latest';
        }

        foreach ($this->getAvailableModels() as $name) {
            if (in_array(strtolower($name), $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of available models from Ollama.
     *
     * Public because the health monitor reports which models are actually
     * pulled: a reachable Ollama holding nothing is the most common reason a
     * self-hosted chat fails, and it is invisible to isAvailable().
     * Returns an empty array both when nothing is pulled and when the call
     * fails, so callers that need to tell those apart check isAvailable() too.
     */
    public function getAvailableModels(): array
    {
        // An empty base URL is a stock install, not a failure. Listing would
        // throw "URI must include a scheme and host" on every call.
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            return array_column($this->probeModels(), 'name');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list Ollama models', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * List the models actually pulled on the server, with size and family.
     *
     * Uses the native `GET /api/tags` endpoint (richer than the OpenAI-style
     * `/v1/models`): the model import preview shows size and family so an admin
     * recognises what a bare tag like `qwen3:32b` is. Throws on transport
     * failure so the caller can tell "unreachable" from "nothing pulled".
     *
     * @return list<array{name: string, size: int, family: string}>
     */
    public function listPulledModels(float $timeoutSeconds = 10.0): array
    {
        $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').'/api/tags', [
            'timeout' => $timeoutSeconds,
            'max_duration' => $timeoutSeconds,
        ]);
        $body = $response->toArray();

        $out = [];
        foreach ($body['models'] ?? [] as $model) {
            if (!is_array($model)) {
                continue;
            }
            $name = $model['name'] ?? $model['model'] ?? '';
            if (!is_string($name) || '' === $name) {
                continue;
            }
            $family = $model['details']['family'] ?? '';
            $out[] = [
                'name' => $name,
                'size' => is_numeric($model['size'] ?? null) ? (int) $model['size'] : 0,
                'family' => is_string($family) ? $family : '',
            ];
        }

        return $out;
    }

    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'ollama');
        }

        try {
            $embedded = $this->embedViaHttp($options['model'], [$text]);
            $promptTokens = $embedded['prompt_tokens'];

            return [
                'embedding' => $embedded['embeddings'][0] ?? [],
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'total_tokens' => $promptTokens,
                ],
            ];
        } catch (\Exception $e) {
            throw new ProviderException('Ollama embedding error: '.$e->getMessage(), 'ollama');
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'ollama');
        }

        if (empty($texts)) {
            return ['embeddings' => [], 'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0]];
        }

        try {
            $embedded = $this->embedViaHttp($options['model'], $texts);

            return [
                'embeddings' => $embedded['embeddings'],
                'usage' => [
                    'prompt_tokens' => $embedded['prompt_tokens'],
                    'total_tokens' => $embedded['prompt_tokens'],
                ],
            ];
        } catch (\Exception $e) {
            throw new ProviderException('Ollama batch embedding error: '.$e->getMessage(), 'ollama');
        }
    }

    /**
     * @param list<string> $input
     *
     * @return array{embeddings: list<array<int, float>>, prompt_tokens: int}
     */
    private function embedViaHttp(string $model, array $input): array
    {
        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/embed', [
            'json' => ['model' => $model, 'input' => $input],
            'timeout' => self::MAX_CHAT_SECONDS,
            'max_duration' => self::MAX_CHAT_SECONDS,
        ]);
        $payload = $response->toArray();
        $embeddings = [];
        foreach ($payload['embeddings'] ?? [] as $vector) {
            if (is_array($vector)) {
                $embeddings[] = $vector;
            }
        }
        $promptTokens = is_numeric($payload['prompt_eval_count'] ?? null) ? (int) $payload['prompt_eval_count'] : 0;

        return ['embeddings' => $embeddings, 'prompt_tokens' => $promptTokens];
    }

    public function getDimensions(string $model): int
    {
        return match (true) {
            str_contains($model, 'bge-m3') => 1024,
            str_contains($model, 'nomic-embed-text') => 768,
            str_contains($model, 'mxbai-embed-large') => 1024,
            str_contains($model, 'all-minilm') => 384,
            default => 1024, // Default to 1024 for Ollama models
        };
    }
}
