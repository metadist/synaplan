<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Synaplan;

use Plugin\Synafastbill\Api\Exception\FastBillTransportException;
use Plugin\Synafastbill\Api\Http\HttpClientInterface;
use Plugin\Synafastbill\Api\Http\HttpResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClient;

/**
 * Posts one FastBill body through Synaplan's shared HTTP client.
 */
final class SymfonyHttpTransport implements HttpClientInterface
{
    public function __construct(
        private readonly SymfonyHttpClient $http,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ]);

            return new HttpResponse($response->getStatusCode(), $response->getContent(false));
        } catch (TransportExceptionInterface $e) {
            throw new FastBillTransportException('FastBill request failed: '.$e->getMessage(), 0, $e);
        }
    }
}
