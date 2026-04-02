<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use ArdaGnsrn\Ollama\Ollama;
use Psr\Log\LoggerInterface;

class OllamaProvider implements ChatProviderInterface, EmbeddingProviderInterface
{
    private $client;

    public function __construct(
        private LoggerInterface $logger,
        private string $baseUrl,
    ) {
        // Set timeout to 5 minutes for slow CPU-based models
        ini_set('default_socket_timeout', 300);
        $this->client = Ollama::client($this->baseUrl);
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
        try {
            $start = microtime(true);
            $models = $this->client->models()->list();
            $latency = (microtime(true) - $start) * 1000;

            return [
                'healthy' => true,
                'latency_ms' => round($latency, 2),
                'error_rate' => 0.0,
                'active_connections' => 0,
                'models' => count($models->models ?? []),
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isAvailable(): bool
    {
        try {
            $this->client->models()->list();

            return true;
        } catch (\Exception $e) {
            return false;
        }
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

            $ollamaMessages = $this->convertMessages($messages);

            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => $ollamaMessages,
            ]);

            $promptTokens = $response->promptEvalCount ?? 0;
            $completionTokens = $response->evalCount ?? 0;

            $usage = [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ];

            return [
                'content' => $response->message->content ?? '',
                'usage' => $usage,
            ];
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

            throw new ProviderException('Ollama chat error: '.$e->getMessage(), 'ollama');
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

            $this->logger->info('🔵 Ollama streaming chat START', [
                'model' => $model,
                'message_count' => count($messages),
                'supportsReasoning' => $supportsReasoning,
            ]);

            $ollamaMessages = $this->convertMessages($messages);

            $requestOptions = [
                'model' => $model,
                'messages' => $ollamaMessages,
            ];

            if (isset($options['max_tokens'])) {
                $requestOptions['options'] = [
                    'num_predict' => $options['max_tokens'],
                ];
            }

            $stream = $this->client->chat()->createStreamed($requestOptions);

            $this->logger->info('🟡 Ollama: Stream created, iterating...');

            $chunkCount = 0;
            $fullResponse = '';
            $promptTokens = 0;
            $completionTokens = 0;

            foreach ($stream as $chunk) {
                $textChunk = $chunk->message->content ?? '';

                // Sanitize UTF-8 to prevent "Malformed UTF-8 characters" errors
                if (!empty($textChunk)) {
                    $textChunk = mb_convert_encoding($textChunk, 'UTF-8', 'UTF-8');
                }

                if (!empty($textChunk)) {
                    $fullResponse .= $textChunk;

                    $callback($textChunk);
                    ++$chunkCount;

                    if (1 === $chunkCount) {
                        $this->logger->info('🟢 Ollama: First chunk sent!', [
                            'length' => strlen($textChunk),
                            'preview' => substr($textChunk, 0, 50),
                        ]);
                    }
                }

                // Final chunk contains usage data
                if (isset($chunk->done) && $chunk->done) {
                    $promptTokens = $chunk->promptEvalCount ?? 0;
                    $completionTokens = $chunk->evalCount ?? 0;
                    $this->logger->info('🔵 Ollama: Stream done signal received', [
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,
                    ]);
                    break;
                }
            }

            // Note: The Ollama PHP SDK (ardagnsrn/ollama-php) does not expose
            // done_reason from the API response, so we cannot detect truncation
            // (done_reason="length"). Once the SDK adds this field, we should
            // emit callback(['type' => 'finish', 'finish_reason' => ...]) here.

            $this->logger->info('🔵 Ollama: Streaming complete', [
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
            $this->logger->error('🔴 Ollama streaming error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMsg = $e->getMessage();
            if (false !== stripos($errorMsg, '404')
                || false !== stripos($errorMsg, 'not found')
                || false !== stripos($errorMsg, 'model')) {
                throw ProviderException::noModelAvailable('chat', 'ollama', $model, $e);
            }

            throw new ProviderException('Ollama streaming error: '.$e->getMessage(), 'ollama', null, 0, $e);
        }
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
     * Get list of available models from Ollama.
     */
    private function getAvailableModels(): array
    {
        try {
            $models = $this->client->models()->list();
            $modelNames = [];
            foreach (($models->models ?? []) as $model) {
                $modelNames[] = $model->model ?? $model->name ?? '';
            }

            return array_filter($modelNames);
        } catch (\Exception $e) {
            $this->logger->error('Failed to list Ollama models', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'ollama');
        }

        try {
            $response = $this->client->embed()->create([
                'model' => $options['model'],
                'input' => [$text],
            ]);

            $arrRes = method_exists($response, 'toArray') ? $response->toArray() : (array) $response;

            return $arrRes['embeddings'][0] ?? [];
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
            return [];
        }

        try {
            $response = $this->client->embed()->create([
                'model' => $options['model'],
                'input' => $texts,
            ]);

            $arrRes = method_exists($response, 'toArray') ? $response->toArray() : (array) $response;

            return $arrRes['embeddings'] ?? [];
        } catch (\Exception $e) {
            throw new ProviderException('Ollama batch embedding error: '.$e->getMessage(), 'ollama');
        }
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
