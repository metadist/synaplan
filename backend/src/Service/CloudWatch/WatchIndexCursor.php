<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

/**
 * Opaque cursor for the app's watch index. A cursor older than 7 days is refused.
 */
final class WatchIndexCursor
{
    public const TTL_SECONDS = 604800;

    public static function page(int $now, int $afterId): string
    {
        return self::pack($now, 'p', 0, $afterId);
    }

    public static function since(int $now, int $updated, int $id): string
    {
        return self::pack($now, 's', $updated, $id);
    }

    /**
     * @return array{iat: int, kind: string, updated: int, id: int}|null null when the cursor cannot be read or has expired
     */
    public static function read(string $cursor, int $now): ?array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($raw)) {
            return null;
        }
        $parts = explode('|', $raw);
        if (4 !== count($parts) || !ctype_digit($parts[0]) || !in_array($parts[1], ['p', 's'], true) || !ctype_digit($parts[2]) || !ctype_digit($parts[3])) {
            return null;
        }
        $iat = (int) $parts[0];
        if ($iat > $now || $now - $iat > self::TTL_SECONDS) {
            return null;
        }

        return ['iat' => $iat, 'kind' => $parts[1], 'updated' => (int) $parts[2], 'id' => (int) $parts[3]];
    }

    private static function pack(int $now, string $kind, int $updated, int $id): string
    {
        return rtrim(strtr(base64_encode($now.'|'.$kind.'|'.$updated.'|'.$id), '+/', '-_'), '=');
    }
}
