<?php

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/inc/_confsys.php');
require_once(__DIR__ . '/inc/_oauth.php');

echo "=== OAuth Configuration Test ===\n\n";

// Test Google OAuth credentials
$oauthCreds = ApiKeys::get('GOOGLE_OAUTH_CREDENTIALS');
echo "1. Google OAuth Credentials:\n";
echo '   Raw value: ' . ($oauthCreds ? 'Loaded' : 'Not loaded') . "\n";

$parsedCreds = OAuthConfig::getGoogleCredentials();
echo '   Parsed value: ' . ($parsedCreds ? 'Valid JSON' : 'Invalid JSON') . "\n";
if (!$parsedCreds && $oauthCreds) {
    $error = json_last_error_msg();
    echo '   JSON Error: ' . $error . "\n";
}

// Test Gmail OAuth token
$gmailToken = ApiKeys::get('GMAIL_OAUTH_TOKEN');
echo "\n2. Gmail OAuth Token:\n";
echo '   Raw value: ' . ($gmailToken ? 'Loaded' : 'Not loaded') . "\n";

$parsedToken = OAuthConfig::getGmailToken();
echo '   Parsed value: ' . ($parsedToken ? 'Valid JSON' : 'Invalid JSON') . "\n";
if (!$parsedToken && $gmailToken) {
    $error = json_last_error_msg();
    echo '   JSON Error: ' . $error . "\n";
}

// Validate complete OAuth configuration
echo "\n3. Complete OAuth Configuration:\n";
$issues = OAuthConfig::validateConfig();
if (empty($issues)) {
    echo "   ✅ All OAuth configurations are valid\n";
} else {
    echo "   ❌ Issues found:\n";
    foreach ($issues as $issue) {
        echo '   - ' . $issue . "\n";
    }
}

// Show environment variables (without sensitive data)
echo "\n4. Environment Variables Status:\n";
echo '   GOOGLE_OAUTH_CREDENTIALS: ' . (getenv('GOOGLE_OAUTH_CREDENTIALS') ? 'Set' : 'Not set') . "\n";
echo '   GMAIL_OAUTH_TOKEN: ' . (getenv('GMAIL_OAUTH_TOKEN') ? 'Set' : 'Not set') . "\n";

// Show file status
echo "\n5. Legacy Files Status:\n";
$legacyFiles = [
    'secret1_ralfsai.json' => __DIR__ . '/.keys/secret1_ralfsai.json',
    'gmailtoken.json' => __DIR__ . '/.keys/gmailtoken.json'
];

foreach ($legacyFiles as $name => $path) {
    echo "   $name: " . (file_exists($path) ? 'Exists' : 'Not found') . "\n";
}
