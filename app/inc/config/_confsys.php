<?php

// -----------------------------------------------------
// This is the core and central include file for the synaplan system setup
// -----------------------------------------------------

require_once __DIR__ . '/_confkeys.php';

// It starts with some basic global variables and logic
if (isset($_REQUEST['logout']) && $_REQUEST['logout'] == 'true') {
    unset($_SESSION['USERPROFILE']);
    session_destroy();
    header('Location: index.php');
    exit;
}

// GLOBALS CONFIGS
if (isset($_SERVER['SCRIPT_NAME'])) {
    $scriptname = basename($_SERVER['SCRIPT_NAME']);
} else {
    $scriptname = 'cli';
}
// -----------------------------------------------------
if (isset($_SERVER['SERVER_NAME'])) {
    $server = $_SERVER['SERVER_NAME'];
} else {
    $server = 'cli';
}
// -----------------------------------------------------
if (isset($_SERVER['REQUEST_URI'])) {
    $uri = $_SERVER['REQUEST_URI'];
} else {
    $uri = 'cli';
}
// -----------------------------------------------------

$GLOBALS['debug'] = ApiKeys::get('APP_DEBUG') === 'true';
$GLOBALS['baseUrl'] = ApiKeys::get('APP_URL');
if (!isset($GLOBALS['baseUrl'])) {
    throw new \RuntimeException('You must set the APP_URL (via environment variable or in the .env file) to the base URL of your Synaplan installation, e.g. http://localhost:8080');
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
