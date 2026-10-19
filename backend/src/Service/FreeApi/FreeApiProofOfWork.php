<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

/**
 * Finds the issuance stamp the Demo server asks for: a nonce such that
 * sha256(install_id + ':' + nonce) starts with `bits` zero bits.
 *
 * Mirrors the server's verifier byte for byte. The expected work is 2^bits
 * hashes — 18 bits take a fraction of a second, so a one-click activation
 * stays instant while minting thousands of keys does not.
 */
final class FreeApiProofOfWork
{
    /** `pow_bits` of contract/v1/limits.json. */
    public const DEFAULT_BITS = 18;

    /** A server asking for more work than this is refused instead of burning a request worker. */
    public const MAX_BITS = 22;

    /** Wall-clock budget for one search; the activation request must stay well inside the web timeout. */
    public const MAX_SECONDS = 20.0;

    private const CLOCK_CHECK_INTERVAL = 0x10000;

    /**
     * @return string|null null when the bits are out of range or the time budget ran out
     */
    public static function solve(string $installId, int $bits, float $maxSeconds = self::MAX_SECONDS): ?string
    {
        if ($bits < 1 || $bits > self::MAX_BITS) {
            return null;
        }

        $deadline = hrtime(true) + (int) ($maxSeconds * 1e9);
        for ($nonce = 0;; ++$nonce) {
            $candidate = (string) $nonce;
            if (self::verify($installId, $candidate, $bits)) {
                return $candidate;
            }
            if (0 === $nonce % self::CLOCK_CHECK_INTERVAL && $nonce > 0 && hrtime(true) > $deadline) {
                return null;
            }
        }
    }

    public static function verify(string $installId, string $nonce, int $bits): bool
    {
        $hash = hash('sha256', $installId.':'.$nonce, true);
        $full = intdiv($bits, 8);
        for ($i = 0; $i < $full; ++$i) {
            if ("\0" !== $hash[$i]) {
                return false;
            }
        }

        $rest = $bits % 8;
        if (0 === $rest) {
            return true;
        }

        return 0 === (\ord($hash[$full]) & ((0xFF << (8 - $rest)) & 0xFF));
    }
}
