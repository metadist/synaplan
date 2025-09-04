<?php 
/* 
    API for synaplan.com. Serving as a bridge between the frontend and the backend.
    Design with a bearer token authentication. Bearer token is the session id.
    The bearer Auth token is saved in the database for each user.
*/
# https://github.com/logiscapedev/mcp-sdk-php work with that, when called the MCP way
// Set execution time limit to 6 minutes
set_time_limit(360);
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