<?php

declare(strict_types=1);

namespace App\Module\Probe;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Symfony HttpClient implementation of the sidecar probe.
 *
 * Mirrors the historic `ConfigController::checkServiceHealth()` semantics
 * (2 s timeout, optional basic auth, 401/403 and 5xx are "down") so the
 * feature-status page keeps reporting the same health for the same sidecar.
 *
 * Results are memoised per URL for the lifetime of one request: the Feature
 * status page asks every module for its status twice (once for the historic
 * feature rows, once for the module rows), and an unreachable host costs the
 * full timeout on every probe — twice 4 s per absent sidecar added up to a
 * page that timed out. `ResetInterface` clears the memo between requests in
 * the FrankenPHP worker, so a sidecar that comes up is seen on the next load.
 */
final class HttpSidecarHealthProbe implements SidecarHealthProbeInterface, ResetInterface
{
    private const TIMEOUT_SECONDS = 2.0;

    /** @var array<string, bool> */
    private array $reachable = [];

    /** @var array<string, string|null> */
    private array $text = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function isReachable(string $url, ?string $httpUser = null, ?string $httpPass = null): bool
    {
        $key = $this->memoKey($url, $httpUser);
        if (\array_key_exists($key, $this->reachable)) {
            return $this->reachable[$key];
        }

        try {
            $status = $this->httpClient->request('GET', $url, $this->options($httpUser, $httpPass))->getStatusCode();
        } catch (\Throwable) {
            return $this->reachable[$key] = false;
        }

        if (401 === $status || 403 === $status) {
            return $this->reachable[$key] = false;
        }

        return $this->reachable[$key] = $status >= 200 && $status < 500;
    }

    public function fetchText(string $url, ?string $httpUser = null, ?string $httpPass = null): ?string
    {
        $key = $this->memoKey($url, $httpUser);
        if (\array_key_exists($key, $this->text)) {
            return $this->text[$key];
        }

        try {
            $response = $this->httpClient->request('GET', $url, $this->options($httpUser, $httpPass));
            if ($response->getStatusCode() >= 300) {
                return $this->text[$key] = null;
            }

            return $this->text[$key] = $response->getContent(false);
        } catch (\Throwable) {
            return $this->text[$key] = null;
        }
    }

    public function reset(): void
    {
        $this->reachable = [];
        $this->text = [];
    }

    private function memoKey(string $url, ?string $httpUser): string
    {
        return $url.'|'.($httpUser ?? '');
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
