<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Builds the HttpOnly authentication cookies (access, refresh, impersonation
 * stash) with a consistent security posture.
 *
 * The `Secure` attribute follows the scheme of the deployment's public URL
 * rather than `APP_ENV`. Browsers silently drop a `Secure` cookie sent over
 * plain HTTP, so a production deployment that is legitimately reachable only
 * over HTTP — a LAN appliance such as umbrelOS, which serves every app from
 * `http://<device>.local:<port>` and deliberately offers no local TLS — used to
 * authenticate successfully and then lose the session on the very next request.
 *
 * `AUTH_COOKIE_SECURE` overrides the detection for deployments whose TLS is
 * terminated somewhere the application cannot see in `APP_URL`.
 *
 * When the public URL is unknown, the environment decides and production fails
 * secure: an unconfigured `APP_URL` must never silently downgrade a real
 * internet-facing deployment to non-`Secure` cookies.
 */
final readonly class AuthCookieFactory
{
    /**
     * @param string $appEnv      Kernel environment, decides the SameSite policy
     * @param string $appUrl      Public base URL of the deployment
     * @param string $forceSecure Raw `AUTH_COOKIE_SECURE` value; empty means "not configured"
     */
    public function __construct(
        private string $appEnv,
        private string $appUrl,
        private string $forceSecure = '',
    ) {
    }

    public function create(string $name, string $value, int $expire): Cookie
    {
        $isProduction = 'prod' === $this->appEnv;

        return Cookie::create($name)
            ->withValue($value)
            ->withExpires($expire)
            ->withPath('/')
            ->withSecure($this->isSecure())
            ->withHttpOnly(true)
            ->withSameSite($isProduction ? Cookie::SAMESITE_STRICT : Cookie::SAMESITE_LAX);
    }

    private function isSecure(): bool
    {
        // FILTER_VALIDATE_BOOL maps an empty string to false rather than to
        // null, so "not configured" has to be recognised before filtering.
        $forceSecure = trim($this->forceSecure);
        if ('' !== $forceSecure) {
            $configured = filter_var($forceSecure, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if (null !== $configured) {
                return $configured;
            }
        }

        $appUrl = trim($this->appUrl);
        if ('' === $appUrl) {
            return 'prod' === $this->appEnv;
        }

        return str_starts_with(strtolower($appUrl), 'https://');
    }
}
