<?php

declare(strict_types=1);

namespace App\AI\Provider;

use OpenAI\Contracts\ClientContract;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds and caches the OpenAI SDK clients of {@see OpenAICompatibleProvider},
 * one per endpoint and header set, and adds the per-call headers of
 * {@see OpenAiCompatibleRequestHeaders} to the resolved endpoint.
 */
final class OpenAiCompatibleClients
{
    /** Per-call headers make the header set vary per user; the cache must not grow without bound in a long-lived worker. */
    private const MAX_CACHED_CLIENTS = 32;

    /** @var array<string, ClientContract> */
    private array $clients = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?OpenAiCompatibleRequestHeaders $requestHeaders = null,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * @template T of array{name: string, headers: array<string, string>}
     *
     * @param T                    $endpoint
     * @param array<string, mixed> $options
     *
     * @return T
     */
    public function withCallHeaders(array $endpoint, array $options): array
    {
        $extra = $this->requestHeaders?->forCall($endpoint['name'], $options) ?? [];
        if ([] !== $extra) {
            $endpoint['headers'] = array_merge($endpoint['headers'], $extra);
        }

        return $endpoint;
    }

    /**
     * @param array{name: string, base_url: string, api_key: string, headers: array<string, string>} $endpoint
     */
    public function clientFor(array $endpoint, ?string $model): ClientContract
    {
        // A long-lived worker must notice a rotated key or a moved endpoint, not only new headers.
        $cacheKey = $endpoint['name'].'|'.hash('xxh128', serialize([$endpoint['base_url'], $endpoint['api_key'], $endpoint['headers']]));
        if (isset($this->clients[$cacheKey])) {
            return $this->clients[$cacheKey];
        }

        if (null === $this->httpClient) {
            throw new \LogicException('OpenAI-compatible clients require the shared HTTP client.');
        }
        $factory = \OpenAI::factory()
            // Many gateways require SOME bearer token; send a harmless
            // placeholder when the endpoint is unauthenticated (e.g. a
            // localhost LocalAI) so the client still sets the header.
            ->withApiKey('' !== $endpoint['api_key'] ? $endpoint['api_key'] : 'sk-no-key')
            ->withBaseUri($endpoint['base_url'])
            ->withHttpClient(new \Symfony\Component\HttpClient\Psr18Client($this->httpClient));

        foreach ($endpoint['headers'] as $headerName => $headerValue) {
            $factory = $factory->withHttpHeader($headerName, $headerValue);
        }

        $this->logger->info('OpenAI-compatible: using endpoint', [
            'endpoint' => $endpoint['name'],
            'base_url' => $endpoint['base_url'],
            'model' => $model,
        ]);

        if (\count($this->clients) >= self::MAX_CACHED_CLIENTS) {
            $this->clients = [];
        }

        return $this->clients[$cacheKey] = $factory->make();
    }
}
