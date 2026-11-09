<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * The bearer token of a request, told apart from a Synaplan API key sent the
 * same way (`Authorization: Bearer sk_…`).
 */
final class BearerToken
{
    public const API_KEY_PREFIX = 'sk_';

    public static function fromRequest(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));

        return '' === $token ? null : $token;
    }

    /**
     * A bearer that is a JWT, not an API key.
     */
    public static function jwtFromRequest(Request $request): ?string
    {
        $token = self::fromRequest($request);

        return null === $token || str_starts_with($token, self::API_KEY_PREFIX) ? null : $token;
    }
}
