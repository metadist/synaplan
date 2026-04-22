<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\EmbeddingProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Workers AI — Embedding provider for bge-m3.
 *
 * Uses the native REST API (not OpenAI-compat) for simplicity:
 *   POST /client/v4/accounts/{ACCOUNT_ID}/ai/run/@cf/baai/bge-m3
 *
 * Two use-cases:
 *  1. Local dev — fast cloud embeddings without running Ollama bge-m3
 *  2. Production fallback — auto-failover when the primary provider is down
 *
 * @see https://developers.cloudflare.com/workers-ai/models/bge-m3/
 */
class CloudflareProvider implements EmbeddingProviderInterface
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4/accounts';
    private const DEFAULT_MODEL = '@cf/baai/bge-m3';
    private const BGE_M3_DIMENSIONS = 1024;
    private const MAX_BATCH_SIZE = 100;
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $accountId = null,
        private readonly ?string $apiToken = null,
    ) {
    }

    public function getName(): string
    {
        return 'cloudflare';
    }

    public function getDisplayName(): string
    {
        return 'Cloudflare Workers AI';
    }

    public function getDescription(): string
    {
        return 'Cloudflare Workers AI — fast, low-cost bge-m3 embeddings via edge network';
    }

    public function getCapabilities(): array
    {
        return ['embedding'];
    }

    public function getDefaultModels(): array
    {
        return ['embedding' => self::DEFAULT_MODEL];
    }

    public function getStatus(): array
    {
        if (!$this->isAvailable()) {
            return [
                'healthy' => false,
                'error' => 'CLOUDFLARE_ACCOUNT_ID or CLOUDFLARE_API_TOKEN not configured',
            ];
        }

        return ['healthy' => true];
    }

    public function isAvailable(): bool
    {
        return !empty($this->accountId) && !empty($this->apiToken);
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'CLOUDFLARE_ACCOUNT_ID' => [
                'required' => true,
                'hint' => 'Find your Account ID in the Cloudflare dashboard → Workers & Pages → Overview',
            ],
            'CLOUDFLARE_API_TOKEN' => [
                'required' => true,
                'hint' => 'Create an API Token at dash.cloudflare.com/profile/api-tokens with Workers AI permissions',
            ],
        ];
    }

    public function embed(string $text, array $options = []): array
    {
        $this->ensureAvailable();

        $model = $options['model'] ?? self::DEFAULT_MODEL;

        try {
            $data = $this->callApi($model, [$text]);

            $embedding = $data['data'][0] ?? [];
            if (empty($embedding)) {
                throw new ProviderException('Cloudflare returned empty embedding', 'cloudflare');
            }

            return [
                'embedding' => $embedding,
                'usage' => [
                    'prompt_tokens' => 0,
                    'total_tokens' => 0,
                ],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Cloudflare embedding error', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);

            throw new ProviderException('Cloudflare embedding error: '.$e->getMessage(), 'cloudflare', null, 0, $e);
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        $this->ensureAvailable();

        if (empty($texts)) {
            return [
                'embeddings' => [],
                'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
            ];
        }

        $model = $options['model'] ?? self::DEFAULT_MODEL;

        try {
            $allEmbeddings = [];

            foreach (array_chunk($texts, self::MAX_BATCH_SIZE) as $chunk) {
                $data = $this->callApi($model, $chunk);
                foreach ($data['data'] as $embedding) {
                    $allEmbeddings[] = $embedding;
                }
            }

            return [
                'embeddings' => $allEmbeddings,
                'usage' => [
                    'prompt_tokens' => 0,
                    'total_tokens' => 0,
                ],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Cloudflare batch embedding error', [
                'error' => $e->getMessage(),
                'model' => $model,
                'batch_size' => count($texts),
            ]);

            throw new ProviderException('Cloudflare batch embedding error: '.$e->getMessage(), 'cloudflare', null, 0, $e);
        }
    }

    public function getDimensions(string $model): int
    {
        return self::BGE_M3_DIMENSIONS;
    }

    /**
     * @param string[] $texts
     *
     * @return array{data: array<array<float>>, shape: array<int>}
     */
    private function callApi(string $model, array $texts): array
    {
        $url = sprintf('%s/%s/ai/run/%s', self::API_BASE, $this->accountId, $model);

        $this->logger->info('Cloudflare embedding request', [
            'model' => $model,
            'batch_size' => count($texts),
        ]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiToken,
                'Content-Type' => 'application/json',
            ],
            'json' => ['text' => $texts],
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        $json = $response->toArray(false);

        if (!($json['success'] ?? false)) {
            $errors = $json['errors'] ?? [];
            $errorMsg = !empty($errors) ? json_encode($errors) : 'Unknown Cloudflare API error';
            throw new ProviderException('Cloudflare API error: '.$errorMsg, 'cloudflare');
        }

        return $json['result'] ?? throw new ProviderException('Cloudflare API returned no result', 'cloudflare');
    }

    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw ProviderException::missingApiKey('cloudflare', 'CLOUDFLARE_ACCOUNT_ID / CLOUDFLARE_API_TOKEN');
        }
    }
}
