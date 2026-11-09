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
 * active at once.
 */
final readonly class RegistrationPolicy
{
    public const PENDING = 'pending';
    public const OPEN = 'open';
    public const TOKEN = 'token';

    public string $mode;

    public function __construct(string $mode, private string $initialAccessToken)
    {
        $mode = strtolower(trim($mode));
        $this->mode = in_array($mode, [self::OPEN, self::TOKEN], true) ? $mode : self::PENDING;
    }

    public function presentsToken(?string $bearer): bool
    {
        return '' !== $this->initialAccessToken && null !== $bearer && hash_equals($this->initialAccessToken, $bearer);
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
