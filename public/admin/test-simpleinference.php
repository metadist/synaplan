<?php

/**
 * Test Script 2: Simple Inference Test
 *
 * Tests API inference with user ID 3's API key
 * Makes a simple prompt: "what is the weather in usbekistan?"
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
    'test' => 'Simple Inference',
    'user_id' => 3,
    'prompt' => 'what is the weather in uzbekistan?',
    'api_key' => '',
    'response' => '',
    'error' => '',
    'timestamp' => date('Y-m-d H:i:s'),
    'response_time_ms' => 0
];

// Initialize HTML report
$reportFile = __DIR__ . '/test-report.html';
$testStartTime = microtime(true);

try {
    // Step 1: Get API key for user ID 3
    $apiKeySQL = "SELECT BKEY FROM BAPIKEYS WHERE BOWNERID = 3 AND BSTATUS = 'active' ORDER BY BID DESC LIMIT 1";
    $apiKeyRes = db::Query($apiKeySQL);
    $apiKeyArr = db::FetchArr($apiKeyRes);

    if (!$apiKeyArr || empty($apiKeyArr['BKEY'])) {
        $testResult['error'] = 'No active API key found for user ID 3';
        echo json_encode($testResult);

        // Log to report
        appendToReport($reportFile, $testResult, microtime(true) - $testStartTime);
        exit;
    }

    $apiKey = $apiKeyArr['BKEY'];
    $testResult['api_key'] = substr($apiKey, 0, 20) . '...';

    // Step 2: Make API call
    $apiUrl = $GLOBALS['baseUrl'] . 'api.php';
    $prompt = 'what is the weather in uzbekistan?';

    $postData = [
        'action' => 'messageNew',
        'message' => $prompt
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $apiStartTime = microtime(true);
    $response = curl_exec($ch);
    $responseTime = round((microtime(true) - $apiStartTime) * 1000, 2);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $testResult['response_time_ms'] = $responseTime;

    if ($curlError) {
        $testResult['error'] = 'cURL error: ' . $curlError;
        echo json_encode($testResult);

        // Log to report
        appendToReport($reportFile, $testResult, microtime(true) - $testStartTime);
        exit;
    }

    if ($httpCode !== 200) {
        $testResult['error'] = 'API returned HTTP ' . $httpCode . ': ' . substr($response, 0, 200);
        echo json_encode($testResult);

        // Log to report
        appendToReport($reportFile, $testResult, microtime(true) - $testStartTime);
        exit;
    }

    $decoded = json_decode($response, true);

    if (!$decoded) {
        $testResult['error'] = 'Invalid JSON response: ' . substr($response, 0, 200);
        echo json_encode($testResult);

        // Log to report
        appendToReport($reportFile, $testResult, microtime(true) - $testStartTime);
        exit;
    }

    // Check if we have a successful response
    if (isset($decoded['error'])) {
        $testResult['error'] = 'API error: ' . $decoded['error'];
        $testResult['response'] = json_encode($decoded, JSON_PRETTY_PRINT);
    } else {
        $testResult['result'] = true;
        $testResult['response'] = json_encode($decoded, JSON_PRETTY_PRINT);
        $testResult['message'] = 'API inference completed successfully';
    }

} catch (\Throwable $e) {
    $testResult['error'] = 'Exception: ' . $e->getMessage();
}

// Calculate test duration
$testDuration = round((microtime(true) - $testStartTime) * 1000, 2);

// Log to report
appendToReport($reportFile, $testResult, $testDuration);

// Output JSON result
echo json_encode($testResult, JSON_PRETTY_PRINT);

/**
 * Append test result to HTML report
 */
function appendToReport($reportFile, $testResult, $testDuration)
{
    $statusClass = $testResult['result'] ? 'status-success' : 'status-failed';
    $statusText = $testResult['result'] ? 'Passed' : 'Failed';

    $reportHtml = '
    <div class="test-section">
        <div class="test-header">
            <div class="test-title">Test 2: Simple Inference</div>
            <div class="status-badge ' . $statusClass . '">' . $statusText . '</div>
        </div>
        <div class="test-details">
            <div class="detail-row">
                <div class="detail-label">Timestamp:</div>
                <div class="detail-value">' . htmlspecialchars($testResult['timestamp']) . '</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Total Duration:</div>
                <div class="detail-value">' . $testDuration . ' ms</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">API Response Time:</div>
                <div class="detail-value">' . $testResult['response_time_ms'] . ' ms</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">User ID:</div>
                <div class="detail-value"><code>' . htmlspecialchars($testResult['user_id']) . '</code></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">API Key:</div>
                <div class="detail-value"><code>' . htmlspecialchars($testResult['api_key']) . '</code></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Prompt:</div>
                <div class="detail-value"><code>' . htmlspecialchars($testResult['prompt']) . '</code></div>
            </div>
';

    if (!empty($testResult['response'])) {
        $reportHtml .= '
            <div class="detail-row">
                <div class="detail-label">API Response:</div>
                <div class="detail-value"><pre style="background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto;">' . htmlspecialchars(substr($testResult['response'], 0, 500)) . (strlen($testResult['response']) > 500 ? '...' : '') . '</pre></div>
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
