<?php

namespace App\AI\Provider\OpenAI;

use App\AI\Credential\ProviderKeyStore;
use OpenAI;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The transports the OpenAI capability classes share: the API key (an
 * explicit override, else the provider key store, so a key saved in the
 * admin UI applies without a restart), the SDK client built for the current
 * key, Symfony's client for the requests the SDK does not make, and the
 * native cURL call of the gpt-image request.
 */
final class OpenAIApiClient
{
    public const PROVIDER = 'openai';
    public const KEY_ENV = 'OPENAI_API_KEY';

    private ?OpenAI\Client $client = null;

    /** Key the cached client was built with (rebuild on key change). */
    private ?string $clientKey = null;

    /**
     * @param (\Closure(string): OpenAI\Client)|null $sdkFactory builds the SDK client for a key; `\OpenAI::client()` when absent
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OpenAICurlTransport $curl,
        private readonly ?string $apiKey = null,
        private readonly ?ProviderKeyStore $keyStore = null,
        private readonly ?\Closure $sdkFactory = null,
    ) {
    }

    public function key(): ?string
    {
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            return $this->apiKey;
        }

        return $this->keyStore?->getKey(self::PROVIDER);
    }

    /**
     * Lazily build the API client with the CURRENT key; rebuilt when the key
     * changes at runtime (admin UI save / env import).
     */
    public function sdk(): ?OpenAI\Client
    {
        $key = $this->key();
        if (null === $key || '' === $key) {
            $this->client = null;
            $this->clientKey = null;

            return null;
        }

        if (null === $this->client || $this->clientKey !== $key) {
            $this->client = null !== $this->sdkFactory ? ($this->sdkFactory)($key) : \OpenAI::client($key);
            $this->clientKey = $key;
        }

        return $this->client;
    }

    /**
     * A JSON POST through Symfony's client, for the requests the SDK does not
     * make.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers further headers after the bearer key
     * @param array<string, mixed>  $options further request options, e.g. `buffer`
     */
    public function postJson(string $url, array $body, ?string $key, int $timeout, array $headers = [], array $options = []): ResponseInterface
    {
        return $this->httpClient->request('POST', $url, [
            'headers' => ['Authorization' => 'Bearer '.$key] + $headers,
            'json' => $body,
        ] + $options + ['timeout' => $timeout]);
    }

    public function getBytes(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 60, 'max_duration' => 60]);
        if (200 !== $response->getStatusCode()) {
            throw new \Exception('Failed to download image from URL');
        }

        return $response->getContent();
    }

    public function stream(ResponseInterface $response): ResponseStreamInterface
    {
        return $this->httpClient->stream($response);
    }

    /**
     * @return array{0: string|bool, 1: int, 2: string} the response body, the HTTP status and the cURL error
     */
    public function curlPostJson(string $url, ?string $key, string|false $body, int $timeout): array
    {
        return $this->curl->postJson($url, $key, $body, $timeout);
    }
}
