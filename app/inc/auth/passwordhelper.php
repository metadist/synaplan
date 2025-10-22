<?php

/**
 * Password Helper Class
 *
 * Provides secure password hashing and verification with backward compatibility for MD5.
 *
 * Features:
 * - Modern bcrypt password hashing for new passwords
 * - Backward compatibility with legacy MD5 passwords
 * - Automatic upgrade from MD5 to bcrypt on successful login
 * - Secure password verification
 *
 * @package Auth
 */

class PasswordHelper
{
    /**
     * Hash a password using bcrypt
     *
     * @param string $password Plain text password
     * @return string Hashed password
     */
    public static function hash(string $password): string
    {
        // Use PASSWORD_BCRYPT with default cost (currently 10)
        // This creates hashes like: $2y$10$...
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Verify a password against a stored hash
     *
     * Supports both legacy MD5 and modern bcrypt hashes.
     * MD5 hashes are 32 character hexadecimal strings.
     * Bcrypt hashes start with $2y$ (or other bcrypt identifiers).
     *
     * @param string $password Plain text password to verify
     * @param string $storedHash The stored hash from database
     * @return bool True if password matches, false otherwise
     */
    public static function verify(string $password, string $storedHash): bool
    {
        // Check if this is a legacy MD5 hash (32 hex characters)
        if (self::isMd5Hash($storedHash)) {
            // Verify using MD5 for backward compatibility
            return md5($password) === $storedHash;
        }

        // Verify using modern password_verify for bcrypt
        return password_verify($password, $storedHash);
    }

    /**
     * Check if a hash needs rehashing (upgrade)
     *
     * Returns true if:
     * - The hash is MD5 (legacy format)
     * - The hash uses outdated bcrypt parameters
     *
     * @param string $hash The stored hash
     * @return bool True if hash needs upgrade
     */
    public static function needsRehash(string $hash): bool
    {
        // Always rehash MD5 passwords
        if (self::isMd5Hash($hash)) {
            return true;
        }

        // Check if bcrypt hash needs rehashing (cost changed, etc.)
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }

    /**
     * Check if a hash is MD5 format
     *
     * MD5 hashes are exactly 32 hexadecimal characters
     *
     * @param string $hash Hash to check
     * @return bool True if MD5 format
     */
    private static function isMd5Hash(string $hash): bool
    {
        return strlen($hash) === 32 && ctype_xdigit($hash);
    }

    /**
     * Upgrade a user's password hash in the database
     *
     * This should be called after successful login if needsRehash() returns true.
     *
     * @param int $userId User ID
     * @param string $plainPassword Plain text password (from login form)
     * @return bool True if upgrade successful
     */
    public static function upgradeUserPassword(int $userId, string $plainPassword): bool
    {
        try {
            $newHash = self::hash($plainPassword);
            $sql = "UPDATE BUSER SET BPW = '" . db::EscString($newHash) . "' WHERE BID = " . intval($userId);
            db::Query($sql);
            return true;
        } catch (\Throwable $e) {
            error_log('Password upgrade failed for user ' . $userId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate password strength
     *
     * @param string $password Password to validate
     * @param int $minLength Minimum password length (default 8)
     * @return array ['valid' => bool, 'error' => string]
     */
    public static function validateStrength(string $password, int $minLength = 8): array
    {
        if (strlen($password) < $minLength) {
            return [
                'valid' => false,
                'error' => "Password must be at least {$minLength} characters long."
            ];
        }

        // Add additional strength requirements here if needed
        // For example: require uppercase, lowercase, numbers, special chars

        return ['valid' => true, 'error' => ''];
    }
}


