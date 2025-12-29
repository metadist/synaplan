#!/usr/bin/env php
<?php
/**
 * Generate bcrypt password hash for database insertion.
 *
 * Usage:
 *   php createbcryptpw.php <password>
 *   php createbcryptpw.php "my secret password"
 *
 * The output can be directly inserted into the BPASSWORD column of BUSERS table.
 */

if ($argc < 2) {
    echo "Usage: php createbcryptpw.php <password>\n";
    echo "Example: php createbcryptpw.php mypassword123\n";
    exit(1);
}

$password = $argv[1];

// Use bcrypt with cost factor 13 (Symfony default)
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);

echo "\n";
echo "Password: {$password}\n";
echo "Bcrypt Hash:\n";
echo "{$hash}\n";
echo "\n";
echo "SQL Example:\n";
echo "UPDATE BUSERS SET BPASSWORD = '{$hash}' WHERE BEMAIL = 'user@example.com';\n";
echo "\n";
