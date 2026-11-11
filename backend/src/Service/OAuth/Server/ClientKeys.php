<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;

/**
 * The public keys a cloud registers (a JWK Set, RFC 7517) and signs its
 * client assertions with. Only what Synaplan verifies is accepted: RSA keys
 * of 2048 bits or more for RS256 and P-256 keys for ES256. Every stored key
 * names its algorithm, so a key is never tried with another one.
 */
final class ClientKeys
{
    public const MAX_KEYS = 5;

    private const RSA_MIN_BITS = 2048;
    private const KID_MAX_LENGTH = 128;
    private const ALGORITHMS = ['RSA' => 'RS256', 'EC' => 'ES256'];
    private const PUBLIC_MEMBERS = ['RSA' => ['n', 'e'], 'EC' => ['crv', 'x', 'y']];
    private const PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * The registered set, reduced to its public signing keys.
     *
     * @return array{keys: list<array<string, string>>}
     *
     * @throws RegistrationException when a key is private, weak, malformed or of another kind
     */
    public static function normalize(mixed $jwks): array
    {
        $keys = is_array($jwks) && is_array($jwks['keys'] ?? null) ? array_values($jwks['keys']) : [];
        if ([] === $keys || count($keys) > self::MAX_KEYS) {
            throw self::invalid(sprintf('jwks needs one to %d public keys.', self::MAX_KEYS));
        }
        $clean = array_map(self::normalizeKey(...), $keys);

        $kids = array_column($clean, 'kid');
        if (count($clean) > 1 && (count($kids) !== count($clean) || count(array_unique($kids)) !== count($kids))) {
            throw self::invalid('Each key in jwks needs its own kid when there is more than one.');
        }
        try {
            JWK::parseKeySet(['keys' => $clean]);
        } catch (\Throwable) {
            throw self::invalid('jwks holds a key that cannot be read.');
        }

        return ['keys' => $clean];
    }

    /**
     * The keys an assertion is checked against. A single key is used whatever
     * the assertion's kid says; several are looked up by kid.
     *
     * @param array<string, mixed> $jwks a set {@see normalize()} accepted
     *
     * @return Key|array<string, Key>
     */
    public static function parse(array $jwks): Key|array
    {
        $keys = JWK::parseKeySet($jwks);

        return 1 === count($keys) ? array_values($keys)[0] : $keys;
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeKey(mixed $key): array
    {
        $type = is_array($key) ? ($key['kty'] ?? null) : null;
        if (!is_array($key) || !is_string($type) || !isset(self::ALGORITHMS[$type])) {
            throw self::invalid('jwks may hold RSA or EC public keys only.');
        }
        if ([] !== array_intersect(self::PRIVATE_MEMBERS, array_keys($key))) {
            throw self::invalid('jwks must not contain private key material.');
        }
        if (self::ALGORITHMS[$type] !== ($key['alg'] ?? self::ALGORITHMS[$type]) || 'sig' !== ($key['use'] ?? 'sig')) {
            throw self::invalid('jwks keys must be signing keys for RS256 (RSA) or ES256 (EC, P-256).');
        }
        $clean = ['kty' => $type, 'use' => 'sig', 'alg' => self::ALGORITHMS[$type]];
        foreach (self::PUBLIC_MEMBERS[$type] as $member) {
            if (!is_string($key[$member] ?? null) || '' === $key[$member]) {
                throw self::invalid(sprintf('A jwks %s key needs %s.', $type, $member));
            }
            $clean[$member] = $key[$member];
        }
        if ('EC' === $type && 'P-256' !== $clean['crv']) {
            throw self::invalid('EC keys in jwks must use the curve P-256.');
        }
        if ('RSA' === $type && self::bits($clean['n']) < self::RSA_MIN_BITS) {
            throw self::invalid(sprintf('RSA keys in jwks need at least %d bits.', self::RSA_MIN_BITS));
        }
        $kid = $key['kid'] ?? null;
        if (null !== $kid) {
            if (!is_string($kid) || '' === $kid || strlen($kid) > self::KID_MAX_LENGTH) {
                throw self::invalid(sprintf('A kid in jwks is a text of at most %d characters.', self::KID_MAX_LENGTH));
            }
            $clean['kid'] = $kid;
        }

        return $clean;
    }

    private static function bits(string $modulus): int
    {
        $raw = base64_decode(strtr($modulus, '-_', '+/'), true);

        return false === $raw ? 0 : strlen(ltrim($raw, "\0")) * 8;
    }

    private static function invalid(string $description): RegistrationException
    {
        return new RegistrationException('invalid_client_metadata', $description);
    }
}
