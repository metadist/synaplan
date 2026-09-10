<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Client;

use App\Plug\PlugConfigService;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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

    /**
     * Cheap reachability check. A set URL is not enough — a stopped sidecar
     * still has SEARXNG_BASE_URL.
     */
    public function probe(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('SEARXNG_BASE_URL is unset or disabled');
        }

        try {
            $response = $this->httpClient->request('GET', $this->endpoint('/search'), [
                'timeout' => 5,
                'query' => [
                    'q' => 'synaplan',
                    'format' => 'json',
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException('SearXNG probe returned HTTP '.$status);
            }
            $data = $response->toArray(false);
            if (!isset($data['results']) || !\is_array($data['results'])) {
                throw new \RuntimeException('SearXNG probe returned no results array');
            }
        } catch (DecodingExceptionInterface $e) {
            throw new \RuntimeException('SearXNG probe returned no results array', 0, $e);
        } catch (TransportExceptionInterface $e) {
            $message = $e->getMessage();
            $lower = strtolower($message);
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                throw new \RuntimeException('SearXNG request timed out', 0, $e);
            }
            if (str_contains($lower, 'could not resolve') || str_contains($lower, 'nodename nor servname') || str_contains($lower, 'name or service not known')) {
                throw new \RuntimeException('SearXNG unavailable — could not resolve host', 0, $e);
            }
            if (str_contains($lower, 'refused')) {
                throw new \RuntimeException('SearXNG unavailable — connection refused', 0, $e);
            }

            throw new \RuntimeException('SearXNG unavailable: '.$message, 0, $e);
        }
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl(), '/').$path;
    }
}
