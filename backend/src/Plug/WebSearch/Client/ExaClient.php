<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Client;

use App\Plug\PlugConfigService;
use App\Plug\PlugKeyStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST https://api.exa.ai/search.
 */
final readonly class ExaClient
{
    private const ENDPOINT = 'https://api.exa.ai/search';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PlugKeyStore $keys,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function hasKey(): bool
    {
        $key = $this->keys->getKey('exa');

        return null !== $key && '' !== $key;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function search(array $body): array
    {
        $key = $this->keys->getKey('exa');
        if (null === $key || '' === $key) {
            throw new \RuntimeException('Exa API key is not configured');
        }

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'timeout' => $this->plugConfig->webSearchTimeoutMs() / 1000,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-api-key' => $key,
            ],
            'json' => $body,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('Exa search returned HTTP '.$status);
        }

        return $response->toArray(false);
    }

    public function probe(): void
    {
        $this->search([
            'query' => 'synaplan',
            'numResults' => 1,
            'type' => 'auto',
        ]);
    }
}
