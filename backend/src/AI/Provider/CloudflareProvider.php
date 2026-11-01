<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderPricing;
use App\AI\Exception\ProviderException;
use App\AI\Interface\EmbeddingProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Workers AI — Embedding provider for bge-m3 and Qwen3-Embedding.
 *
 * Uses the native REST API (not OpenAI-compat) for simplicity:
 *   POST /client/v4/accounts/{ACCOUNT_ID}/ai/run/{model}
 *
 * Supported models:
 *   - @cf/baai/bge-m3           (1024-dim, multilingual)
 *   - @cf/qwen/qwen3-embedding-0.6b (1024-dim, instruction-aware, multilingual)
 *
 * Two use-cases:
 *  1. Local dev — fast cloud embeddings without running Ollama bge-m3
 *  2. Production fallback — auto-failover when the primary provider is down
 *
 * @see https://developers.cloudflare.com/workers-ai/models/bge-m3/
 * @see https://developers.cloudflare.com/workers-ai/models/qwen3-embedding-0.6b/
 */
#[AsAiProvider]
class CloudflareProvider implements EmbeddingProviderInterface, DescribesProviderInterface
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4/accounts';
    private const DEFAULT_MODEL = '@cf/baai/bge-m3';
    private const EMBEDDING_DIMENSIONS = 1024;
    private const MAX_BATCH_SIZE = 100;
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $http,
        private readonly ?string $accountId = null,
        private readonly ?string $apiToken = null,
    ) {
    }

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'cloudflare',
            displayName: 'Cloudflare Workers AI',
            capabilities: [ProviderCapability::Embedding],
            endpoints: [ProviderEndpoint::api(self::API_BASE)],
            // The token and the account id are environment-only; no row in the
            // platform key store.
            key: ProviderKeySpec::envOnly('CLOUDFLARE_API_TOKEN', 'Cloudflare API token with Workers AI access'),
            dialects: new ProviderDialects(),
            defaults: null,
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'simple-icons:cloudflare'),
        );
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
        return 'Cloudflare Workers AI — fast, low-cost embeddings via edge network (bge-m3, Qwen3-Embedding)';
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
        $instruction = $options['instruction'] ?? null;

        try {
            $data = $this->callApi($model, [$text], $instruction);

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
        $instruction = $options['instruction'] ?? null;

        try {
            $allEmbeddings = [];

            foreach (array_chunk($texts, self::MAX_BATCH_SIZE) as $chunk) {
                $data = $this->callApi($model, $chunk, $instruction);
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
        return self::EMBEDDING_DIMENSIONS;
    }

    /**
     * @param string[]    $texts
     * @param string|null $instruction Task instruction for Qwen3 models (ignored by bge-m3)
     *
     * @return array{data: array<array<float>>, shape: array<int>}
     */
    private function callApi(string $model, array $texts, ?string $instruction = null): array
    {
        $url = sprintf('%s/%s/ai/run/%s', self::API_BASE, $this->accountId, $model);

        $this->logger->info('Cloudflare embedding request', [
            'model' => $model,
            'batch_size' => count($texts),
            'has_instruction' => null !== $instruction,
        ]);

        $payload = ['text' => $texts];
        if (null !== $instruction && $this->supportsInstruction($model)) {
            $payload['instruction'] = $instruction;
        }

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]);
            $httpCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new ProviderException('Cloudflare cURL error: '.$e->getMessage(), 'cloudflare', null, 0, $e);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new ProviderException("Cloudflare API returned HTTP {$httpCode}: {$body}", 'cloudflare');
        }

        try {
            $json = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException('Cloudflare returned invalid JSON: '.$e->getMessage(), 'cloudflare');
        }

        if (!($json['success'] ?? false)) {
            $errors = $json['errors'] ?? [];
            $errorMsg = !empty($errors) ? json_encode($errors) : 'Unknown Cloudflare API error';
            throw new ProviderException('Cloudflare API error: '.$errorMsg, 'cloudflare');
        }

        return $json['result'] ?? throw new ProviderException('Cloudflare API returned no result', 'cloudflare');
    }

    private function supportsInstruction(string $model): bool
    {
        return str_contains($model, 'qwen');
    }

    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw ProviderException::missingApiKey('cloudflare', 'CLOUDFLARE_ACCOUNT_ID / CLOUDFLARE_API_TOKEN');
        }
    }
}
