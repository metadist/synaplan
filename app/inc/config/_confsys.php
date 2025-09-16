<?php
// -----------------------------------------------------
// This is the core and central include file for the synaplan system setup
// -----------------------------------------------------

// It starts with some basic global variables and logic
if(isset($_REQUEST["logout"]) && $_REQUEST["logout"]=="true") {
    unset($_SESSION["USERPROFILE"]);
    session_destroy();
    header("Location: index.php");
    exit;
}

// GLOBALS CONFIGS
if(isset($_SERVER["SCRIPT_NAME"])) {
    $scriptname = basename($_SERVER["SCRIPT_NAME"]);
} else {
    $scriptname = "cli";
}
// -----------------------------------------------------
if(isset($_SERVER["SERVER_NAME"])) {
    $server = $_SERVER["SERVER_NAME"];
} else {
    $server = "cli";
}
// -----------------------------------------------------
if(isset($_SERVER["REQUEST_URI"])) {
    $uri = $_SERVER["REQUEST_URI"];
} else {
    $uri = "cli";
}
// -----------------------------------------------------

// ----------------------------------------------------- LIVE
$liveUrl = "https://app.synaplan.com/";

// ----------------------------------------------------- DEV
$devUrl = "http://localhost/synaplan/public/";
$baseUrl = "";

// ----------------------------------------------------- set right url
// ----------------------------------------------------- storage path

// Check if we're running in Docker environment
$isDocker = false;
if (file_exists('/.dockerenv') || 
    (isset($_SERVER['HOSTNAME']) && strpos($_SERVER['HOSTNAME'], 'synaplan-') === 0) ||
    (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development')) {
    $isDocker = true;
}

// Check if we're on localhost (including with port)
$isLocalhost = (substr_count($server, "localhost") > 0) || 
               (substr_count($server, "127.0.0.1") > 0) ||
               (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);

if ($isLocalhost || $isDocker) {
    // If running in Docker or localhost, use the current host and port
    if ($isDocker) {
        // In Docker, use the mapped port from HTTP_HOST
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost:8080';
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $GLOBALS["baseUrl"] = $protocol . "://" . $host . "/";
    } else {
        // Regular localhost development
        $GLOBALS["baseUrl"] = $devUrl;
    }
    $GLOBALS["debug"] = true;
} else {
    $GLOBALS["baseUrl"] = $liveUrl;
    $GLOBALS["debug"] = false;
}

// -----------------------------------------------------
// Path constants (minimal, environment-agnostic)
// These are safe to include in any context (CLI, Docker, local)
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', PROJECT_ROOT . '/public');
}
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', PUBLIC_PATH . '/up');
}
