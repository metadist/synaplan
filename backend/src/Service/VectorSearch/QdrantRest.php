<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One Qdrant REST call. Every request carries the API key when one is set,
 * and carries nothing extra when it is not.
 */
final readonly class QdrantRest
{
    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private QdrantApiKey $apiKey,
    ) {
    }

    public function apiKey(): QdrantApiKey
    {
        return $this->apiKey;
    }

    /**
     * Send a request and return the decoded JSON body.
     *
     * Transport errors, non-2xx responses and non-JSON bodies become
     * {@see \RuntimeException}. A 401 or 403 names the API key and does not
     * repeat the response body, so a server that echoes the key cannot put
     * it in the message.
     *
     * `$alwaysJsonContentType` is false for callers that today send no
     * headers on a body-less request. An empty key then leaves that request
     * unchanged.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<array-key, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, bool $alwaysJsonContentType = true): array
    {
        $exchange = $this->exchange($method, $path, $body, null, $alwaysJsonContentType);
        $status = $exchange['status'];
        if ($status < 200 || $status >= 300) {
            $auth = $this->apiKey->failureMessage($status, $exchange['body']);
            if (null !== $auth) {
                throw new \RuntimeException($auth);
            }

            throw new \RuntimeException("Qdrant request failed [{$method} {$path}]: HTTP {$status} — {$exchange['body']}");
        }

        if ('' === $exchange['body']) {
            return [];
        }

        try {
            $decoded = json_decode($exchange['body'], true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Qdrant returned a non-JSON response body [{$method} {$path}]: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException("Qdrant returned a non-JSON response body [{$method} {$path}]");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    public function exchange(string $method, string $path, ?array $body = null, ?float $timeout = null, bool $alwaysJsonContentType = true): array
    {
        $headers = [];
        if ($alwaysJsonContentType || null !== $body) {
            $headers['Content-Type'] = 'application/json';
        }
        $headers = $this->apiKey->apply($headers);

        $options = [];
        if ([] !== $headers) {
            $options['headers'] = $headers;
        }
        if (null !== $body) {
            $options['json'] = $body;
        }
        if (null !== $timeout) {
            $options['timeout'] = $timeout;
        }

        $response = $this->http->request($method, $this->url($path), $options);

        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = $response->getHeaders(false);

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(false),
            'headers' => $responseHeaders,
        ];
    }

    /**
     * `/healthz` answers without an API key. `/collections` is what tells a
     * missing or wrong key from an open Qdrant.
     */
    public function probe(float $timeoutSeconds, LoggerInterface $logger): string
    {
        try {
            // Idle timeout only: `max_duration` surfaces as a plain transport
            // error and would be cached as a full outage.
            $health = $this->exchange('GET', '/healthz', timeout: $timeoutSeconds);
            $healthOutcome = self::outcome($health['status']);
            if (QdrantApiKey::PROBE_OK !== $healthOutcome) {
                return $healthOutcome;
            }

            $access = $this->exchange('GET', '/collections', timeout: $timeoutSeconds);

            return self::outcome($access['status']);
        } catch (TimeoutExceptionInterface $e) {
            $logger->warning('Qdrant health check timed out', ['error' => $e->getMessage()]);

            return QdrantApiKey::PROBE_TIMEOUT;
        } catch (\Throwable $e) {
            $logger->warning('Qdrant health check failed', ['error' => $e->getMessage()]);

            return QdrantApiKey::PROBE_DOWN;
        }
    }

    private static function outcome(int $status): string
    {
        return match ($status) {
            200 => QdrantApiKey::PROBE_OK,
            401 => QdrantApiKey::PROBE_UNAUTHORIZED,
            403 => QdrantApiKey::PROBE_FORBIDDEN,
            default => QdrantApiKey::PROBE_DOWN,
        };
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
