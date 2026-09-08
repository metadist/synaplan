<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Client;

use App\AI\Credential\ProviderKeyStore;
use App\Plug\PlugConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST https://api.perplexity.ai/search — shares the chat provider key.
 */
final readonly class PerplexitySearchClient
{
    private const ENDPOINT = 'https://api.perplexity.ai/search';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ProviderKeyStore $keys,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function hasKey(): bool
    {
        $key = $this->keys->getKey('perplexity');

        return null !== $key && '' !== $key;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function search(array $body): array
    {
        $key = $this->keys->getKey('perplexity');
        if (null === $key || '' === $key) {
            throw new \RuntimeException('Perplexity API key is not configured');
        }

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'timeout' => $this->plugConfig->webSearchTimeoutMs() / 1000,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$key,
            ],
            'json' => $body,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('Perplexity search returned HTTP '.$status);
        }

        return $response->toArray(false);
    }
}
