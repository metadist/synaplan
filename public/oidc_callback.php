<?php
/**
 * OIDC Callback Handler
 * 
 * This file handles the OAuth/OIDC callback from the provider.
 * It should be set as the redirect URI in your OIDC provider configuration.
 */

// Start session and load core includes
session_start();
$root = __DIR__ . '/';
require_once($root . '../app/inc/_coreincludes.php');


// Handle OIDC callback
if (isset($_REQUEST['code']) || isset($_REQUEST['error'])) {
    // Handle error cases
    if (isset($_REQUEST['error'])) {
        $error = $_REQUEST['error'];
        $errorDescription = isset($_REQUEST['error_description']) ? $_REQUEST['error_description'] : '';

        error_log('OIDC callback error: ' . $error . ' - ' . $errorDescription);
        $_SESSION['oidc_error'] = 'Authentication failed: ' . htmlspecialchars($error) . 
                                  ($errorDescription ? ' - ' . htmlspecialchars($errorDescription) : '');

        // Redirect to login page
        header('Location: index.php');
        exit;
    }

    // Handle successful callback
    if (OidcAuth::handleCallback()) {
        // Successful authentication - redirect to main application
        header('Location: index.php');
        exit;
    } else {
        // Authentication failed
        $_SESSION['oidc_error'] = 'Authentication failed. Please try again.';
        header('Location: index.php');
        exit;
    }
} else {
    // No valid callback parameters - redirect to login
    $_SESSION['oidc_error'] = 'Invalid callback request.';
    header('Location: index.php');
    exit;
}