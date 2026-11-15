<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use Symfony\Component\HttpFoundation\Request;

/**
 * PHP answers an attach directive only to the watchdog.
 *
 * The header value must be the shared secret. The literal "1" is not a
 * proof, and the peer must be loopback: Caddy sends long routes to the
 * watchdog, and the watchdog dials PHP on this host.
 */
final class RelayProof
{
    public function accepts(Request $request): bool
    {
        $given = $request->headers->get('X-Synaplan-Relay');
        $secret = self::secret();
        if (!is_string($given) || '' === $given || '' === $secret || '1' === $given) {
            return false;
        }
        if (!hash_equals($secret, $given)) {
            return false;
        }

        $peer = $request->server->get('REMOTE_ADDR');

        return '127.0.0.1' === $peer || '::1' === $peer;
    }

    public static function secret(): string
    {
        $value = $_ENV['SYNAPLAN_RELAY_SECRET'] ?? $_SERVER['SYNAPLAN_RELAY_SECRET'] ?? getenv('SYNAPLAN_RELAY_SECRET');

        return is_string($value) ? $value : '';
    }
}
