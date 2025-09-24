<?php

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/inc/_oauth.php');

session_start();

try {
    // Create Google client with OAuth configuration
    $client = OAuthConfig::createGoogleClient('https://wa.metadist.de/callback2oauth.php');

    // Generate the auth URL
    $authUrl = $client->createAuthUrl();

    // Redirect the user to Google's OAuth page
    header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
    exit;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
    exit;
}
