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
 * Values written by earlier releases (base64 of an IV and AES-256-CBC
 * ciphertext, no prefix) stay readable. They are replaced by v2 the next time
 * their owner saves them.
 *
 * The optional context ties a value to a place: a value encrypted with a
 * context opens only with the same one, so it cannot be moved to another row.
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
            return str_starts_with($ciphertext, self::V2_PREFIX)
                ? $this->open(substr($ciphertext, strlen(self::V2_PREFIX)), $context)
                : $this->openLegacy($ciphertext);
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

    private function open(string $encoded, string $context): string
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
        if (false === $plaintext) {
            throw new \RuntimeException('The value was altered or belongs to another key or place');
        }

        return $plaintext;
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
