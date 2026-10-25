<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * One entry point for everything that stands between a stranger and a new
 * account: the human check ({@see SignupChallenge}) and the limits plus pause
 * switch ({@see SignupGuard}). Controllers depend on this class only.
 */
final readonly class SignupProtection
{
    public function __construct(
        private SignupChallenge $challenge,
        private SignupGuard $guard,
        private SignupAdmission $admission,
    ) {
    }

    public function challengeRequired(): bool
    {
        return $this->challenge->isRequired();
    }

    public function verifyHuman(?string $token, string $action, ?string $clientIp): bool
    {
        return $this->challenge->verify($token, $action, $clientIp);
    }

    /**
     * The limits always apply; the pause does not for a valid invitation code.
     *
     * @throws SignupBlockedException
     */
    public function assertSignupAllowed(?string $clientIp, ?string $invitationCode = null): void
    {
        $this->guard->assertAllowed($clientIp, $this->admission->holdsInvitation($invitationCode));
    }

    /**
     * The human-check token from a JSON body: the current `challengeToken`
     * or the `recaptchaToken` older clients still send.
     *
     * @param array<string, mixed> $payload
     */
    public static function tokenFromPayload(array $payload): string
    {
        foreach (['challengeToken', 'recaptchaToken'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && '' !== $payload[$key]) {
                return $payload[$key];
            }
        }

        return '';
    }
}
