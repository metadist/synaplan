<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Exception\ProviderException;
use ArdaGnsrn\Ollama\Ollama;
use Psr\Log\LoggerInterface;

class OllamaProvider implements ChatProviderInterface, EmbeddingProviderInterface
{
    private $client;

    public function __construct(
        private LoggerInterface $logger,
        private string $baseUrl
    ) {
        $this->client = Ollama::client($this->baseUrl);
    }

    public function getName(): string
    {
        return 'ollama';
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

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'ollama');
        }

        try {
            $model = $options['model'];
            $reasoning = $options['reasoning'] ?? false;
            
            $this->logger->info('Ollama chat request', [
                'model' => $model,
                'message_count' => count($messages),
                'reasoning_requested' => $reasoning
            ]);

            $requestOptions = [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
            ];

            if ($reasoning) {
                $requestOptions['options'] = [
                    'enable_reasoning' => true
                ];
            }

            $response = $this->client->chat()->create($requestOptions);

            return $response->message->content ?? '';
        } catch (\Exception $e) {
            $this->logger->error('Ollama chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown'
            ]);
            throw new ProviderException(
                'Ollama chat error: ' . $e->getMessage(),
                'ollama'
            );
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'ollama');
        }

        try {
            $model = $options['model'];
            $reasoning = $options['reasoning'] ?? false;
            
            $this->logger->info('Ollama streaming chat request', [
                'model' => $model,
                'message_count' => count($messages),
                'reasoning_requested' => $reasoning
            ]);

            $requestOptions = [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
            ];

            if ($reasoning) {
                $requestOptions['options'] = [
                    'enable_reasoning' => true
                ];
            }

            $stream = $this->client->chat()->create($requestOptions);

            foreach ($stream as $response) {
                $content = $response->message->content ?? '';
                if ($content) {
                    $callback($content);
                }
                
                // Check if stream is done
                if (isset($response->done) && $response->done) {
                    break;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Ollama streaming error', [
                'error' => $e->getMessage()
            ]);
            throw new ProviderException(
                'Ollama streaming error: ' . $e->getMessage(),
                'ollama'
            );
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
            throw new ProviderException(
                'Ollama embedding error: ' . $e->getMessage(),
                'ollama'
            );
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'ollama');
        }

        return array_map(fn($text) => $this->embed($text, $options), $texts);
    }

    public function getDimensions(string $model): int
    {
        return match(true) {
            str_contains($model, 'nomic-embed-text') => 768,
            str_contains($model, 'mxbai-embed-large') => 1024,
            default => 768
        };
    }
}

