<?php

declare(strict_types=1);

namespace App\Service\Egress;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A short GET or JSON POST through the shared HTTP client. Replaces the raw
 * cURL calls that downloaded a generated file or probed a local service.
 * Timeouts stay the values those calls used.
 */
final readonly class HttpBytes
{
    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    /**
     * @throws \Exception the same sentences the cURL downloader used
     */
    public function download(string $url, int $timeoutSeconds = 60): string
    {
        try {
            $response = $this->http->request('GET', $url, $this->options($timeoutSeconds));
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new \Exception('cURL download failed (HTTP 0): '.$e->getMessage(), 0, $e);
        }
        if (200 !== $status) {
            throw new \Exception('cURL download failed (HTTP '.$status.'): ');
        }

        return $body;
    }

    /**
     * @return array{0: int, 1: string} status and body; status 0 when the connection failed
     */
    public function probe(string $url, int $timeoutSeconds = 5, ?string $user = null, ?string $password = null): array
    {
        $options = $this->options($timeoutSeconds);
        if (null !== $user && '' !== $user) {
            $options['auth_basic'] = [$user, $password ?? ''];
        }
        try {
            $response = $this->http->request('GET', $url, $options);

            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface) {
            return [0, ''];
        }
    }

    /**
     * @return array{0: string, 1: int, 2: string} body, status, error
     */
    public function postJson(string $url, ?string $key, string $body, int $timeoutSeconds): array
    {
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.($key ?? ''),
                ],
                'body' => $body,
                'timeout' => $timeoutSeconds,
                'max_duration' => $timeoutSeconds,
            ]);

            return [$response->getContent(false), $response->getStatusCode(), ''];
        } catch (TransportExceptionInterface $e) {
            return ['', 0, $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(int $timeoutSeconds): array
    {
        $options = [
            'timeout' => $timeoutSeconds,
            'max_duration' => $timeoutSeconds,
            'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; PHP; Synaplan)'],
        ];
        if (\defined('CURLOPT_IPRESOLVE') && \defined('CURL_IPRESOLVE_V4')) {
            $options['extra'] = ['curl' => [\CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4]];
        }

        return $options;
    }
}
