<?php

/**
 * Master Test Runner
 *
 * Runs all test scripts in sequence:
 * 1. test-createuser.php - Create test user
 * 2. test-simpleinference.php - Test API inference
 * 3. test-deleteuser.php - Delete test user and send report
 *
 * Returns combined JSON result
 */

// Initialize session and includes
session_start();
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/inc/_coreincludes.php';

// Set response header
header('Content-Type: application/json; charset=UTF-8');

// Initialize master result
$masterResult = [
    'success' => false,
    'total_tests' => 3,
    'passed' => 0,
    'failed' => 0,
    'tests' => [],
    'error' => '',
    'timestamp' => date('Y-m-d H:i:s'),
    'total_duration_ms' => 0
];

$masterStartTime = microtime(true);

// Delete old report file if exists
$reportFile = __DIR__ . '/test-report.html';
if (file_exists($reportFile)) {
    unlink($reportFile);
}

try {
    // Test 1: Create User
    echo "Running Test 1: Create User...\n";
    $test1Result = runTest('test-createuser.php');
    $masterResult['tests'][] = $test1Result;

    if ($test1Result['result']) {
        $masterResult['passed']++;
        echo "✓ Test 1 Passed\n";
    } else {
        $masterResult['failed']++;
        echo '✗ Test 1 Failed: ' . ($test1Result['error'] ?? 'Unknown error') . "\n";
    }

    // Small delay between tests
    usleep(500000); // 0.5 seconds

    // Test 2: Simple Inference
    echo "Running Test 2: Simple Inference...\n";
    $test2Result = runTest('test-simpleinference.php');
    $masterResult['tests'][] = $test2Result;

    if ($test2Result['result']) {
        $masterResult['passed']++;
        echo "✓ Test 2 Passed\n";
    } else {
        $masterResult['failed']++;
        echo '✗ Test 2 Failed: ' . ($test2Result['error'] ?? 'Unknown error') . "\n";
    }

    // Small delay between tests
    usleep(500000); // 0.5 seconds

    // Test 3: Delete User
    echo "Running Test 3: Delete User...\n";
    $test3Result = runTest('test-deleteuser.php');
    $masterResult['tests'][] = $test3Result;

    if ($test3Result['result']) {
        $masterResult['passed']++;
        echo "✓ Test 3 Passed\n";
    } else {
        $masterResult['failed']++;
        echo '✗ Test 3 Failed: ' . ($test3Result['error'] ?? 'Unknown error') . "\n";
    }

    // Calculate success
    $masterResult['success'] = ($masterResult['failed'] === 0);

    // Email info
    if (isset($test3Result['email_sent'])) {
        $masterResult['email_sent'] = $test3Result['email_sent'];
        $masterResult['email_recipient'] = $test3Result['email_recipient'] ?? '';
    }
} catch (\Throwable $e) {
    $masterResult['error'] = 'Master test runner exception: ' . $e->getMessage();
}

// Calculate total duration
$masterResult['total_duration_ms'] = round((microtime(true) - $masterStartTime) * 1000, 2);

// Output result
echo "\n" . str_repeat('=', 60) . "\n";
echo "Test Suite Summary\n";
echo str_repeat('=', 60) . "\n";
echo 'Total Tests: ' . $masterResult['total_tests'] . "\n";
echo 'Passed: ' . $masterResult['passed'] . "\n";
echo 'Failed: ' . $masterResult['failed'] . "\n";
echo 'Duration: ' . $masterResult['total_duration_ms'] . " ms\n";
echo 'Email Sent: ' . ($masterResult['email_sent'] ?? false ? 'Yes' : 'No') . "\n";
echo str_repeat('=', 60) . "\n\n";

echo json_encode($masterResult, JSON_PRETTY_PRINT);

/**
 * Run a test script and return its result
 *
 * @param string $scriptName Test script filename
 * @return array Test result
 */
function runTest(string $scriptName): array {
    $scriptPath = __DIR__ . '/' . $scriptName;

    if (!file_exists($scriptPath)) {
        return [
            'result' => false,
            'error' => 'Test script not found: ' . $scriptName
        ];
    }

    // Use output buffering to capture the script output
    ob_start();
    $testResult = include $scriptPath;
    $output = ob_get_clean();

    // Try to decode JSON output
    if (!empty($output)) {
        $decoded = json_decode($output, true);
        if ($decoded && is_array($decoded)) {
            return $decoded;
        }
    }

    // If no JSON, return generic result
    return [
        'result' => false,
        'error' => 'No valid JSON output from test script',
        'raw_output' => substr($output, 0, 500)
    ];
}
