<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One unsigned ping when a delivery address is saved (03 §2). The app
 * answers 401 to an unsigned body; any HTTP answer at all means Synaplan
 * can reach the cloud, a connection or TLS error means it cannot.
 * Self-signed TLS is never accepted.
 */
final readonly class DeliveryProbe
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    public function reachable(string $url): bool
    {
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => (string) json_encode(['type' => 'ping']),
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $response->getStatusCode();

            return true;
        } catch (ExceptionInterface) {
            return false;
        }
    }
}
