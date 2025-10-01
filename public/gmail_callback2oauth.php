<?php

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/inc/_oauth.php');

session_start();

try {
    // Create Google client with OAuth configuration
    $client = OAuthConfig::createGoogleClient('https://wa.metadist.de/gmail_callback2oauth.php');

    // Exchange the auth code for an access token and refresh token
    if (isset($_GET['code'])) {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (isset($token['error'])) {
            throw new Exception('Error: ' . $token['error_description']);
        }

        // Save the token using OAuthConfig
        if (!OAuthConfig::saveGmailToken($token)) {
            throw new Exception('Failed to save OAuth token');
        }

        // Redirect or inform the user the process is complete
        echo 'Authorization successful. Token stored.';
        exit;
    } else {
        echo 'No authorization code received.';
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
    exit;
}
