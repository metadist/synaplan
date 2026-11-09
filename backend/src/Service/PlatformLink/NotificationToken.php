<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

/**
 * A notification to a cloud as a JSON Web Token (RFC 7519) in compact JWS
 * form, signed with EdDSA (Ed25519, RFC 8037). The key's id is the `kid` in
 * the header and in Synaplan's JWK Set at /.well-known/jwks.json, so a cloud
 * verifies it with any JOSE library or a few lines of libsodium.
 *
 * Header `typ` is `synaplan-notification+jwt` (explicit typing, RFC 8725
 * 3.11), so the token is never mistaken for another JWT. Claims: `iss`
 * Synaplan's public URL, `aud` the instance id, `sub` the person (external
 * id, when the notice is about one), `type` and `id` what happened,
 * `iat`/`exp` a five-minute window, `jti` unique per token. A receiver
 * checks signature, `typ`, `iss` against the Synaplan it is configured for,
 * audience and window, then refuses a `type`/`id` it saw in the last ten
 * minutes. It never fetches keys from a URL named in the token.
 */
final class NotificationToken
{
    public const OK = 'ok';
    public const BAD_SIGNATURE = 'bad_signature';
    public const WRONG_INSTANCE = 'wrong_instance';
    public const STALE = 'stale';
    public const WRONG_ISSUER = 'wrong_issuer';

    public const TYPE = 'synaplan-notification+jwt';

    public const LIFETIME_SECONDS = 300;
    private const SKEW_SECONDS = 60;
    private const KEY_PREFIX = 'ed25519:';

    /**
     * @param array<string, mixed> $claims
     */
    public static function encode(array $claims, string $secretKey, string $kid): string
    {
        $header = self::b64(self::json(['alg' => 'EdDSA', 'typ' => self::TYPE, 'kid' => $kid]));
        $payload = self::b64(self::json($claims));
        $signature = sodium_crypto_sign_detached($header.'.'.$payload, self::keyBytes($secretKey));

        return $header.'.'.$payload.'.'.self::b64($signature);
    }

    /**
     * @return array<string, mixed>
     */
    public static function claimsFor(string $issuer, string $type, string $id, string $instanceId, ?string $externalId, int $now): array
    {
        $claims = [
            'iss' => $issuer,
            'aud' => $instanceId,
            'type' => $type,
            'id' => $id,
            'iat' => $now,
            'exp' => $now + self::LIFETIME_SECONDS,
            'jti' => bin2hex(random_bytes(16)),
        ];
        if (null !== $externalId && '' !== $externalId) {
            $claims['sub'] = $externalId;
        }

        return $claims;
    }

    /**
     * The receiver's check, as every cloud app implements it.
     *
     * @param list<string> $publicKeys keys as `ed25519:<base64url>`
     */
    public static function verdict(string $token, string $issuer, string $instanceId, array $publicKeys, int $now): string
    {
        $claims = self::verifiedClaims($token, $publicKeys);
        if (null === $claims) {
            return self::BAD_SIGNATURE;
        }
        if (($claims['iss'] ?? null) !== $issuer) {
            return self::WRONG_ISSUER;
        }
        if (($claims['aud'] ?? null) !== $instanceId) {
            return self::WRONG_INSTANCE;
        }
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp) || $now >= $exp || $iat > $now + self::SKEW_SECONDS) {
            return self::STALE;
        }

        return self::OK;
    }

    /**
     * @param list<string> $publicKeys
     *
     * @return array<string, mixed>|null
     */
    public static function verifiedClaims(string $token, array $publicKeys): ?array
    {
        $parts = explode('.', trim($token));
        if (3 !== count($parts)) {
            return null;
        }
        $header = json_decode(self::unb64($parts[0]) ?? '', true);
        if (!is_array($header) || 'EdDSA' !== ($header['alg'] ?? null) || self::TYPE !== ($header['typ'] ?? null)) {
            return null;
        }
        $signature = self::unb64($parts[2]);
        if (null === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen($signature)) {
            return null;
        }
        foreach ($publicKeys as $publicKey) {
            $key = self::keyBytes($publicKey);
            if (SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen($key)
                && sodium_crypto_sign_verify_detached($signature, $parts[0].'.'.$parts[1], $key)) {
                $claims = json_decode(self::unb64($parts[1]) ?? '', true);

                return is_array($claims) ? $claims : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): ?string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return false === $raw ? null : $raw;
    }

    private static function keyBytes(string $key): string
    {
        return self::unb64(str_starts_with($key, self::KEY_PREFIX) ? substr($key, strlen(self::KEY_PREFIX)) : $key) ?? '';
    }
}
