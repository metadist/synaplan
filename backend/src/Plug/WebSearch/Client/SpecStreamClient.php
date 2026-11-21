<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Client;

use App\Plug\PlugConfigService;
use App\Plug\PlugKeyStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GET https://api.specstream.ai/v1/web-search.
 */
final readonly class SpecStreamClient
{
    private const ENDPOINT = 'https://api.specstream.ai/v1/web-search';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PlugKeyStore $keys,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function hasKey(): bool
    {
        $key = $this->keys->getKey('specstream');

        return null !== $key && '' !== $key;
    }

    /**
     * @param array<string, int|string> $queryParams
     *
     * @return array<string, mixed>
     */
    public function search(array $queryParams): array
    {
        $key = $this->keys->getKey('specstream');
        if (null === $key || '' === $key) {
            throw new \RuntimeException('SpecStream API key is not configured');
        }

        $response = $this->httpClient->request('GET', self::ENDPOINT, [
            'timeout' => $this->plugConfig->webSearchTimeoutMs() / 1000,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$key,
            ],
            'query' => $queryParams,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('SpecStream search returned HTTP '.$status);
        }

        return $response->toArray(false);
    }

    public function probe(): void
    {
        $this->search([
            'q' => 'synaplan',
            'num' => 1,
            'speed' => 'fast',
        ]);
    }
}
