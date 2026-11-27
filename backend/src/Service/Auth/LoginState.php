<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Service\OAuth\OAuthStateService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The signed state of a social sign-in (Google, GitHub, Apple).
 *
 * A sign-in started in a browser can only be finished in that browser
 * ({@see LoginBrowserBinding}). One started by the native app with `native=1`
 * ends in a handoff to the app instead of a web session and is not bound here.
 */
final readonly class LoginState
{
    /** `error_code` of a sign-in whose state is expired, forged or from another browser. */
    public const RESTART_CODE = 'login_restart';

    private const NATIVE_PARAMETER = 'native';
    private const NATIVE_CLAIM = 'native';
    private const BROWSER_CLAIM = 'browser';

    public function __construct(
        private OAuthStateService $state,
        private LoginBrowserBinding $browser,
    ) {
    }

    /**
     * @param array<string, mixed> $extraData
     */
    public function issue(Request $request, string $provider, array $extraData = []): string
    {
        unset($extraData[self::NATIVE_CLAIM], $extraData[self::BROWSER_CLAIM]);

        $binding = $request->query->getBoolean(self::NATIVE_PARAMETER)
            ? [self::NATIVE_CLAIM => true]
            : [self::BROWSER_CLAIM => $this->browser->start($request, $provider)];

        return $this->state->generateState($provider, $binding + $extraData);
    }

    /**
     * @return array<string, mixed>|null the payload, or null when the state is
     *                                   invalid or was issued to another browser
     */
    public function redeem(Request $request, string $state, string $provider): ?array
    {
        $payload = $this->state->validateState($state, $provider);
        if (null === $payload) {
            return null;
        }

        if (self::isNative($payload)) {
            return $payload;
        }

        return $this->browser->confirm($request, $provider, $payload[self::BROWSER_CLAIM] ?? null) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function isNative(array $payload): bool
    {
        return true === ($payload[self::NATIVE_CLAIM] ?? null);
    }
}
