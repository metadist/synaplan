<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Client;

use App\Plug\PlugConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GET {base}/search?q=…&format=json.
 */
final readonly class SearxngClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private PlugConfigService $plugConfig,
        private string $baseUrl,
    ) {
    }

    public function baseUrl(): string
    {
        return trim($this->baseUrl);
    }

    public function isConfigured(): bool
    {
        $url = $this->baseUrl();

        return '' !== $url && 'disabled' !== strtolower($url);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    public function search(array $query): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('SEARXNG_BASE_URL is unset or disabled');
        }

        $response = $this->httpClient->request('GET', $this->endpoint('/search'), [
            'timeout' => $this->plugConfig->webSearchTimeoutMs() / 1000,
            'query' => $query,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('SearXNG search returned HTTP '.$status);
        }

        $data = $response->toArray(false);
        if (!isset($data['results']) || !\is_array($data['results'])) {
            throw new \RuntimeException('SearXNG search returned no results array');
        }

        return $data;
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl(), '/').$path;
    }
}
