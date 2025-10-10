<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Exception\ProviderException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class AnthropicProvider implements ChatProviderInterface
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $apiKey = null,
        private string $baseUrl = 'https://api.anthropic.com/v1',
        private int $timeout = 60
    ) {}

    public function getName(): string
    {
        return 'anthropic';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        try {
            $start = microtime(true);
            
            // Simple health check - minimal request
            $response = $this->httpClient->request('GET', $this->baseUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 5,
            ]);

            $latency = (microtime(true) - $start) * 1000;

            return [
                'healthy' => $response->getStatusCode() === 200,
                'latency_ms' => round($latency, 2),
                'error_rate' => 0.0,
                'active_connections' => 0,
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
        return !empty($this->apiKey);
    }

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'anthropic');
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/messages', [
                'headers' => $this->getHeaders(),
                'json' => [
                    'model' => $options['model'],
                    'max_tokens' => $options['max_tokens'] ?? 4096,
                    'messages' => $messages,
                ],
                'timeout' => $this->timeout,
            ]);

            $data = $response->toArray();
            return $data['content'][0]['text'] ?? '';
        } catch (\Exception $e) {
            $this->logger->error('Anthropic chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown'
            ]);
            throw new ProviderException(
                'Anthropic chat error: ' . $e->getMessage(),
                'anthropic'
            );
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'anthropic');
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/messages', [
                'headers' => array_merge($this->getHeaders(), [
                    'Accept' => 'text/event-stream',
                ]),
                'json' => [
                    'model' => $options['model'],
                    'max_tokens' => $options['max_tokens'] ?? 4096,
                    'messages' => $messages,
                    'stream' => true,
                ],
                'timeout' => $this->timeout,
            ]);

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isLast()) {
                    break;
                }
                
                $content = $chunk->getContent();
                if ($content) {
                    $callback($content);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Anthropic streaming error', [
                'error' => $e->getMessage()
            ]);
            throw new ProviderException(
                'Anthropic streaming error: ' . $e->getMessage(),
                'anthropic'
            );
        }
    }

    private function getHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
    }
}

