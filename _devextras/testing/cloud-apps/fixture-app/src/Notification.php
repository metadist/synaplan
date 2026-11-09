<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * Checks a notification the way every cloud app must: a JSON Web Token in
 * compact JWS form, typed `synaplan-notification+jwt`, EdDSA (Ed25519) with a
 * key from Synaplan's JWK Set, issued by the Synaplan this cloud talks to
 * (`iss`, as `/.well-known/synaplan-configuration` names it), addressed to
 * this instance (`aud`), inside its window (`iat`, `exp`). The five contract
 * vectors in `backend/tests/Fixtures/cloud-apps-contract/notify-vectors.json`
 * pin it.
 */
final class Notification
{
    public const OK = 'ok';
    public const BAD_SIGNATURE = 'bad_signature';
    public const WRONG_INSTANCE = 'wrong_instance';
    public const STALE = 'stale';
    public const WRONG_ISSUER = 'wrong_issuer';

    private const TYPE = 'synaplan-notification+jwt';
    private const SKEW_SECONDS = 60;
    private const PREFIX = 'ed25519:';

    /**
     * @param list<array{publicKey?: mixed, kid?: mixed}|mixed> $keys Synaplan's notify keys
     */
    public static function verify(string $token, string $issuer, string $instanceId, array $keys, int $now): string
    {
        $parts = explode('.', trim($token));
        $header = self::header($token);
        if (3 !== count($parts) || 'EdDSA' !== ($header['alg'] ?? null) || self::TYPE !== ($header['typ'] ?? null)) {
            return self::BAD_SIGNATURE;
        }
        $signature = self::b64decode($parts[2]);
        $verified = false;
        foreach ($keys as $key) {
            $publicKey = is_array($key) ? ($key['publicKey'] ?? null) : null;
            $raw = is_string($publicKey) && str_starts_with($publicKey, self::PREFIX) ? self::b64decode(substr($publicKey, strlen(self::PREFIX))) : null;
            if (null !== $signature && null !== $raw && SODIUM_CRYPTO_SIGN_BYTES === strlen($signature) && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen($raw)
                && sodium_crypto_sign_verify_detached($signature, $parts[0].'.'.$parts[1], $raw)) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            return self::BAD_SIGNATURE;
        }
        $claims = self::claims($token);
        if ('' === $issuer || ($claims['iss'] ?? null) !== $issuer) {
            return self::WRONG_ISSUER;
        }
        if (($claims['aud'] ?? null) !== $instanceId) {
            return self::WRONG_INSTANCE;
        }
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp) || $now > $exp || $iat > $now + self::SKEW_SECONDS) {
            return self::STALE;
        }

        return self::OK;
    }

    /**
     * The token's claims, unverified: call verify() first.
     *
     * @return array<string, mixed>
     */
    public static function claims(string $token): array
    {
        $parts = explode('.', trim($token));
        $decoded = 3 === count($parts) ? json_decode(self::b64decode($parts[1]) ?? '', true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function header(string $token): array
    {
        $parts = explode('.', trim($token));
        $decoded = 3 === count($parts) ? json_decode(self::b64decode($parts[0]) ?? '', true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function b64decode(string $encoded): ?string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return false === $raw ? null : $raw;
    }
}
