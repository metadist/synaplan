<?php

/**
 * Test Script 3: Delete User
 *
 * Deletes user ID 3 completely with all associated data
 * Closes HTML report and sends it via email to team@synaplan.com
 * Returns JSON result
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
    'test' => 'Delete User',
    'user_id' => 3,
    'deletion_log' => [],
    'email_sent' => false,
    'error' => '',
    'timestamp' => date('Y-m-d H:i:s')
];

// Initialize HTML report
$reportFile = __DIR__ . '/test-report.html';
$testStartTime = microtime(true);

try {
    // Step 1: Delete user ID 3 completely
    $deleteResult = UserRegistration::deleteUserCompletely(3);

    if (!$deleteResult['success']) {
        $testResult['error'] = 'User deletion failed: ' . ($deleteResult['error'] ?? 'Unknown error');
        $testResult['deletion_log'] = $deleteResult['log'] ?? [];
    } else {
        $testResult['result'] = true;
        $testResult['deletion_log'] = $deleteResult['log'] ?? [];
        $testResult['message'] = 'User deleted successfully';
    }

    // Step 2: Reset BUSELOG counts for user ID 3
    try {
        // Count BUSELOG entries
        $countSQL = 'SELECT COUNT(*) as cnt FROM BUSELOG WHERE BUSERID = 3';
        $countRes = db::Query($countSQL);
        $countArr = db::FetchArr($countRes);
        $deletedBuselogCount = $countArr['cnt'] ?? 0;

        // Delete BUSELOG entries
        $deleteSQL = 'DELETE FROM BUSELOG WHERE BUSERID = 3';
        db::Query($deleteSQL);

        $testResult['deletion_log'][] = "Deleted {$deletedBuselogCount} BUSELOG entries for user ID 3";
        $testResult['buselog_deleted'] = $deletedBuselogCount;
    } catch (\Throwable $e) {
        $testResult['deletion_log'][] = 'BUSELOG deletion error: ' . $e->getMessage();
        $testResult['buselog_error'] = $e->getMessage();
    }
} catch (\Throwable $e) {
    $testResult['error'] = 'Exception: ' . $e->getMessage();
}

// Calculate test duration
$testDuration = round((microtime(true) - $testStartTime) * 1000, 2);

// Log to report
appendToReport($reportFile, $testResult, $testDuration);

// Step 2: Close HTML report
closeReport($reportFile);

// Step 3: Send email with test report
try {
    $emailRecipient = 'team@synaplan.com';
    $emailSubject = 'Synaplan Test Suite Report - ' . date('Y-m-d H:i:s');

    // Read the HTML report
    if (file_exists($reportFile)) {
        $htmlBody = file_get_contents($reportFile);

        // Create plain text version
        $plainBody = "Synaplan Test Suite Report\n\n";
        $plainBody .= 'Test execution completed at: ' . date('Y-m-d H:i:s') . "\n\n";
        $plainBody .= "Please view the attached HTML version for full details.\n\n";
        $plainBody .= "Test Results Summary:\n";
        $plainBody .= '- User Creation: ' . (file_exists($reportFile) ? 'See report' : 'Unknown') . "\n";
        $plainBody .= '- API Inference: ' . (file_exists($reportFile) ? 'See report' : 'Unknown') . "\n";
        $plainBody .= '- User Deletion: ' . ($testResult['result'] ? 'Passed' : 'Failed') . "\n";

        // Send email
        $emailSent = EmailService::sendEmail(
            $emailRecipient,
            $emailSubject,
            $htmlBody,
            $plainBody
        );

        if ($emailSent) {
            $testResult['email_sent'] = true;
            $testResult['email_recipient'] = $emailRecipient;
        } else {
            $testResult['email_error'] = 'Failed to send email';
        }
    } else {
        $testResult['email_error'] = 'Report file not found';
    }
} catch (\Throwable $e) {
    $testResult['email_error'] = 'Email exception: ' . $e->getMessage();
}

// Output JSON result
echo json_encode($testResult, JSON_PRETTY_PRINT);

/**
 * Append test result to HTML report
 */
function appendToReport($reportFile, $testResult, $testDuration) {
    $statusClass = $testResult['result'] ? 'status-success' : 'status-failed';
    $statusText = $testResult['result'] ? 'Passed' : 'Failed';

    $reportHtml = '
    <div class="test-section">
        <div class="test-header">
            <div class="test-title">Test 3: Delete User</div>
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
';

    if (!empty($testResult['deletion_log'])) {
        $reportHtml .= '
            <div class="detail-row">
                <div class="detail-label">Deletion Log:</div>
                <div class="detail-value">
                    <ul style="margin: 0; padding-left: 20px;">
';
        foreach ($testResult['deletion_log'] as $logEntry) {
            $reportHtml .= '                        <li>' . htmlspecialchars($logEntry) . '</li>' . "\n";
        }
        $reportHtml .= '
                    </ul>
                </div>
            </div>
';
    }

    $reportHtml .= '</div>';

    if (!empty($testResult['error'])) {
        $reportHtml .= '
        <div class="error-message">
            <strong>Error:</strong> ' . htmlspecialchars($testResult['error']) . '
        </div>
';
    }

    $reportHtml .= '
    </div>
';

    // Write to report file (append mode)
    file_put_contents($reportFile, $reportHtml, FILE_APPEND);
}

/**
 * Close HTML report with summary section
 */
function closeReport($reportFile) {
    $completedTime = date('Y-m-d H:i:s');

    // Add summary section
    $reportHtml = '
    <div class="test-section" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
        <div class="test-header" style="border-bottom: 2px solid rgba(255,255,255,0.3);">
            <div class="test-title" style="color: white;">📊 Test Suite Summary</div>
        </div>
        <div class="test-details">
            <div class="detail-row">
                <div class="detail-label" style="color: rgba(255,255,255,0.9);">Completed:</div>
                <div class="detail-value" style="color: white;"><strong>' . $completedTime . '</strong></div>
            </div>
            <div class="detail-row">
                <div class="detail-label" style="color: rgba(255,255,255,0.9);">Total Tests:</div>
                <div class="detail-value" style="color: white;"><strong>3</strong></div>
            </div>
            <div class="detail-row">
                <div class="detail-label" style="color: rgba(255,255,255,0.9);">Test Scope:</div>
                <div class="detail-value" style="color: white;">User Creation, API Inference, User Deletion</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>Generated by Synaplan Automated Test Suite</p>
        <p>© ' . date('Y') . ' Synaplan - metadist GmbH</p>
    </div>

</body>
</html>';

    // Append closing HTML
    file_put_contents($reportFile, $reportHtml, FILE_APPEND);
}
