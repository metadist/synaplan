<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Service\Egress\EgressCall;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Requests a URL a person typed and follows its redirects one at a time.
 *
 * The HTTP client's own redirect following checks only the first address. A
 * public page can answer `302 Location: http://10.0.0.5/` and the client
 * would fetch that. Here every address, the first and each redirect target,
 * passes {@see SsrfGuard} before a request is sent to it, and the connection
 * is pinned to the address the guard accepted.
 */
final readonly class GuardedHttpFetch
{
    /** Headers that carry a credential for the first host only. */
    private const CREDENTIAL_HEADERS = ['authorization', 'cookie', 'proxy-authorization'];

    public function __construct(
        private HttpClientInterface $http,
        private SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * The first response that is not a redirect. `max_redirects` in $options
     * is ignored; $maxRedirects is the limit.
     *
     * @param array<string, mixed> $options
     *
     * @throws BlockedAddressException   when the URL or a redirect target is refused
     * @throws TooManyRedirectsException when the limit is passed
     */
    public function request(string $method, string $url, array $options, int $maxRedirects): ResponseInterface
    {
        unset($options['max_redirects'], $options['resolve']);

        for ($hop = 0;; ++$hop) {
            if ($this->ssrfGuard->isBlockedUrl($url)) {
                throw new BlockedAddressException();
            }

            $response = $this->http->request($method, $url, EgressCall::typed(
                $this->ssrfGuard->pinnedResolveOption($url) + ['max_redirects' => 0] + $options,
            ));

            $status = $response->getStatusCode();
            $location = $status >= 300 && $status < 400
                ? trim($response->getHeaders(false)['location'][0] ?? '')
                : '';
            if ('' === $location) {
                return $response;
            }

            $response->cancel();
            if ($hop >= $maxRedirects) {
                throw new TooManyRedirectsException($maxRedirects);
            }

            $next = self::absoluteUrl($url, $location);
            if (!self::sameOrigin($url, $next)) {
                $options = self::withoutCredentials($options);
            }
            if (303 === $status || (in_array($status, [301, 302], true) && 'POST' === strtoupper($method))) {
                $method = 'GET';
                unset($options['body'], $options['json']);
            }
            $url = $next;
        }
    }

    /**
     * The redirect target as an absolute URL. A target that names its own
     * scheme is returned unchanged, so the guard sees `file:` or `gopher:`
     * and refuses it.
     */
    public static function absoluteUrl(string $base, string $location): string
    {
        if (1 === preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $origin = $scheme.'://'.$host.$port;

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parsed['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.$dir.$location;
    }

    private static function sameOrigin(string $a, string $b): bool
    {
        return self::origin($a) === self::origin($b);
    }

    private static function origin(string $url): string
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return '';
        }
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $port = $parsed['port'] ?? ('https' === $scheme ? 443 : 80);

        return $scheme.'://'.strtolower((string) ($parsed['host'] ?? '')).':'.$port;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function withoutCredentials(array $options): array
    {
        unset($options['auth_basic'], $options['auth_bearer']);
        if (is_array($options['headers'] ?? null)) {
            foreach (array_keys($options['headers']) as $name) {
                if (is_string($name) && in_array(strtolower($name), self::CREDENTIAL_HEADERS, true)) {
                    unset($options['headers'][$name]);
                }
            }
        }

        return $options;
    }
}
