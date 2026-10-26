<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Service\Security\SsrfGuard;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One unsigned ping when a delivery address is saved (03 §2). The app
 * answers 401 to an unsigned body; any HTTP answer at all means Synaplan
 * can reach the cloud, a connection or TLS error means it cannot.
 * Self-signed TLS is never accepted.
 *
 * The address it connects to is pinned from the answers the origin policy
 * just validated, so a hostname cannot hand back a different, forbidden
 * address for the probe (or resolve to nothing usable — that fails
 * closed). Development hosts from the shared dev list keep their loopback
 * answers; everywhere else loopback and link-local answers are dropped.
 */
final readonly class DeliveryProbe
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $http,
        private SsrfGuard $ssrfGuard,
        private RedirectUriPolicy $redirects,
    ) {
    }

    public function reachable(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ('' === $host) {
            return false;
        }
        $options = [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) json_encode(['type' => 'ping']),
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
        ];
        if (false === filter_var($host, \FILTER_VALIDATE_IP)) {
            $pinned = $this->pinnedAddress($host);
            if (null === $pinned) {
                return false;
            }
            $options['resolve'] = [$host => $pinned];
        }

        try {
            $response = $this->http->request('POST', $url, $options);
            $response->getStatusCode();

            return true;
        } catch (ExceptionInterface) {
            return false;
        }
    }

    /**
     * The one address the probe may connect to for this hostname, from the
     * public and operator-network answers. Null when none survives.
     */
    private function pinnedAddress(string $host): ?string
    {
        $devLocal = $this->redirects->isDev() && $this->redirects->isLocalDevHost($host);
        foreach ($this->ssrfGuard->pinnedIps($host, true) as $ip) {
            if (!$devLocal && DeliveryOriginPolicy::ipIsLoopbackOrLinkLocal($ip)) {
                continue;
            }

            return $ip;
        }

        return null;
    }
}
