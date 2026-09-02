<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches the docs manifest and page Markdown. HTTPS only; raw_url host
 * must already have been checked by {@see DocsManifest}.
 */
final readonly class PlatformDocsManifestClient
{
    private const TIMEOUT_SECONDS = 15;
    private const MAX_BYTES = 2_000_000;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @throws \RuntimeException
     */
    public function fetchManifest(string $url): DocsManifest
    {
        $this->assertHttps($url);
        $body = $this->get($url, 'application/json');

        try {
            return DocsManifest::fromJson($body);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Docs manifest rejected: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws \RuntimeException
     */
    public function fetchPage(DocsPage $page): string
    {
        $this->assertHttps($page->rawUrl);

        return $this->get($page->rawUrl, 'text/markdown');
    }

    private function get(string $url, string $accept): string
    {
        try {
            return $this->requestOnce($url, $accept);
        } catch (TransportExceptionInterface $first) {
            try {
                return $this->requestOnce($url, $accept);
            } catch (TransportExceptionInterface $retry) {
                throw new \RuntimeException(sprintf('Transport error fetching %s: %s', $url, $retry->getMessage()), 0, $retry);
            }
        }
    }

    /**
     * @throws TransportExceptionInterface
     * @throws \RuntimeException
     */
    private function requestOnce(string $url, string $accept): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 3,
                'headers' => ['Accept' => $accept],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('HTTP %d fetching %s', $status, $url));
            }
            $body = $response->getContent();
            if (strlen($body) > self::MAX_BYTES) {
                throw new \RuntimeException(sprintf('Response from %s exceeds %d bytes.', $url, self::MAX_BYTES));
            }

            return $body;
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf('HTTP error fetching %s: %s', $url, $e->getMessage()), 0, $e);
        }
    }

    private function assertHttps(string $url): void
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new \RuntimeException('Docs sync only accepts https:// URLs.');
        }
    }
}
