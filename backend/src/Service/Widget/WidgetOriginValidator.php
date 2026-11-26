<?php

declare(strict_types=1);

namespace App\Service\Widget;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validates that a public widget request originates from a host on the
 * widget's configured domain allowlist.
 *
 * Mirrors the semantics enforced on the widget chat endpoints
 * (WidgetPublicController::ensureDomainAllowed):
 *
 *   - an EMPTY allowlist allows every domain — restricting embedding is
 *     opt-in, matching the legacy widget.js loader and the UI copy
 *     ("Leave empty to allow all domains");
 *   - the requesting host is the `Origin` header when the browser sent one.
 *     A page cannot set that header, so a foreign site cannot name an allowed
 *     host instead. Only without `Origin` (a same-origin request, a script
 *     tag) the host comes from `X-Widget-Host`, which the embed script sets,
 *     and then from `Referer`;
 *   - allowlist entries support `*.example.com` wildcards (the domain itself
 *     and every subdomain, not a name that merely ends in the same letters)
 *     and optional `:port` suffixes.
 *
 * This is defence-in-depth, not a hard auth boundary: a scripted client can
 * forge any of these headers. The hard-to-guess session id plus per-IP rate
 * limiting carry the real weight; the origin check stops casual cross-site
 * reuse from browsers, where Origin cannot be forged.
 */
final readonly class WidgetOriginValidator
{
    /**
     * @param array<string> $allowedDomains
     */
    public function isRequestAllowed(Request $request, array $allowedDomains): bool
    {
        if ([] === $allowedDomains) {
            return true;
        }

        $host = $this->extractHostFromRequest($request);
        if (null === $host) {
            return false;
        }

        return $this->isHostAllowed($host, $allowedDomains);
    }

    public function extractHostFromRequest(Request $request): ?string
    {
        $origin = $request->headers->get('origin');
        if (null !== $origin && '' !== trim($origin)) {
            // An opaque origin ("null": sandboxed frame, file page) names no
            // host. It must not fall through to a header the page controls.
            return $this->hostOfUrl($origin);
        }

        $headerHost = $request->headers->get('x-widget-host');
        if (null !== $headerHost && '' !== $headerHost) {
            $normalized = $this->normalizeHost($headerHost);
            if (null !== $normalized) {
                return $normalized;
            }
        }

        $referer = $request->headers->get('referer');

        return null !== $referer && '' !== $referer ? $this->hostOfUrl($referer) : null;
    }

    private function hostOfUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host']) || '' === $parts['host']) {
            return null;
        }

        $host = strtolower($parts['host']);

        return isset($parts['port']) ? $host.':'.$parts['port'] : $host;
    }

    /**
     * Check whether the host matches the allowlist (wildcards + optional ports).
     *
     * @param array<mixed> $allowedDomains
     */
    public function isHostAllowed(string $host, array $allowedDomains): bool
    {
        $host = strtolower($host);
        $hostWithoutPort = $host;
        $hostPort = null;

        if (str_contains($host, ':')) {
            [$hostWithoutPort, $hostPort] = explode(':', $host, 2);
        }

        foreach ($allowedDomains as $domain) {
            if (!is_string($domain) || '' === trim($domain)) {
                continue;
            }

            $domain = strtolower(trim($domain));
            $domainHost = $domain;
            $domainPort = null;

            if (str_contains($domain, ':')) {
                [$domainHost, $domainPort] = explode(':', $domain, 2);
            }

            if (null !== $domainPort && $hostPort !== $domainPort) {
                continue;
            }

            if ($domainHost === $host || $domainHost === $hostWithoutPort) {
                return true;
            }

            if (str_starts_with($domainHost, '*.')) {
                $suffix = substr($domainHost, 2);
                if ('' !== $suffix && ($hostWithoutPort === $suffix || str_ends_with($hostWithoutPort, '.'.$suffix))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeHost(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        if ('' === $normalized) {
            return null;
        }

        $normalized = preg_replace('#^https?://#', '', $normalized);
        if (null === $normalized) {
            return null;
        }

        $normalized = preg_replace('#^//#', '', $normalized);
        if (null === $normalized) {
            return null;
        }

        $parts = preg_split('~[/?#]~', $normalized);
        $normalized = false !== $parts ? ($parts[0] ?? '') : '';

        return '' !== $normalized ? $normalized : null;
    }
}
