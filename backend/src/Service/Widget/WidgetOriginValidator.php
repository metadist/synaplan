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
 *   - an EMPTY allowlist blocks — a widget without configured domains is
 *     not embeddable, so no anonymous request on its behalf is legitimate;
 *   - the requesting host is taken from `X-Widget-Host` (set by the embed
 *     script), falling back to the `Origin` / `Referer` headers;
 *   - allowlist entries support `*.example.com` wildcards and optional
 *     `:port` suffixes.
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
            return false;
        }

        $host = $this->extractHostFromRequest($request);
        if (null === $host) {
            return false;
        }

        return $this->isHostAllowed($host, $allowedDomains);
    }

    public function extractHostFromRequest(Request $request): ?string
    {
        $headerHost = $request->headers->get('x-widget-host');
        if (null !== $headerHost && '' !== $headerHost) {
            $normalized = $this->normalizeHost($headerHost);
            if (null !== $normalized) {
                return $normalized;
            }
        }

        foreach (['origin', 'referer'] as $header) {
            $value = $request->headers->get($header);
            if (null === $value || '' === $value) {
                continue;
            }

            $parts = parse_url($value);
            if (false === $parts || !isset($parts['host'])) {
                continue;
            }

            $host = strtolower($parts['host']);
            if (isset($parts['port'])) {
                $host .= ':'.$parts['port'];
            }

            if ('' !== $host) {
                return $host;
            }
        }

        return null;
    }

    /**
     * Check whether the host matches the allowlist (wildcards + optional ports).
     *
     * @param array<string> $allowedDomains
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
            if ('' === $domain) {
                continue;
            }

            $domain = strtolower($domain);
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

            if (str_starts_with($domainHost, '*.') && $hostWithoutPort !== $domainHost) {
                $suffix = substr($domainHost, 2);
                if ('' !== $suffix && str_ends_with($hostWithoutPort, $suffix)) {
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
