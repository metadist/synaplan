<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One unsigned ping when a delivery address is saved (03 §2). The app
 * answers 401 to an unsigned body; any HTTP answer at all means Synaplan
 * can reach the cloud, a connection or TLS error means it cannot.
 * Self-signed TLS is never accepted.
 *
 * The connection goes to the address {@see DeliveryOriginPolicy::pinnedAddress()}
 * already accepted for this cloud. The probe does not resolve the name
 * again, so a name that checked out as public cannot point the probe at a
 * private address a moment later.
 */
final readonly class DeliveryProbe
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $http,
        private DeliveryOriginPolicy $origins,
    ) {
    }

    public function reachable(PlatformInstance $instance, string $url): bool
    {
        $address = $this->origins->pinnedAddress($instance, $url);
        if (null === $address) {
            return false;
        }
        $host = strtolower(trim((string) parse_url($url, \PHP_URL_HOST), '[]'));
        $options = [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) json_encode(['type' => 'ping']),
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
        ];
        if ('' !== $host && $host !== $address) {
            $options['resolve'] = [$host => $address];
        }

        try {
            $response = $this->http->request('POST', $url, $options);
            $response->getStatusCode();

            return true;
        } catch (ExceptionInterface) {
            return false;
        }
    }
}
