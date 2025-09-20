<?php 
/* 
    API for synaplan.com. Serving as a bridge between the frontend and the backend.
    Design with a bearer token authentication. Bearer token is the session id.
    The bearer Auth token is saved in the database for each user.
*/
# https://github.com/logiscapedev/mcp-sdk-php work with that, when called the MCP way
// Set execution time limit to 6 minutes
set_time_limit(360);
// Ensure session cookies are sent from widget iframes: SameSite=None; Secure when HTTPS
// Robust HTTPS detection: X-Forwarded-Proto or baseUrl prefix
$forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
$baseHttps = isset($GLOBALS['baseUrl']) && strpos($GLOBALS['baseUrl'], 'https://') === 0;
$isHttps = ($forwardedProto === 'https') ||
           (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
           $baseHttps;
if (function_exists('session_set_cookie_params')) {
    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps ? true : false,
        'httponly' => true,
        'samesite' => 'None'
    ];
    @session_set_cookie_params($cookieParams);
}
session_start();
// Prevent PHP warnings/notices from corrupting JSON responses
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// Use Composer autoload and new app core includes
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';
require_once __DIR__ . '/../app/inc/api/_api-openapi.php';

// ----------------------------- Bearer API key authentication
ApiAuthenticator::handleBearerAuth();

// ******************************************************
// Route request to appropriate handler
// ******************************************************
$rawPostData = file_get_contents('php://input');

if (ApiRouter::route($rawPostData)) {
    // Request was handled by router
    exit;
}

// ******************************************************
// Handle REST API requests
// ******************************************************
// Map JSON body `{ service: "...", ... }` to REST-style $_REQUEST['action']
if (!empty($rawPostData) && isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $decoded = json_decode($rawPostData, true);
    if (is_array($decoded)) {
        if (!isset($_REQUEST['action']) && isset($decoded['action'])) {
            $_REQUEST['action'] = $decoded['action'];
        }
        if (!isset($_REQUEST['action']) && isset($decoded['service'])) {
            $_REQUEST['action'] = $decoded['service'];
        }
        // Merge JSON fields into $_REQUEST for convenience
        foreach ($decoded as $k => $v) {
            if (!isset($_REQUEST[$k])) {
                $_REQUEST[$k] = $v;
            }
        }
    }
}

header('Content-Type: application/json; charset=UTF-8');

try {
    $apiAction = $_REQUEST['action'] ?? '';

    // Debug logging
    ApiAuthenticator::logSessionDebugInfo();
    if ($GLOBALS["debug"]) {
        error_log("API Action: " . $apiAction);
    }

    // Check if action is allowed for current session
    ApiAuthenticator::isActionAllowed($apiAction);

    // ------------------------------------------------------ API OPTIONS --------------------
    // Take form post of user message and files and save to database
    // give back tracking ID of the message
    require_once __DIR__ . '/../app/inc/api/_api-restcalls.php';
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => "Server error", "message" => ($GLOBALS["debug"] ?? false) ? $e->getMessage() : null]);
}