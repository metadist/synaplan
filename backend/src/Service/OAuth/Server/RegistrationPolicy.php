<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

/**
 * Who may register a cloud as an OAuth2 client (RFC 7591), set by
 * PLATFORM_LINKS_REGISTRATION:
 *
 * - pending (default): anyone may register; the client waits until a
 *   Synaplan administrator approves it.
 * - open: anyone may register and is active at once. Registering grants no
 *   data; every person still consents on their own.
 * - token: only with the initial access token in
 *   PLATFORM_LINKS_REGISTRATION_TOKEN (RFC 7591 section 3).
 *
 * In every mode a registration that presents the initial access token is
 * active at once. That token approves one client and then is spent.
 */
final readonly class RegistrationPolicy
{
    public const PENDING = 'pending';
    public const OPEN = 'open';
    public const TOKEN = 'token';

    public const MIN_TOKEN_LENGTH = 32;

    public string $mode;

    public function __construct(string $mode, private string $initialAccessToken)
    {
        $mode = strtolower(trim($mode));
        $this->mode = in_array($mode, [self::OPEN, self::TOKEN], true) ? $mode : self::PENDING;
        if (self::TOKEN === $this->mode && strlen($this->initialAccessToken) < self::MIN_TOKEN_LENGTH) {
            throw new \InvalidArgumentException('PLATFORM_LINKS_REGISTRATION_TOKEN must be at least 32 characters when registration requires it.');
        }
    }

    public function presentsToken(?string $bearer): bool
    {
        return strlen($this->initialAccessToken) >= self::MIN_TOKEN_LENGTH
            && null !== $bearer
            && hash_equals($this->initialAccessToken, $bearer);
    }

    /**
     * The status a new client gets, or null when it may not register.
     */
    public function statusFor(?string $bearer): ?string
    {
        if ($this->presentsToken($bearer)) {
            return 'active';
        }

        return match ($this->mode) {
            self::OPEN => 'active',
            self::TOKEN => null,
            default => 'pending',
        };
    }
}
