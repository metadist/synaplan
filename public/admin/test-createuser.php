<?php

/**
 * Test Script 1: Create Test User
 *
 * Creates user ID 3 with email team@synaplan.com and password "testing"
 * Generates an API key for the user
 * Returns JSON result and logs to HTML report
 */

// Initialize session and includes
session_start();
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/inc/_coreincludes.php';

// Set response header
header('Content-Type: application/json; charset=UTF-8');

// Initialize test result
$testResult = [
    'result' => false,
    'test' => 'Create User',
    'user_id' => 0,
    'email' => '',
    'api_key' => '',
    'error' => '',
    'timestamp' => date('Y-m-d H:i:s')
];

// Initialize HTML report
$reportFile = __DIR__ . '/test-report.html';
$testStartTime = microtime(true);

try {
    // Step 1: Check if user ID 3 already exists
    $checkSQL = 'SELECT BID FROM BUSER WHERE BID = 3';
    $checkRes = db::Query($checkSQL);
    $existingUser = db::FetchArr($checkRes);

    if ($existingUser) {
        // Delete existing user ID 3 first
        $deleteResult = UserRegistration::deleteUserCompletely(3);
        if (!$deleteResult['success']) {
            $testResult['error'] = 'Failed to delete existing user ID 3: ' . $deleteResult['error'];
            echo json_encode($testResult);
            exit;
        }
    }

    // Step 2: Create user with specific ID 3
    $email = 'team@synaplan.com';
    $password = 'testing';
    $passwordHash = PasswordHelper::hash($password);

    // Create user details JSON
    $userDetails = [
        'firstName' => 'Test',
        'lastName' => 'User',
        'phone' => '',
        'companyName' => 'Synaplan Test',
        'vatId' => '',
        'street' => '',
        'zipCode' => '',
        'city' => '',
        'country' => '',
        'language' => 'en',
        'timezone' => '',
        'invoiceEmail' => '',
        'emailConfirmed' => true,
        'testUser' => true
    ];

    // Insert user with specific ID 3
    // First, we need to check if we can insert with specific ID
    $insertSQL = "INSERT INTO BUSER (BID, BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID, BUSERLEVEL, BUSERDETAILS) 
                 VALUES (3, '" . date('YmdHis') . "', 'MAIL', '" . db::EscString($email) . "', '" . db::EscString($passwordHash) . "', '" . db::EscString($email) . "', 'NEW', '" . db::EscString(json_encode($userDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "')";

    db::Query($insertSQL);
    $newUserId = db::LastId();

    // Verify the user was created with ID 3
    if ($newUserId != 3) {
        // If auto-increment didn't give us 3, try to fix it
        $verifySQL = "SELECT BID FROM BUSER WHERE BMAIL = '" . db::EscString($email) . "' LIMIT 1";
        $verifyRes = db::Query($verifySQL);
        $verifyUser = db::FetchArr($verifyRes);

        if ($verifyUser && $verifyUser['BID'] == 3) {
            $newUserId = 3;
        } else {
            $testResult['error'] = 'User created but not with ID 3 (got ID ' . $newUserId . ')';
            $testResult['user_id'] = $newUserId;
        }
    }

    if ($newUserId <= 0) {
        $testResult['error'] = 'Failed to create user';
        echo json_encode($testResult);
        exit;
    }

    $testResult['user_id'] = $newUserId;
    $testResult['email'] = $email;

    // Step 3: Create API key for the user
    $random = bin2hex(random_bytes(24));
    $apiKey = 'sk_live_' . $random;
    $now = time();

    $insertKeySQL = 'INSERT INTO BAPIKEYS (BOWNERID, BNAME, BKEY, BSTATUS, BCREATED, BLASTUSED) 
                     VALUES (' . $newUserId . ", 'Test API Key', '" . db::EscString($apiKey) . "', 'active', " . $now . ', 0)';

    db::Query($insertKeySQL);
    $apiKeyId = db::LastId();

    if ($apiKeyId > 0) {
        $testResult['result'] = true;
        $testResult['api_key'] = $apiKey;
        $testResult['message'] = 'User created successfully with API key';
    } else {
        $testResult['error'] = 'User created but API key creation failed';
    }
} catch (\Throwable $e) {
    $testResult['error'] = 'Exception: ' . $e->getMessage();
}

// Calculate test duration
$testDuration = round((microtime(true) - $testStartTime) * 1000, 2);

// Create HTML report
$reportHtml = '';

// If this is the first test, create the report header
if (!file_exists($reportFile)) {
    $reportHtml .= '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Synaplan Test Report</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            max-width: 1200px;
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
        .header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
        }
        .test-section {
            background: white;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .test-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        .test-title {
            font-size: 24px;
            font-weight: 600;
            color: #2d3748;
        }
        .status-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
        }
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        .test-details {
            margin-top: 15px;
        }
        .detail-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .detail-label {
            font-weight: 600;
            width: 200px;
            color: #555;
        }
        .detail-value {
            flex: 1;
            color: #333;
            word-break: break-all;
        }
        .error-message {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-top: 15px;
            border-radius: 4px;
            color: #856404;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 14px;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: "Courier New", monospace;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🧪 Synaplan Test Report</h1>
        <p>Automated testing suite execution report</p>
        <p><strong>Started:</strong> ' . date('Y-m-d H:i:s') . '</p>
    </div>
';
}

// Add test section
$statusClass = $testResult['result'] ? 'status-success' : 'status-failed';
$statusText = $testResult['result'] ? 'Passed' : 'Failed';

$reportHtml .= '
    <div class="test-section">
        <div class="test-header">
            <div class="test-title">Test 1: Create User</div>
            <div class="status-badge ' . $statusClass . '">' . $statusText . '</div>
        </div>
        <div class="test-details">
            <div class="detail-row">
                <div class="detail-label">Timestamp:</div>
                <div class="detail-value">' . htmlspecialchars($testResult['timestamp']) . '</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Duration:</div>
                <div class="detail-value">' . $testDuration . ' ms</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">User ID:</div>
                <div class="detail-value"><code>' . htmlspecialchars($testResult['user_id']) . '</code></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Email:</div>
                <div class="detail-value"><code>' . htmlspecialchars($testResult['email']) . '</code></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">API Key:</div>
                <div class="detail-value"><code>' . htmlspecialchars(substr($testResult['api_key'], 0, 20)) . '...</code></div>
            </div>
';

if (!empty($testResult['error'])) {
    $reportHtml .= '
        </div>
        <div class="error-message">
            <strong>Error:</strong> ' . htmlspecialchars($testResult['error']) . '
        </div>
';
} else {
    $reportHtml .= '</div>';
}

$reportHtml .= '
    </div>
';

// Write to report file (append mode)
file_put_contents($reportFile, $reportHtml, FILE_APPEND);

// Output JSON result
echo json_encode($testResult, JSON_PRETTY_PRINT);
