<?php

declare(strict_types=1);

namespace App\AI\Provider\OpenAI;

use App\Service\Egress\HttpBytes;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The JSON POST the gpt-image request has always used: a bearer key and a
 * time limit, now through the shared HTTP client. The class name stays so
 * the call sites do not move. Tests replace the client, so no request leaves
 * the process.
 */
final readonly class OpenAICurlTransport
{
    private HttpBytes $bytes;

    public function __construct(HttpClientInterface $http)
    {
        $this->bytes = new HttpBytes($http);
    }

    /**
     * @return array{0: string|bool, 1: int, 2: string} the response body, the HTTP status and the error
     */
    public function postJson(string $url, ?string $key, string|false $body, int $timeout): array
    {
        [$responseBody, $httpCode, $error] = $this->bytes->postJson($url, $key, false === $body ? '' : $body, $timeout);

        return [$responseBody, $httpCode, $error];
    }
}
