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
// core app files with relative paths
$root = __DIR__ . '/';
require_once($root . '/inc/_coreincludes.php');
require_once($root . '/inc/_api-openapi.php');

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

require_once($root . '/inc/_api_restcalls.php');