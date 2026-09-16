<?php

declare(strict_types=1);

namespace App\Plug\Rerank\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST JSON to a rerank HTTP API. Lives under Plug/ so callers stay behind the port.
 */
final readonly class HttpRerankClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     *
     * @return array<int|string, mixed>
     */
    public function postJson(string $url, array $headers, array $body, float $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', $url, [
            'timeout' => $timeoutSeconds,
            'headers' => $headers + [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('Rerank HTTP '.$status.' from '.$url);
        }

        return $response->toArray(false);
    }
}
