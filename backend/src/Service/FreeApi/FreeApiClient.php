<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The three `/v1/free/*` routes of the Synaplan Demo server.
 *
 * Every call goes to FREEAPI_BASE_URL only, follows no redirects, is capped at
 * 15 seconds and reads at most 64 KiB of answer. Refusals come back as
 * {@see FreeApiException} carrying the server's `freeapi_*` code.
 */
final readonly class FreeApiClient
{
    private const TIMEOUT_SECONDS = 15;
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(
        private HttpClientInterface $httpClient,
        private FreeApiConfig $config,
    ) {
    }

    /**
     * @param array<string, string|int> $payload the issuance body of contract/v1/issuance.json
     *
     * @return array<string, mixed>
     */
    public function issueKey(array $payload): array
    {
        return $this->call('POST', '/v1/free/keys', null, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $key): array
    {
        return $this->call('GET', '/v1/free/status', $key);
    }

    public function revokeKey(string $key): void
    {
        $this->call('DELETE', '/v1/free/keys/self', $key);
    }

    /**
     * @param array<string, string|int>|null $json
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?string $key, ?array $json = null): array
    {
        $baseUrl = $this->config->baseUrl();
        if (null === $baseUrl) {
            throw FreeApiException::notConfigured();
        }

        $headers = [
            'Accept' => 'application/json',
            'X-Synaplan-Version' => $this->config->appVersion(),
            'X-Synaplan-Protocol' => (string) FreeApiConfig::PROTOCOL,
        ];
        if (null !== $key) {
            $headers['Authorization'] = 'Bearer '.$key;
        }
        $options = ['headers' => $headers, 'timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::TIMEOUT_SECONDS, 'max_redirects' => 0];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $baseUrl.$path, $options);
            $status = $response->getStatusCode();
            $body = $this->readBody($response);
        } catch (ExceptionInterface $e) {
            throw FreeApiException::unreachable($e);
        }

        $decoded = json_decode($body, true);
        if ($status >= 200 && $status < 300) {
            if (!\is_array($decoded)) {
                throw FreeApiException::invalidResponse('no JSON object', $status);
            }

            return $decoded;
        }

        throw $this->refusal($status, \is_array($decoded) ? $decoded : [], $response);
    }

    private function readBody(ResponseInterface $response): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (\strlen($body) > self::MAX_RESPONSE_BYTES) {
                $response->cancel();
                throw FreeApiException::invalidResponse('answer too large', $response->getStatusCode());
            }
        }

        return $body;
    }

    /**
     * @param array<mixed> $decoded
     */
    private function refusal(int $status, array $decoded, ResponseInterface $response): FreeApiException
    {
        $error = \is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code = \is_string($error['code'] ?? null) ? $error['code'] : '';
        if (!str_starts_with($code, 'freeapi_')) {
            $code = 401 === $status ? FreeApiException::INVALID_KEY : FreeApiException::INVALID_RESPONSE;
        }
        $message = \is_string($error['message'] ?? null) && '' !== $error['message']
            ? substr($error['message'], 0, 300)
            : sprintf('The Synaplan Demo server refused the request (HTTP %d).', $status);

        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;

        return new FreeApiException(
            $message,
            $code,
            $status,
            null !== $retryAfter && ctype_digit($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
