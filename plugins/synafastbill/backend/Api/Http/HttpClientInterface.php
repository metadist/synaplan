<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Api\Http;

/**
 * Transport seam for the FastBill client.
 *
 * Production uses {@see \Plugin\Synafastbill\Synaplan\SymfonyHttpTransport},
 * which posts through Synaplan's shared HTTP client. Tests inject a fake so
 * the command pipeline runs without any network access.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers): HttpResponse;
}
