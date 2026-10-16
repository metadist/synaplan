<?php

declare(strict_types=1);

namespace App\Service\Federation;

/**
 * Ed25519 signatures for server-to-server federation messages.
 *
 * Keys and signatures are the prefix `ed25519:` plus unpadded base64url.
 * The signed bytes are the canonical JSON of the body with `sig` removed.
 */
final class FederationSigner
{
    public const PREFIX = 'ed25519:';

    private const PUBLIC_BYTES = 32;

    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_keypair');
    }

    /**
     * @return array{publicKey: string, secretKey: string}
     */
    public function generate(): array
    {
        $this->assertAvailable();
        $pair = sodium_crypto_sign_keypair();

        return [
            'publicKey' => $this->encode(sodium_crypto_sign_publickey($pair)),
            'secretKey' => $this->encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function signBody(array $body, string $secretKey): array
    {
        unset($body['sig']);
        $body['sig'] = $this->sign($this->canonical($body), $secretKey);

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function verifyBody(array $body, string $publicKey): void
    {
        $signature = $body['sig'] ?? null;
        if (!is_string($signature) || '' === $signature) {
            throw new FederationException('bad_signature', 'The request signature is missing.');
        }
        unset($body['sig']);
        if (!$this->verify($this->canonical($body), $signature, $publicKey)) {
            throw new FederationException('bad_signature', 'The request signature is not valid.');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    public function canonical(array $body): string
    {
        unset($body['sig']);

        return json_encode($this->sort($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function sign(string $message, string $secretKey): string
    {
        $this->assertAvailable();

        return $this->encode(sodium_crypto_sign_detached($message, $this->decode($secretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)));
    }

    public function verify(string $message, string $signature, string $publicKey): bool
    {
        if (!self::available()) {
            return false;
        }
        try {
            $sig = $this->decode($signature, SODIUM_CRYPTO_SIGN_BYTES);
            $key = $this->decode($publicKey, self::PUBLIC_BYTES);
        } catch (FederationException) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    public function fingerprint(string $publicKey): string
    {
        $raw = substr($publicKey, strlen(self::PREFIX));

        return substr($raw, 0, 16);
    }

    private function assertAvailable(): void
    {
        if (!self::available()) {
            throw new FederationException('sodium_missing', 'This server cannot sign partner requests. The signing library is missing.', 503);
        }
    }

    private function encode(string $raw): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $encoded, int $expected): string
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new FederationException('bad_signature', 'The key format is not valid.');
        }
        $b64 = substr($encoded, strlen(self::PREFIX));
        $pad = strlen($b64) % 4;
        if (0 !== $pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);
        if (!is_string($raw) || strlen($raw) !== $expected) {
            throw new FederationException('bad_signature', 'The key format is not valid.');
        }

        return $raw;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private function sort(array $value): array
    {
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = is_array($item) ? $this->sort($item) : $item;
            }

            return $out;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sort($item);
            }
        }

        return $value;
    }
}
