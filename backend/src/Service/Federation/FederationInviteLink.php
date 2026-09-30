<?php

declare(strict_types=1);

namespace App\Service\Federation;

/**
 * The address an admin pastes. It points at the inviting server's API.
 */
final class FederationInviteLink
{
    /**
     * @return array{origin: string, token: string}
     */
    public static function parse(string $url): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            throw new FederationException('invite_invalid', 'That invite address is not valid.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        if ('' === $host || !in_array($scheme, ['http', 'https'], true)) {
            throw new FederationException('invite_invalid', 'That invite address is not valid.');
        }
        if (1 !== preg_match('#^/api/v1/federation/invites/([a-f0-9]{32})$#', $path, $match)) {
            throw new FederationException('invite_invalid', 'That invite address is not valid.');
        }
        $origin = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return ['origin' => $origin, 'token' => $match[1]];
    }
}
