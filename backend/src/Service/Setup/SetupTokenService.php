<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Repository\ConfigRepository;

/**
 * The one-time token that proves the person in the setup wizard can read this
 * installation's container log.
 *
 * Without it, the first network client to reach an empty installation would
 * become its administrator. The backend prints a fresh token on every start
 * while setup is still required; `php bin/console app:setup:token` prints a
 * new one on demand. Only the SHA-256 of the token is stored, and the row is
 * removed once the first administrator exists.
 */
final readonly class SetupTokenService
{
    public const KEY_TOKEN_HASH = 'TOKEN_HASH';

    /** No 0/O, 1/I/L: the token is read from a log and may be typed by hand. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const GROUPS = 5;
    private const GROUP_LENGTH = 4;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Replaces any earlier token and returns the new one, e.g. `K7QM-…`.
     */
    public function issue(): string
    {
        $groups = [];
        for ($group = 0; $group < self::GROUPS; ++$group) {
            $chars = '';
            for ($i = 0; $i < self::GROUP_LENGTH; ++$i) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $chars;
        }
        $token = implode('-', $groups);

        $this->configRepository->setValue(
            SetupConstants::OWNER_ID,
            SetupConstants::CONFIG_GROUP,
            self::KEY_TOKEN_HASH,
            $this->hash($token),
        );

        return $token;
    }

    /**
     * False for a wrong token and when no token has been issued at all.
     */
    public function matches(#[\SensitiveParameter] string $candidate): bool
    {
        $stored = $this->configRepository->getValue(
            SetupConstants::OWNER_ID,
            SetupConstants::CONFIG_GROUP,
            self::KEY_TOKEN_HASH,
        );
        if (null === $stored || '' === $stored) {
            return false;
        }

        return hash_equals($stored, $this->hash($candidate));
    }

    public function clear(): void
    {
        $this->configRepository->deleteValue(
            SetupConstants::OWNER_ID,
            SetupConstants::CONFIG_GROUP,
            self::KEY_TOKEN_HASH,
        );
    }

    /**
     * Case, spaces and dashes do not matter, so a hand-typed token works.
     */
    private function hash(string $token): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($token));

        return hash('sha256', is_string($normalized) ? $normalized : '');
    }
}
