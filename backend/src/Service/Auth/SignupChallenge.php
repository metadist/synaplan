<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * The human check in front of account creation and sign-in.
 *
 * Picks the configured provider: Cloudflare Turnstile when
 * `TURNSTILE_SECRET_KEY` is set, otherwise reCAPTCHA v3 when enabled,
 * otherwise none (development, self-hosting without keys). The browser side
 * mirrors this order in `useSignupChallenge`.
 */
final readonly class SignupChallenge
{
    public const PROVIDER_TURNSTILE = 'turnstile';
    public const PROVIDER_RECAPTCHA = 'recaptcha';

    public function __construct(
        private TurnstileVerifier $turnstile,
        private RecaptchaService $recaptcha,
    ) {
    }

    public function provider(): ?string
    {
        if ($this->turnstile->isConfigured()) {
            return self::PROVIDER_TURNSTILE;
        }
        if ($this->recaptcha->isEnabled()) {
            return self::PROVIDER_RECAPTCHA;
        }

        return null;
    }

    public function isRequired(): bool
    {
        return null !== $this->provider();
    }

    /**
     * True when the token proves a human for this action — or when no
     * provider is configured, so an install without keys keeps working.
     */
    public function verify(?string $token, string $action, ?string $remoteIp): bool
    {
        return match ($this->provider()) {
            self::PROVIDER_TURNSTILE => $this->turnstile->verify((string) $token, $remoteIp),
            self::PROVIDER_RECAPTCHA => $this->recaptcha->verify((string) $token, $action, $remoteIp),
            default => true,
        };
    }
}
