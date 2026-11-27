<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Service\OAuth\BrowserBoundState;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ties a sign-in at an identity provider to the browser that started it.
 *
 * The provider's answer is a link anyone can be sent. Without this, a person
 * who opens someone else's answer is signed in to that someone's account and
 * may then store their own data in it. Starting a sign-in sets a short-lived
 * cookie, and the state carries the cookie's hash; an answer that arrives
 * without the matching cookie was started elsewhere and signs nobody in.
 *
 * A second start in the same browser keeps the cookie it finds, so two tabs
 * or a repeated click both finish. The cookie is not spent for that reason;
 * it runs out ten minutes after the last start.
 *
 * The cookie is SameSite=Lax so it arrives on the provider's redirect. Apple
 * answers with a cross-site POST, which Lax does not accompany, so its cookie
 * is SameSite=None and therefore Secure. Apple only answers to https anyway.
 */
final readonly class LoginBrowserBinding
{
    private const COOKIE_PREFIX = 'login_flow_';
    private const COOKIE_PATH = '/api/v1/auth';
    private const TTL_SECONDS = 600;
    private const NONCE_PATTERN = '/^[a-f0-9]{64}$/';

    /** Providers whose answer is a cross-site POST. */
    private const ANSWERS_BY_POST = ['apple'];

    public function __construct(
        private AuthCookieFactory $cookies,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Sets the cookie on the response to this request.
     *
     * @return string the value the state has to carry
     */
    public function start(Request $request, string $provider): string
    {
        $held = $request->cookies->get(self::cookieName($provider));
        $nonce = is_string($held) && 1 === preg_match(self::NONCE_PATTERN, $held) ? $held : bin2hex(random_bytes(32));
        $request->attributes->set(
            BrowserBoundState::COOKIE_ATTRIBUTE,
            $this->cookie($provider, $nonce, time() + self::TTL_SECONDS),
        );

        return hash('sha256', $nonce);
    }

    /** True when this browser holds the cookie the state was issued with. */
    public function confirm(Request $request, string $provider, mixed $expected): bool
    {
        $nonce = $request->cookies->get(self::cookieName($provider));

        if (!is_string($nonce) || '' === $nonce || !is_string($expected) || '' === $expected
            || !hash_equals($expected, hash('sha256', $nonce))
        ) {
            $this->logger->warning('Sign-in refused: not the browser that started it', [
                'provider' => $provider,
                'cookie_present' => is_string($nonce) && '' !== $nonce,
                'state_bound' => is_string($expected) && '' !== $expected,
            ]);

            return false;
        }

        return true;
    }

    private function cookie(string $provider, string $value, int $expire): Cookie
    {
        $cookie = $this->cookies->create(self::cookieName($provider), $value, $expire)
            ->withPath(self::COOKIE_PATH)
            ->withSameSite(Cookie::SAMESITE_LAX);

        if (in_array($provider, self::ANSWERS_BY_POST, true)) {
            return $cookie->withSameSite(Cookie::SAMESITE_NONE)->withSecure(true);
        }

        return $cookie;
    }

    private static function cookieName(string $provider): string
    {
        return self::COOKIE_PREFIX.preg_replace('/[^a-z0-9]/', '', strtolower($provider));
    }
}
