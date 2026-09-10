<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Client;

use App\Plug\PlugConfigService;
use App\Plug\PlugKeyStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST https://api.tavily.com/search.
 */
final readonly class TavilyClient
{
    private const ENDPOINT = 'https://api.tavily.com/search';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PlugKeyStore $keys,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function hasKey(): bool
    {
        $key = $this->keys->getKey('tavily');

        return null !== $key && '' !== $key;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function search(array $body): array
    {
        $key = $this->keys->getKey('tavily');
        if (null === $key || '' === $key) {
            throw new \RuntimeException('Tavily API key is not configured');
        }

        $body['api_key'] = $key;
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'timeout' => $this->plugConfig->webSearchTimeoutMs() / 1000,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('Tavily search returned HTTP '.$status);
        }

        return $response->toArray(false);
    }

    public function probe(): void
    {
        $this->search([
            'query' => 'synaplan',
            'max_results' => 1,
            'search_depth' => 'basic',
        ]);
    }
}
