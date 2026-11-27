<?php

declare(strict_types=1);

namespace App\Service\Security;

use Psr\Log\LoggerInterface;

/**
 * Encrypts secrets the database keeps: provider keys, mailbox passwords,
 * connector tokens.
 *
 * New values are written in format v2: `v2:` followed by base64 of a 24-byte
 * nonce and the XChaCha20-Poly1305 ciphertext. The cipher authenticates, so a
 * value that was altered in the database fails to open instead of decrypting
 * to something else. The key is derived from APP_SECRET for this purpose
 * alone ({@see PurposeKey}).
 *
 * The context ties a value to a place ({@see \App\Model\SecretPlace}): a
 * value encrypted with a context opens only with the same one, so it cannot
 * be moved to another owner's row.
 *
 * Two older shapes stay readable until an installation has run
 * `app:secrets:reencrypt`: values from earlier releases (base64 of an IV and
 * AES-256-CBC ciphertext, no prefix), and v2 values written before callers
 * named a place. A save or that command replaces either with a bound value.
 */
final class EncryptionService
{
    public const KEY_PURPOSE = 'synaplan/encryption/v2';

    private const V2_PREFIX = 'v2:';
    private const LEGACY_CIPHER = 'AES-256-CBC';
    private const LEGACY_IV_LENGTH = 16;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secretKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context = ''): string
    {
        if ('' === $plaintext) {
            return '';
        }

        try {
            $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key());

            return self::V2_PREFIX.base64_encode($nonce.$sealed);
        } catch (\Throwable $e) {
            $this->logger->error('Encryption failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Encryption failed: '.$e->getMessage());
        }
    }

    public function decrypt(string $ciphertext, string $context = ''): string
    {
        if ('' === $ciphertext) {
            return '';
        }

        try {
            if (!str_starts_with($ciphertext, self::V2_PREFIX)) {
                return $this->openLegacy($ciphertext);
            }
            $encoded = substr($ciphertext, strlen(self::V2_PREFIX));

            return $this->open($encoded, $context)
                ?? ('' !== $context ? $this->open($encoded, '') : null)
                ?? throw new \RuntimeException('The value was altered or belongs to another key or place');
        } catch (\Throwable $e) {
            $this->logger->error('Decryption failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Decryption failed: '.$e->getMessage());
        }
    }

    /**
     * True for a value an earlier release wrote, which a save would upgrade.
     */
    public function isLegacy(string $ciphertext): bool
    {
        return '' !== $ciphertext && !str_starts_with($ciphertext, self::V2_PREFIX);
    }

    /**
     * The ciphertext to store at $context in place of $stored, or null when
     * $stored is already bound to that place. Used to move values written by
     * an earlier release to the current format without their owner saving them.
     *
     * @throws \RuntimeException when the value cannot be read
     */
    public function rewrap(string $stored, string $context): ?string
    {
        if ('' === $stored) {
            return null;
        }

        return $this->isBoundTo($stored, $context)
            ? null
            : $this->encrypt($this->decrypt($stored, $context), $context);
    }

    private function isBoundTo(string $stored, string $context): bool
    {
        if (!str_starts_with($stored, self::V2_PREFIX)) {
            return false;
        }

        try {
            return null !== $this->open(substr($stored, strlen(self::V2_PREFIX)), $context);
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Null when the value does not authenticate under this key and context.
     */
    private function open(string $encoded, string $context): ?string
    {
        $data = base64_decode($encoded, true);
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (false === $data || strlen($data) < $nonceLength + \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new \RuntimeException('Invalid ciphertext');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($data, $nonceLength),
            $context,
            substr($data, 0, $nonceLength),
            $this->key(),
        );

        return false === $plaintext ? null : $plaintext;
    }

    private function openLegacy(string $ciphertext): string
    {
        $data = base64_decode($ciphertext, true);
        if (false === $data || strlen($data) < self::LEGACY_IV_LENGTH) {
            throw new \RuntimeException('Invalid ciphertext');
        }

        $plaintext = openssl_decrypt(
            substr($data, self::LEGACY_IV_LENGTH),
            self::LEGACY_CIPHER,
            hash('sha256', $this->secretKey, true),
            \OPENSSL_RAW_DATA,
            substr($data, 0, self::LEGACY_IV_LENGTH),
        );
        if (false === $plaintext) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    private function key(): string
    {
        return PurposeKey::derive($this->secretKey, self::KEY_PURPOSE);
    }
}
