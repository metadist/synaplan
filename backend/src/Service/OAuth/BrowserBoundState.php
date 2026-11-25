<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\User;
use App\Service\Auth\AuthCookieFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A signed OAuth state that only the browser that asked for it can redeem.
 *
 * The state names the account that receives the provider's tokens, and the
 * consent URL can be handed to anyone. So asking for a state also sets a
 * short-lived cookie, and the state carries that cookie's hash. A callback
 * without the matching cookie is someone else's consent and is refused.
 *
 * The cookie is SameSite=Lax in every environment: it has to arrive on the
 * provider's cross-site redirect, which the Strict auth cookie does not.
 *
 * The native app keeps no cookies and runs the consent in the system browser,
 * so a flow started with `native=1` is bound to the account instead: only a
 * caller signed in as the account the state names can redeem it. The browser
 * hands the provider's answer to the app, and the app redeems it with its
 * own token (see NativeConsentReturnSubscriber).
 */
final readonly class BrowserBoundState
{
    /** Request attribute holding the cookie the response must carry. */
    public const COOKIE_ATTRIBUTE = '_oauth_browser_cookie';

    private const COOKIE_PREFIX = 'oauth_flow_';
    private const COOKIE_PATH = '/api/v1';
    private const TTL_SECONDS = 600;
    private const CLAIM = 'browser';
    private const APP_CLAIM = 'app';
    private const NATIVE_PARAMETER = 'native';

    public function __construct(
        private OAuthStateService $state,
        private RequestStack $requests,
        private AuthCookieFactory $cookies,
        private LoggerInterface $logger,
        private TokenStorageInterface $tokens,
    ) {
    }

    /**
     * @param array<string, mixed> $extraData
     */
    public function generateState(string $provider, array $extraData = []): string
    {
        $request = $this->requests->getMainRequest();
        if ('1' === $request?->query->get(self::NATIVE_PARAMETER)) {
            return $this->state->generateState($provider, [self::APP_CLAIM => true] + $extraData);
        }

        $nonce = bin2hex(random_bytes(32));
        $request?->attributes->set(
            self::COOKIE_ATTRIBUTE,
            $this->cookie($provider, $nonce, time() + self::TTL_SECONDS),
        );

        return $this->state->generateState($provider, [self::CLAIM => hash('sha256', $nonce)] + $extraData);
    }

    /**
     * @return array<string, mixed>|null the payload, or null when the state is
     *                                   invalid or was issued to another browser
     *                                   or, for the app, to another account
     */
    public function validateState(string $state, string $provider): ?array
    {
        $payload = $this->state->validateState($state, $provider);
        if (null === $payload) {
            return null;
        }

        if (true === ($payload[self::APP_CLAIM] ?? null)) {
            return $this->redeemedByOwner($payload, $provider) ? $payload : null;
        }

        $request = $this->requests->getMainRequest();
        $nonce = $request?->cookies->get(self::cookieName($provider));
        $expected = $payload[self::CLAIM] ?? null;

        if (!is_string($nonce) || '' === $nonce || !is_string($expected)
            || !hash_equals($expected, hash('sha256', $nonce))
        ) {
            $this->logger->warning('OAuth callback refused: not the browser that started the flow', [
                'provider' => $provider,
                'owner_id' => $payload['owner_id'] ?? null,
                'cookie_present' => is_string($nonce) && '' !== $nonce,
            ]);

            return null;
        }

        $request->attributes->set(self::COOKIE_ATTRIBUTE, $this->cookie($provider, '', 1));

        return $payload;
    }

    /**
     * The payload of a signed, unexpired state from any browser. For choosing
     * where to send the person afterwards, never for redeeming the state.
     *
     * @return array<string, mixed>|null
     */
    public function peek(string $state, string $provider): ?array
    {
        return $this->state->validateState($state, $provider);
    }

    /** True when the state was issued to the native app rather than to a browser. */
    public function isAppBound(string $state): bool
    {
        return true === ($this->state->readState($state)[self::APP_CLAIM] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function redeemedByOwner(array $payload, string $provider): bool
    {
        $user = $this->tokens->getToken()?->getUser();
        $ownerId = $payload['owner_id'] ?? null;

        if ($user instanceof User && is_numeric($ownerId) && $user->getId() === (int) $ownerId) {
            return true;
        }

        $this->logger->warning('OAuth callback refused: not the account that started the flow in the app', [
            'provider' => $provider,
            'owner_id' => $ownerId,
            'signed_in' => $user instanceof User,
        ]);

        return false;
    }

    private function cookie(string $provider, string $value, int $expire): Cookie
    {
        return $this->cookies->create(self::cookieName($provider), $value, $expire)
            ->withPath(self::COOKIE_PATH)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    private static function cookieName(string $provider): string
    {
        return self::COOKIE_PREFIX.preg_replace('/[^a-z0-9]/', '', strtolower($provider));
    }
}
