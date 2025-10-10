<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Exception\ProviderException;
use OpenAI;
use Psr\Log\LoggerInterface;

class OpenAIProvider implements ChatProviderInterface, EmbeddingProviderInterface
{
    private $client;

    public function __construct(
        private LoggerInterface $logger,
        private ?string $apiKey = null
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
        return ['chat', 'embedding', 'vision'];
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

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw new ProviderException('OpenAI client not initialized', 'openai');
        }

        try {
            $reasoning = $options['reasoning'] ?? false;
            
            $requestOptions = [
                'model' => $options['model'],
                'messages' => $messages,
                'max_tokens' => $options['max_tokens'] ?? 4096,
                'temperature' => $options['temperature'] ?? 0.7,
            ];

            if ($reasoning) {
                $requestOptions['reasoning_effort'] = 'high';
            }

            $response = $this->client->chat()->create($requestOptions);

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
            throw new ProviderException('OpenAI client not initialized', 'openai');
        }

        try {
            $reasoning = $options['reasoning'] ?? false;
            
            $requestOptions = [
                'model' => $options['model'],
                'messages' => $messages,
                'max_tokens' => $options['max_tokens'] ?? 4096,
                'temperature' => $options['temperature'] ?? 0.7,
            ];

            if ($reasoning) {
                $requestOptions['reasoning_effort'] = 'high';
            }

            $stream = $this->client->chat()->createStreamed($requestOptions);

            foreach ($stream as $response) {
                $content = $response['choices'][0]['delta']['content'] ?? '';
                if ($content) {
                    $callback($content);
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException(
                'OpenAI streaming error: ' . $e->getMessage(),
                'openai'
            );
        }
    }

    // EmbeddingProviderInterface
    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw new ProviderException('OpenAI client not initialized', 'openai');
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
            throw new ProviderException('OpenAI client not initialized', 'openai');
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
}

