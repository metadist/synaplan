<?php

/**
 * OAuthConfig Class
 * 
 * Handles OAuth credentials and tokens for various services including Google OAuth
 * and Gmail OAuth. Uses environment variables for secure credential management.
 * 
 * @package OAuthConfig
 */
class OAuthConfig {
    private static $initialized = false;
    private static $googleCredentials = null;
    private static $gmailToken = null;

    /**
     * Initialize the OAuth configuration
     * Loads credentials from environment variables or falls back to legacy files
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }

        // Load Google OAuth credentials
        $googleCredsJson = getenv('GOOGLE_OAUTH_CREDENTIALS');
        if ($googleCredsJson) {
            self::$googleCredentials = json_decode($googleCredsJson, true);
        } else {
            // Fallback to legacy file
            $legacyPath = __DIR__ . '/../.keys/secret1_ralfsai.json';
            if (file_exists($legacyPath)) {
                $content = file_get_contents($legacyPath);
                if ($content !== false) {
                    self::$googleCredentials = json_decode($content, true);
                }
            }
        }

        // Load Gmail OAuth token
        $gmailTokenJson = getenv('GMAIL_OAUTH_TOKEN');
        if ($gmailTokenJson) {
            self::$gmailToken = json_decode($gmailTokenJson, true);
        } else {
            // Fallback to legacy file
            $legacyPath = __DIR__ . '/../.keys/gmailtoken.json';
            if (file_exists($legacyPath)) {
                $content = file_get_contents($legacyPath);
                if ($content !== false) {
                    self::$gmailToken = json_decode($content, true);
                }
            }
        }

        self::$initialized = true;
    }

    /**
     * Get Google OAuth credentials
     * 
     * @return array|null Google OAuth credentials or null if not configured
     */
    public static function getGoogleCredentials() {
        self::init();
        return self::$googleCredentials;
    }

    /**
     * Get Gmail OAuth token
     * 
     * @return array|null Gmail OAuth token or null if not configured
     */
    public static function getGmailToken() {
        self::init();
        return self::$gmailToken;
    }

    /**
     * Save Gmail OAuth token
     * 
     * @param array $token Gmail OAuth token to save
     * @return bool True if successful
     */
    public static function saveGmailToken($token) {
        self::init();
        self::$gmailToken = $token;

        // Save to environment variable if possible
        if (putenv('GMAIL_OAUTH_TOKEN=' . json_encode($token))) {
            $_ENV['GMAIL_OAUTH_TOKEN'] = json_encode($token);
            return true;
        }

        // Fallback to legacy file
        $legacyPath = __DIR__ . '/../.keys/gmailtoken.json';
        return file_put_contents($legacyPath, json_encode($token)) !== false;
    }

    /**
     * Create a configured Google Client instance
     * 
     * @param string $redirectUri OAuth redirect URI
     * @param array $scopes Array of OAuth scopes
     * @return Google_Client Configured Google Client instance
     * @throws Exception If credentials are not configured
     */
    public static function createGoogleClient($redirectUri, $scopes = ['https://www.googleapis.com/auth/gmail.modify']) {
        self::init();
        
        if (!self::$googleCredentials) {
            throw new Exception('Google OAuth credentials not configured');
        }

        $client = new Google_Client();
        $client->setAuthConfig(self::$googleCredentials);
        $client->setRedirectUri($redirectUri);
        
        foreach ($scopes as $scope) {
            $client->addScope($scope);
        }
        
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // Set existing token if available
        if (self::$gmailToken) {
            $client->setAccessToken(self::$gmailToken);
            
            // Refresh token if expired
            if ($client->isAccessTokenExpired()) {
                if ($client->getRefreshToken()) {
                    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    self::saveGmailToken($client->getAccessToken());
                }
            }
        }

        return $client;
    }

    /**
     * Validate OAuth configuration
     * 
     * @return array Array of missing or invalid configurations
     */
    public static function validateConfig() {
        self::init();
        $issues = [];

        if (!self::$googleCredentials) {
            $issues[] = 'Google OAuth credentials not configured';
        }

        if (!self::$gmailToken) {
            $issues[] = 'Gmail OAuth token not configured';
        } elseif (isset(self::$gmailToken['expires_in']) && 
                 isset(self::$gmailToken['created']) && 
                 (time() - self::$gmailToken['created'] > self::$gmailToken['expires_in'])) {
            $issues[] = 'Gmail OAuth token expired';
        }

        return $issues;
    }
} 