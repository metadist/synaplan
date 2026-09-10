<?php

declare(strict_types=1);

namespace App\Module\Probe;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony HttpClient implementation of the sidecar probe.
 *
 * Mirrors the historic `ConfigController::checkServiceHealth()` semantics
 * (2 s timeout, optional basic auth, 401/403 and 5xx are "down") so the
 * feature-status page keeps reporting the same health for the same sidecar.
 */
final class HttpSidecarHealthProbe implements SidecarHealthProbeInterface
{
    private const TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function isReachable(string $url, ?string $httpUser = null, ?string $httpPass = null): bool
    {
        try {
            $status = $this->httpClient->request('GET', $url, $this->options($httpUser, $httpPass))->getStatusCode();
        } catch (\Throwable) {
            return false;
        }

        if (401 === $status || 403 === $status) {
            return false;
        }

        return $status >= 200 && $status < 500;
    }

    public function fetchText(string $url, ?string $httpUser = null, ?string $httpPass = null): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, $this->options($httpUser, $httpPass));
            if ($response->getStatusCode() >= 300) {
                return null;
            }

            return $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(?string $httpUser, ?string $httpPass): array
    {
        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
        ];

        if (null !== $httpUser && '' !== $httpUser) {
            $options['auth_basic'] = [$httpUser, (string) $httpPass];
        }

        return $options;
    }
}
