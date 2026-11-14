<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * The attach token from the stream contract.
 *
 * token = base64url(runId, ownerKey, fromSeq, exp) + "." + base64url(HMAC-SHA256).
 * The cursor is inside the signature, so a client cannot widen it.
 * The watchdog verifies the same bytes.
 */
final class AttachToken
{
    public static function sign(string $secret, string $runId, string $ownerKey, int $fromSeq, int $exp): string
    {
        $payload = $runId."\n".$ownerKey."\n".$fromSeq."\n".$exp;

        return self::encode($payload).'.'.self::encode(hash_hmac('sha256', $payload, $secret, true));
    }

    public static function verify(string $secret, string $token, string $runId, string $ownerKey, int $fromSeq, int $now): bool
    {
        $parts = explode('.', $token, 2);
        if (2 !== count($parts)) {
            return false;
        }
        $payload = self::decode($parts[0]);
        $mac = self::decode($parts[1]);
        if (!is_string($payload) || !is_string($mac)) {
            return false;
        }
        $fields = explode("\n", $payload);
        if (4 !== count($fields) || $fields[0] !== $runId || $fields[1] !== $ownerKey) {
            return false;
        }
        if (!ctype_digit($fields[2]) || (int) $fields[2] !== $fromSeq) {
            return false;
        }
        if (!ctype_digit($fields[3]) || $now > (int) $fields[3]) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret, true), $mac);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $pad = strlen($encoded) % 4;
        if (0 !== $pad) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return false === $raw ? null : $raw;
    }
}
