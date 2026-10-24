<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * Checks a signed notification the way every cloud app must: Ed25519 over
 * the body without `sig`, keys sorted, as compact JSON; addressed to this
 * instance; at most five minutes old. The five contract vectors in
 * `backend/tests/Fixtures/cloud-apps-contract/notify-vectors.json` pin it.
 */
final class Notification
{
    public const OK = 'ok';
    public const BAD_SIGNATURE = 'bad_signature';
    public const WRONG_INSTANCE = 'wrong_instance';
    public const STALE = 'stale';

    public const WINDOW_SECONDS = 300;

    private const PREFIX = 'ed25519:';

    /**
     * @param array<mixed>                                     $body
     * @param list<array{publicKey?: mixed, kid?: mixed}|mixed> $keys Synaplan's notify keys
     */
    public static function verify(array $body, string $instanceId, array $keys, int $now, int $window = self::WINDOW_SECONDS): string
    {
        $signature = $body['sig'] ?? null;
        if (!is_string($signature) || '' === $signature) {
            return self::BAD_SIGNATURE;
        }
        if (($body['instance_id'] ?? null) !== $instanceId) {
            return self::WRONG_INSTANCE;
        }
        $ts = $body['ts'] ?? null;
        if (!is_int($ts) || abs($now - $ts) > $window) {
            return self::STALE;
        }
        $message = self::canonical($body);
        foreach ($keys as $key) {
            $publicKey = is_array($key) ? ($key['publicKey'] ?? null) : null;
            if (is_string($publicKey) && self::verifies($message, $signature, $publicKey)) {
                return self::OK;
            }
        }

        return self::BAD_SIGNATURE;
    }

    /**
     * @param array<mixed> $body
     */
    public static function canonical(array $body): string
    {
        unset($body['sig']);

        return json_encode(self::sorted($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function verifies(string $message, string $signature, string $publicKey): bool
    {
        $sig = self::decode($signature, SODIUM_CRYPTO_SIGN_BYTES);
        $key = self::decode($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);

        return null !== $sig && null !== $key && sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    private static function decode(string $encoded, int $length): ?string
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            return null;
        }
        $b64 = strtr(substr($encoded, strlen(self::PREFIX)), '-_', '+/');
        $raw = base64_decode($b64.str_repeat('=', (4 - strlen($b64) % 4) % 4), true);

        return is_string($raw) && strlen($raw) === $length ? $raw : null;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }
}
