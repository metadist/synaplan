<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Encryption Service
 * 
 * Simple encryption/decryption for sensitive data like IMAP passwords.
 * Uses APP_SECRET as encryption key.
 */
class EncryptionService
{
    private const CIPHER = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    public function __construct(
        private string $secretKey,
        private LoggerInterface $logger
    ) {
        // Derive encryption key from APP_SECRET (32 bytes for AES-256)
        $this->secretKey = hash('sha256', $secretKey, true);
    }

    /**
     * Encrypt plaintext
     */
    public function encrypt(string $plaintext): string
    {
        if (empty($plaintext)) {
            return '';
        }

        try {
            $iv = random_bytes(self::IV_LENGTH);
            $encrypted = openssl_encrypt(
                $plaintext,
                self::CIPHER,
                $this->secretKey,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new \RuntimeException('Encryption failed');
            }

            // Prepend IV to encrypted data and base64 encode
            return base64_encode($iv . $encrypted);
        } catch (\Exception $e) {
            $this->logger->error('Encryption failed', [
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Encryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt ciphertext
     */
    public function decrypt(string $ciphertext): string
    {
        if (empty($ciphertext)) {
            return '';
        }

        try {
            $data = base64_decode($ciphertext, true);
            
            if ($data === false || strlen($data) < self::IV_LENGTH) {
                throw new \RuntimeException('Invalid ciphertext');
            }

            $iv = substr($data, 0, self::IV_LENGTH);
            $encrypted = substr($data, self::IV_LENGTH);

            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER,
                $this->secretKey,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new \RuntimeException('Decryption failed');
            }

            return $decrypted;
        } catch (\Exception $e) {
            $this->logger->error('Decryption failed', [
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Decryption failed: ' . $e->getMessage());
        }
    }
}

