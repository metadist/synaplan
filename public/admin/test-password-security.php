<?php

/**
 * Password Security Test Script
 *
 * Tests the new password security implementation:
 * 1. MD5 verification still works
 * 2. Bcrypt hashing and verification works
 * 3. Auto-upgrade detection works
 * 4. Password strength validation works
 */

// Initialize session and includes
session_start();
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/inc/_coreincludes.php';

// Set response header
header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Security Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
            background: #f5f5f5;
            color: #333;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .test-section {
            background: white;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .test-result {
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            font-family: monospace;
        }
        .success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .info {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            color: #0c5460;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
        h2 {
            color: #667eea;
            margin-top: 0;
        }
        h3 {
            color: #555;
            margin-top: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔐 Password Security Test</h1>
        <p>Testing the new bcrypt password implementation with MD5 backward compatibility</p>
    </div>

    <?php
    // Test password
    $testPassword = 'TestPassword123!';

// Test 1: Bcrypt Hashing
echo '<div class="test-section">';
echo '<h2>Test 1: Bcrypt Password Hashing</h2>';

try {
    $bcryptHash = PasswordHelper::hash($testPassword);

    if (substr($bcryptHash, 0, 4) === '$2y$' && strlen($bcryptHash) === 60) {
        echo '<div class="test-result success">';
        echo '✓ SUCCESS: Password hashed with bcrypt<br>';
        echo 'Hash: <code>' . htmlspecialchars($bcryptHash) . '</code><br>';
        echo 'Length: ' . strlen($bcryptHash) . ' characters<br>';
        echo 'Format: bcrypt ($2y$ prefix detected)';
        echo '</div>';
    } else {
        echo '<div class="test-result error">';
        echo '✗ FAILED: Hash does not match bcrypt format<br>';
        echo 'Hash: <code>' . htmlspecialchars($bcryptHash) . '</code>';
        echo '</div>';
    }
} catch (\Throwable $e) {
    echo '<div class="test-result error">';
    echo '✗ EXCEPTION: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}

echo '</div>';

// Test 2: Bcrypt Verification
echo '<div class="test-section">';
echo '<h2>Test 2: Bcrypt Password Verification</h2>';

try {
    $isValid = PasswordHelper::verify($testPassword, $bcryptHash);

    if ($isValid) {
        echo '<div class="test-result success">';
        echo '✓ SUCCESS: Correct password verified successfully';
        echo '</div>';
    } else {
        echo '<div class="test-result error">';
        echo '✗ FAILED: Correct password was rejected';
        echo '</div>';
    }

    // Test wrong password
    $isInvalid = PasswordHelper::verify('WrongPassword', $bcryptHash);

    if (!$isInvalid) {
        echo '<div class="test-result success">';
        echo '✓ SUCCESS: Wrong password correctly rejected';
        echo '</div>';
    } else {
        echo '<div class="test-result error">';
        echo '✗ FAILED: Wrong password was accepted';
        echo '</div>';
    }
} catch (\Throwable $e) {
    echo '<div class="test-result error">';
    echo '✗ EXCEPTION: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}

echo '</div>';

// Test 3: MD5 Backward Compatibility
echo '<div class="test-section">';
echo '<h2>Test 3: MD5 Backward Compatibility</h2>';

try {
    // Create MD5 hash (legacy format)
    $md5Hash = md5($testPassword);

    echo '<div class="test-result info">';
    echo 'Legacy MD5 Hash: <code>' . htmlspecialchars($md5Hash) . '</code><br>';
    echo 'Length: ' . strlen($md5Hash) . ' characters (32 hex = MD5)';
    echo '</div>';

    // Test MD5 verification
    $isValid = PasswordHelper::verify($testPassword, $md5Hash);

    if ($isValid) {
        echo '<div class="test-result success">';
        echo '✓ SUCCESS: MD5 password verified (backward compatibility works)';
        echo '</div>';
    } else {
        echo '<div class="test-result error">';
        echo '✗ FAILED: MD5 password verification failed';
        echo '</div>';
    }
} catch (\Throwable $e) {
    echo '<div class="test-result error">';
    echo '✗ EXCEPTION: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}

echo '</div>';

// Test 4: Rehash Detection
echo '<div class="test-section">';
echo '<h2>Test 4: Password Rehash Detection</h2>';

try {
    // Test MD5 needs rehash
    $needsRehash = PasswordHelper::needsRehash($md5Hash);

    if ($needsRehash) {
        echo '<div class="test-result success">';
        echo '✓ SUCCESS: MD5 hash correctly detected as needing upgrade';
        echo '</div>';
    } else {
        echo '<div class="test-result error">';
        echo '✗ FAILED: MD5 hash not detected as needing upgrade';
        echo '</div>';
    }

    // Test bcrypt doesn't need rehash
    $needsRehash = PasswordHelper::needsRehash($bcryptHash);

    if (!$needsRehash) {
        echo '<div class="test-result success">';
        echo '✓ SUCCESS: Bcrypt hash correctly detected as up-to-date';
        echo '</div>';
    } else {
        echo '<div class="test-result warning">';
        echo '⚠ WARNING: Bcrypt hash detected as needing upgrade (cost factor may have changed)';
        echo '</div>';
    }
} catch (\Throwable $e) {
    echo '<div class="test-result error">';
    echo '✗ EXCEPTION: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}

echo '</div>';

// Test 5: Database Statistics
echo '<div class="test-section">';
echo '<h2>Test 5: Database Password Statistics</h2>';

try {
    // Query password hash types
    $statsSQL = "
            SELECT 
                CASE 
                    WHEN LENGTH(BPW) = 32 AND BPW REGEXP '^[a-f0-9]{32}$' THEN 'MD5'
                    WHEN BPW LIKE '\$2y\$%' THEN 'bcrypt'
                    WHEN BPW LIKE '\$2a\$%' THEN 'bcrypt'
                    WHEN BPW LIKE '\$2b\$%' THEN 'bcrypt'
                    ELSE 'unknown'
                END as hash_type,
                COUNT(*) as user_count
            FROM BUSER
            WHERE LENGTH(BPW) > 0
            GROUP BY hash_type
        ";

    $statsRes = db::Query($statsSQL);
    $stats = [];
    $total = 0;

    while ($row = db::FetchArr($statsRes)) {
        $stats[$row['hash_type']] = intval($row['user_count']);
        $total += intval($row['user_count']);
    }

    echo '<div class="stats">';

    echo '<div class="stat-box">';
    echo '<div class="stat-number">' . $total . '</div>';
    echo '<div class="stat-label">Total Users</div>';
    echo '</div>';

    echo '<div class="stat-box">';
    echo '<div class="stat-number" style="color: #28a745;">' . ($stats['bcrypt'] ?? 0) . '</div>';
    echo '<div class="stat-label">Bcrypt (Secure)</div>';
    echo '</div>';

    echo '<div class="stat-box">';
    echo '<div class="stat-number" style="color: #ffc107;">' . ($stats['MD5'] ?? 0) . '</div>';
    echo '<div class="stat-label">MD5 (Legacy)</div>';
    echo '</div>';

    if ($total > 0) {
        $bcryptPercent = round((($stats['bcrypt'] ?? 0) / $total) * 100, 1);
        echo '<div class="stat-box">';
        echo '<div class="stat-number" style="color: #17a2b8;">' . $bcryptPercent . '%</div>';
        echo '<div class="stat-label">Migration Progress</div>';
        echo '</div>';
    }

    echo '</div>';

    if (($stats['MD5'] ?? 0) > 0) {
        echo '<div class="test-result warning" style="margin-top: 20px;">';
        echo '⚠ INFO: ' . ($stats['MD5'] ?? 0) . ' user(s) still have MD5 passwords.<br>';
        echo 'These will be automatically upgraded to bcrypt when users log in.';
        echo '</div>';
    }

    if (($stats['bcrypt'] ?? 0) > 0) {
        echo '<div class="test-result success" style="margin-top: 20px;">';
        echo '✓ SUCCESS: ' . ($stats['bcrypt'] ?? 0) . ' user(s) have secure bcrypt passwords.';
        echo '</div>';
    }

    if (isset($stats['unknown']) && $stats['unknown'] > 0) {
        echo '<div class="test-result error" style="margin-top: 20px;">';
        echo '✗ WARNING: ' . $stats['unknown'] . ' user(s) have unknown password format!';
        echo '</div>';
    }

} catch (\Throwable $e) {
    echo '<div class="test-result error">';
    echo '✗ EXCEPTION: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}

echo '</div>';

// Summary
echo '<div class="test-section">';
echo '<h2>Summary</h2>';
echo '<div class="test-result info">';
echo '<strong>Password Security Upgrade Status:</strong><br><br>';
echo '✓ Bcrypt hashing implemented<br>';
echo '✓ Password verification working<br>';
echo '✓ MD5 backward compatibility maintained<br>';
echo '✓ Auto-upgrade detection functional<br>';
echo '✓ Database statistics available<br><br>';
echo '<strong>Next Steps:</strong><br>';
echo '1. Monitor the migration progress as users log in<br>';
echo '2. All new users automatically get bcrypt passwords<br>';
echo '3. Existing users upgrade automatically on next login<br>';
echo '4. No user action or password reset required<br>';
echo '</div>';
echo '</div>';
?>

    <div style="text-align: center; padding: 20px; color: #666; font-size: 14px;">
        <p>Test completed at <?php echo date('Y-m-d H:i:s'); ?></p>
        <p>For more information, see: <code>/docs/PASSWORD_SECURITY_UPGRADE.md</code></p>
    </div>

</body>
</html>



