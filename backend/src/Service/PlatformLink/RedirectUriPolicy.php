<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use App\Service\Security\SsrfGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Host + redirect-URI policy for partner-instance registration and link-code
 * issue (C6). Redirects are only accepted when they prefix-match a registered
 * URI on the registered host. The browser never builds the callback URL —
 * PHP does.
 */
final readonly class RedirectUriPolicy
{
    /** Hosts that may use http:// when APP_ENV=dev (local Nextcloud / Outlook). */
    private const DEV_LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', 'nextcloud'];

    public function __construct(
        private SsrfGuard $ssrfGuard,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function isDev(): bool
    {
        return 'dev' === $this->environment || 'test' === $this->environment;
    }

    /**
     * Normalize a registration host: accept `https://files.example.org` or
     * `files.example.org[:port]` and return `host[:port]` (lowercase, no
     * trailing dot). Rejects blocked / private hosts unless they are the
     * documented local-dev exception.
     */
    public function normalizeHost(string $raw): string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            throw new PlatformLinkValidationException('Host is required.');
        }
        if (str_contains($raw, '\\') || str_contains($raw, '@') || str_contains($raw, ' ')) {
            throw new PlatformLinkValidationException('Host is not a valid hostname.');
        }
        if (str_contains($raw, '*')) {
            // Only the seeded Outlook built-in row may carry a wildcard host;
            // a registrable instance with `*` would accept any redirect target.
            throw new PlatformLinkValidationException('Wildcard hosts cannot be registered.');
        }

        if (str_contains($raw, '://')) {
            $parsed = parse_url($raw);
            if (false === $parsed || !isset($parsed['host'])) {
                throw new PlatformLinkValidationException('Host is not a valid URL.');
            }
            if (str_ends_with((string) $parsed['host'], '.')) {
                throw new PlatformLinkValidationException('Host must not have a trailing dot.');
            }
            $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
            $host = $this->canonicalizeHost((string) $parsed['host']);
            $port = isset($parsed['port']) ? (int) $parsed['port'] : null;
            $this->assertAllowedScheme($scheme, $host);
            $this->assertHostAllowed($host);

            return $this->formatHost($host, $port);
        }

        if (str_contains($raw, '/') || str_contains($raw, '?') || str_contains($raw, '#')) {
            throw new PlatformLinkValidationException('Host must not include a path, query or fragment.');
        }

        $host = $raw;
        $port = null;
        if (1 === preg_match('/^(.+):(\d+)$/', $raw, $m)) {
            $host = $m[1];
            $port = (int) $m[2];
        }
        $host = $this->canonicalizeHost($host);
        $this->assertHostAllowed($host);

        return $this->formatHost($host, $port);
    }

    /**
     * @param list<mixed> $uris
     *
     * @return list<string>
     */
    public function normalizeRedirectUris(string $instanceHost, array $uris): array
    {
        if ([] === $uris) {
            throw new PlatformLinkValidationException('At least one redirect URI is required.');
        }

        $out = [];
        foreach ($uris as $uri) {
            if (!\is_string($uri) || '' === trim($uri)) {
                throw new PlatformLinkValidationException('Each redirect URI must be a non-empty string.');
            }
            $normalized = $this->assertRegistrableRedirectUri(trim($uri), $instanceHost);
            $out[] = $normalized;
        }

        return array_values(array_unique($out));
    }

    /**
     * True when $redirectUri prefix-matches one registered entry on $instanceHost.
     *
     * For the Outlook built-in row the instance host is `*`: the candidate is
     * then only checked against the hosts carried by the registered prefixes
     * (`https://localhost`, `https://*.synaplan.com`, …), never against `*`.
     *
     * @param list<mixed> $registered
     */
    public function matchesRegisteredPrefix(string $redirectUri, string $instanceHost, array $registered): bool
    {
        try {
            $candidate = $this->parseRedirect($redirectUri, $instanceHost, allowQuery: false);
        } catch (PlatformLinkValidationException) {
            return false;
        }

        foreach ($registered as $prefix) {
            if (!\is_string($prefix) || '' === $prefix) {
                continue;
            }
            try {
                $allowed = $this->parseRedirect($prefix, $instanceHost, allowQuery: false, allowWildcardHost: true);
            } catch (PlatformLinkValidationException) {
                continue;
            }
            if ($this->prefixMatches($allowed, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function buildCallbackRedirect(string $redirectUri, string $code, string $state): string
    {
        $sep = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri.$sep.'code='.rawurlencode($code).'&state='.rawurlencode($state);
    }

    /**
     * @return array{scheme: string, host: string, port: ?int, path: string}
     */
    private function parseRedirect(string $uri, string $instanceHost, bool $allowQuery, bool $allowWildcardHost = false): array
    {
        if (str_contains($uri, '\\') || str_contains($uri, '@')) {
            throw new PlatformLinkValidationException('Redirect URI must not contain userinfo or backslashes.');
        }
        $lower = strtolower($uri);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($uri, '//')) {
            throw new PlatformLinkValidationException('Redirect URI scheme is not allowed.');
        }

        $parsed = parse_url($uri);
        if (false === $parsed || !isset($parsed['scheme'], $parsed['host'])) {
            throw new PlatformLinkValidationException('Redirect URI is not a valid URL.');
        }
        if (str_ends_with((string) $parsed['host'], '.')) {
            throw new PlatformLinkValidationException('Redirect URI host must not have a trailing dot.');
        }
        if (!$allowQuery && (isset($parsed['query']) || isset($parsed['fragment']))) {
            throw new PlatformLinkValidationException('Redirect URI must not include a query or fragment.');
        }
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new PlatformLinkValidationException('Redirect URI must not contain userinfo.');
        }

        $scheme = strtolower((string) $parsed['scheme']);
        $host = $this->canonicalizeHost((string) $parsed['host']);
        $this->assertAllowedScheme($scheme, $host);

        $expected = $this->splitHostPort($instanceHost);
        $instanceIsWildcard = str_contains($expected['host'], '*');
        if ($instanceIsWildcard) {
            // Built-in row: registered prefixes may name any host under the
            // pattern; candidates are matched against those prefixes in
            // prefixMatches(), so no instance-host comparison happens here.
            if ($allowWildcardHost && !$this->wildcardHostMatches($expected['host'], $host)) {
                throw new PlatformLinkValidationException('Redirect URI host must match the registered instance host.');
            }
        } else {
            if ($host !== $expected['host']) {
                throw new PlatformLinkValidationException('Redirect URI host must match the registered instance host.');
            }
            if (null !== $expected['port'] && (int) ($parsed['port'] ?? 0) !== $expected['port']) {
                throw new PlatformLinkValidationException('Redirect URI port must match the registered instance host.');
            }
        }

        $path = (string) ($parsed['path'] ?? '/');
        if ('' === $path) {
            $path = '/';
        }
        if (str_contains($path, '..') || str_contains(rawurldecode($path), '..')) {
            throw new PlatformLinkValidationException('Redirect URI path must not contain parent segments.');
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => isset($parsed['port']) ? (int) $parsed['port'] : $expected['port'],
            'path' => $path,
        ];
    }

    /**
     * @param array{scheme: string, host: string, port: ?int, path: string} $allowed
     * @param array{scheme: string, host: string, port: ?int, path: string} $candidate
     */
    private function prefixMatches(array $allowed, array $candidate): bool
    {
        if ($allowed['scheme'] !== $candidate['scheme']) {
            return false;
        }
        if (str_contains($allowed['host'], '*')) {
            if (!$this->wildcardHostMatches($allowed['host'], $candidate['host'])) {
                return false;
            }
        } elseif ($allowed['host'] !== $candidate['host']) {
            return false;
        }
        if ($allowed['port'] !== $candidate['port'] && !$this->portFreeLocalPrefix($allowed)) {
            return false;
        }

        $prefix = rtrim($allowed['path'], '/');
        $path = $candidate['path'];
        if ('' === $prefix) {
            // Registered root (`https://host` or `https://host/`): every path
            // on that origin is below it.
            return str_starts_with($path, '/');
        }

        return $path === $prefix || $path === $prefix.'/' || str_starts_with($path, $prefix.'/');
    }

    /**
     * A registered prefix on the developer's own machine without an explicit
     * port (`https://localhost`) accepts any port: the Outlook add-in dev
     * server picks its own (`:3000`), and localhost is not reachable by an
     * attacker. Every other host keeps strict port matching.
     *
     * @param array{scheme: string, host: string, port: ?int, path: string} $allowed
     */
    private function portFreeLocalPrefix(array $allowed): bool
    {
        return null === $allowed['port'] && $this->isLocalDevHost($allowed['host']);
    }

    private function wildcardHostMatches(string $pattern, string $host): bool
    {
        if ('*' === $pattern) {
            return true;
        }
        if (!str_starts_with($pattern, '*.')) {
            return $pattern === $host;
        }
        $suffix = substr($pattern, 1); // ".synaplan.com"

        return str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.') && !str_ends_with($host, '.');
    }

    private function assertRegistrableRedirectUri(string $uri, string $instanceHost): string
    {
        $parsed = $this->parseRedirect($uri, $instanceHost, allowQuery: false);
        $port = $parsed['port'];
        $authority = $parsed['host'];
        if (null !== $port) {
            $authority .= ':'.$port;
        }

        return $parsed['scheme'].'://'.$authority.$parsed['path'];
    }

    private function assertAllowedScheme(string $scheme, string $host): void
    {
        if ('https' === $scheme) {
            return;
        }
        if ('http' === $scheme && $this->isDev() && $this->isLocalDevHost($host)) {
            return;
        }

        throw new PlatformLinkValidationException('Host and redirect URIs must use https.');
    }

    private function assertHostAllowed(string $host): void
    {
        if ('' === $host || str_ends_with($host, '.') || str_contains($host, '..')) {
            throw new PlatformLinkValidationException('Host is not a valid hostname.');
        }
        if (1 === preg_match('/[^\x20-\x7e]/', $host) || str_starts_with($host, 'xn--')) {
            throw new PlatformLinkValidationException('Internationalised host names are not accepted.');
        }
        if ($this->isDev() && $this->isLocalDevHost($host)) {
            return;
        }
        if ($this->ssrfGuard->isBlockedHost($host)) {
            throw new PlatformLinkValidationException('Host is not allowed.');
        }
    }

    public function isLocalDevHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if (\in_array($host, self::DEV_LOCAL_HOSTS, true)) {
            return true;
        }

        return str_ends_with($host, '.localhost');
    }

    private function canonicalizeHost(string $host): string
    {
        $host = strtolower(trim($host, "[] \t"));
        $host = rtrim($host, '.');

        return $host;
    }

    /**
     * @return array{host: string, port: ?int}
     */
    private function splitHostPort(string $instanceHost): array
    {
        if (1 === preg_match('/^(.+):(\d+)$/', $instanceHost, $m)) {
            return ['host' => $this->canonicalizeHost($m[1]), 'port' => (int) $m[2]];
        }

        return ['host' => $this->canonicalizeHost($instanceHost), 'port' => null];
    }

    private function formatHost(string $host, ?int $port): string
    {
        if (null === $port) {
            return $host;
        }

        return $host.':'.$port;
    }
}
