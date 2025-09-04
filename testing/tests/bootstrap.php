<?php
/**
 * PHPUnit Bootstrap File
 * 
 * Sets up the testing environment for unit tests
 */

// Load Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

// Set up test environment
define('TESTING', true);

// Mock global variables for testing
$GLOBALS["debug"] = true;

// Set baseUrl dynamically based on environment
if (!isset($GLOBALS["baseUrl"])) {
    if (isset($_SERVER['HTTP_HOST'])) {
        // Web environment - use actual host
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $GLOBALS["baseUrl"] = $protocol . $_SERVER['HTTP_HOST'] . '/';
    } elseif (getenv('BASE_URL')) {
        // Environment variable
        $GLOBALS["baseUrl"] = getenv('BASE_URL');
    } else {
        // Fallback for CLI/testing
        $GLOBALS["baseUrl"] = "http://localhost:8080/";
    }
}

// Mock _mymail function for unit tests only (avoid conflicts with real function)
if (!function_exists('_mymail') && (getenv('PHPUNIT_TESTSUITE') === 'Unit' || getenv('MOCK_MAIL') === '1')) {
    function _mymail($from, $to, $subject, $html, $plain, $replyTo = '', $attachment = '') {
        return true;
    }
}

// Mock DB class for testing (only for unit tests)
if (!class_exists('DB') && (!defined('TESTING') || !TESTING)) {
    class DB {
        public static function EscString($str) {
            return $str; // Simple mock
        }
        
        public static function Query($sql) {
            return true; // Mock success
        }
        
        public static function FetchArr($res) {
            return null; // Mock no results
        }
        
        public static function LastId() {
            return 12345; // Mock ID
        }
    }
}

// For integration tests, load real DB class only if mysqli is available
if (defined('TESTING') && TESTING && function_exists('mysqli_connect') && getenv('USE_REAL_DB') === '1') {
    // Set server variable to avoid undefined variable warning
    $server = 'localhost';
    
    // Load real database configuration for integration tests
    if (file_exists(__DIR__ . '/../../public/inc/_confdb.php')) {
        require_once __DIR__ . '/../../public/inc/_confdb.php';
    }
}
