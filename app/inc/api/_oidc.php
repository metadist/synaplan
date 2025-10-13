<?php

/**
 * OIDC Authentication Class
 *
 * Handles OpenID Connect authentication flow integration with the existing
 * Synaplan authentication system.
 *
 * @package OIDC
 */

use Jumbojett\OpenIDConnectClient;

class OidcAuth
{
    private static $client = null;

    /**
     * Initialize OIDC client
     */
    private static function initClient()
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $providerUrl = ApiKeys::getOidcProviderUrl();
        $clientId = ApiKeys::getOidcClientId();
        $clientSecret = ApiKeys::getOidcClientSecret();

        // Validate that all required values are non-empty strings
        if (empty($providerUrl) || !is_string($providerUrl)) {
            throw new Exception('OIDC_PROVIDER_URL must be set to a valid URL');
        }
        if (empty($clientId) || !is_string($clientId)) {
            throw new Exception('OIDC_CLIENT_ID must be set to a valid string');
        }
        if (empty($clientSecret) || !is_string($clientSecret)) {
            throw new Exception('OIDC_CLIENT_SECRET must be set to a valid string');
        }

        self::$client = new OpenIDConnectClient($providerUrl, $clientId, $clientSecret);

        // Conditionally disable SSL verification based on environment
        $sslVerify = ApiKeys::get('OIDC_SSL_VERIFY');
        if ($sslVerify === 'false' ||
            (empty($sslVerify) && ApiKeys::get('APP_ENV') === 'development')) {
            self::$client->setVerifyHost(false);
            self::$client->setVerifyPeer(false);
        }

        // Set redirect URI
        $redirectUri = ApiKeys::getOidcRedirectUri();
        if (!empty($redirectUri) && is_string($redirectUri)) {
            self::$client->setRedirectURL($redirectUri);
        }

        // Set scopes
        $scopes = explode(' ', ApiKeys::getOidcScopes());
        $scopesArray = array_map('trim', $scopes);
        self::$client->addScope($scopesArray);

        return self::$client;
    }

    /**
     * Start OIDC authentication flow
     */
    public static function initiateAuth()
    {
        try {
            $client = self::initClient();
            $client->authenticate();
        } catch (Exception $e) {
            error_log('OIDC authentication error: ' . $e->getMessage());
            $_SESSION['oidc_error'] = 'OIDC configuration error: ' . $e->getMessage();

            // Clean any output buffer and redirect
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Location: index.php');
            exit;
        }

        return true;
    }

    /**
     * Handle OIDC callback and create/login user
     */
    public static function handleCallback()
    {
        try {
            $client = self::initClient();

            // Verify the authentication
            if (!$client->authenticate()) {
                error_log('OIDC: Authentication failed - client->authenticate() returned false');
                $_SESSION['oidc_error'] = 'OIDC authentication verification failed.';
                return false;
            }

            // Get user info from the OIDC provider
            $userInfo = $client->requestUserInfo();

            if (!$userInfo) {
                error_log('OIDC: Failed to get user info from provider');
                $_SESSION['oidc_error'] = 'Failed to retrieve user information from provider.';
                return false;
            }

            if (!isset($userInfo->email)) {
                error_log('OIDC: No email in user info. User info: ' . json_encode($userInfo));
                $_SESSION['oidc_error'] = 'No email address provided by the authentication provider.';
                return false;
            }

            // Get or create user in database using Central
            $user = self::getOrCreateUserFromOidc($userInfo);

            if ($user) {
                // Set session
                $_SESSION['USERPROFILE'] = $user;
                // Clear any leftover anonymous widget session variables on login
                Frontend::clearWidgetSession();
                Frontend::$AIdetailArr['GMAIL'] = substr($user['BMAIL'], 0, strpos($user['BMAIL'], '@'));
                error_log('OIDC: Successfully authenticated user: ' . $user['BMAIL']);
                return true;
            } else {
                error_log('OIDC: Failed to create or retrieve user from database');
                $_SESSION['oidc_error'] = 'Failed to create or retrieve user account.';
                return false;
            }

        } catch (Exception $e) {
            error_log('OIDC callback error: ' . $e->getMessage());
            $_SESSION['oidc_error'] = 'OIDC callback error: ' . $e->getMessage();
        }

        return false;
    }

    /**
     * Get existing user or create new user from OIDC info using Central functions
     */
    private static function getOrCreateUserFromOidc($userInfo)
    {
        $email = $userInfo->email;

        // Use Central::getUserByMail() - it handles both finding and creating users
        $user = Central::getUserByMail($email, '', true);

        if ($user) {
            // Update user to mark as OIDC type if not already
            if ($user['BINTYPE'] !== 'OIDC') {
                $updateSQL = "UPDATE BUSER SET BINTYPE = 'OIDC', BPROVIDERID = 'OIDC' WHERE BID = " . $user['BID'];
                db::Query($updateSQL);
                $user['BINTYPE'] = 'OIDC';
                $user['BPROVIDERID'] = 'OIDC';
            }

            // Update user details with OIDC information
            $user = self::updateUserDetailsFromOidc($user, $userInfo);

            return $user;
        }

        return false;
    }

    /**
     * Update user details from OIDC information
     */
    private static function updateUserDetailsFromOidc($user, $userInfo)
    {
        $currentDetails = $user['DETAILS'] ?? [];

        // Update with OIDC information (only if not already set)
        if (empty($currentDetails['firstName']) && isset($userInfo->given_name)) {
            $currentDetails['firstName'] = $userInfo->given_name;
        }
        if (empty($currentDetails['lastName']) && isset($userInfo->family_name)) {
            $currentDetails['lastName'] = $userInfo->family_name;
        }
        if (empty($currentDetails['phone']) && isset($userInfo->phone_number)) {
            $currentDetails['phone'] = $userInfo->phone_number;
        }
        if (empty($currentDetails['language']) && isset($userInfo->locale)) {
            $currentDetails['language'] = substr($userInfo->locale, 0, 2);
        }
        if (empty($currentDetails['timezone']) && isset($userInfo->zoneinfo)) {
            $currentDetails['timezone'] = $userInfo->zoneinfo;
        }
        // Store OIDC subject for reference
        if (isset($userInfo->sub)) {
            $currentDetails['oidc_subject'] = $userInfo->sub;
        }

        // Update database using Central helper function
        Central::updateUserDetails($user['BID'], $currentDetails);

        // Update the user array and return it
        $user['BUSERDETAILS'] = json_encode($currentDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $user['DETAILS'] = $currentDetails;

        return $user;
    }

    /**
     * Check if OIDC is configured
     */
    public static function isConfigured()
    {
        return ApiKeys::getOidcProviderUrl() &&
               ApiKeys::getOidcClientId() &&
               ApiKeys::getOidcClientSecret();
    }

    /**
     * Check if auto-redirect to IDP is enabled
     */
    public static function isAutoRedirectEnabled()
    {
        $autoRedirect = ApiKeys::get('OIDC_AUTO_REDIRECT');
        // Default to true (enabled) unless explicitly set to 'false'
        return $autoRedirect !== 'false';
    }

    /**
     * Get OIDC login URL
     */
    public static function getLoginUrl()
    {
        if (!self::isConfigured()) {
            return null;
        }

        try {
            $client = self::initClient();
            return $client->getAuthorizationURL();
        } catch (Exception $e) {
            error_log('OIDC get login URL error: ' . $e->getMessage());
            return null;
        }
    }
}
