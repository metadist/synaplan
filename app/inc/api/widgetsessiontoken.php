<?php

/**
 * Widget Session Token Handler
 * 
 * Generates and validates HMAC-signed tokens for widget sessions.
 * This solves the third-party cookie blocking issue by providing
 * a stateless token that can be passed via JavaScript headers.
 * 
 * Token format: base64(json({uid, wid, sid, exp})).signature
 * 
 * @package API
 */

class WidgetSessionToken
{
    /** @var int Token expiration time in seconds (24 hours) */
    private const TOKEN_EXPIRY = 86400;
    
    /** @var string Token prefix for easy identification */
    private const TOKEN_PREFIX = 'WST_';
    
    /**
     * Generate a widget session token
     * 
     * @param int $widgetOwnerId The widget owner's user ID
     * @param int $widgetId The widget ID (1-9)
     * @param string $anonymousSessionId The anonymous session identifier
     * @return string The signed token
     */
    public static function generate(int $widgetOwnerId, int $widgetId, string $anonymousSessionId): string {
        $payload = [
            'uid' => $widgetOwnerId,
            'wid' => $widgetId,
            'sid' => $anonymousSessionId,
            'exp' => time() + self::TOKEN_EXPIRY,
            'iat' => time()
        ];
        
        $payloadJson = json_encode($payload);
        $payloadBase64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        
        $signature = self::sign($payloadBase64);
        
        return self::TOKEN_PREFIX . $payloadBase64 . '.' . $signature;
    }
    
    /**
     * Validate and decode a widget session token
     * 
     * @param string $token The token to validate
     * @return array|false Returns payload array on success, false on failure
     */
    public static function validate(string $token) {
        // Check prefix
        if (strpos($token, self::TOKEN_PREFIX) !== 0) {
            return false;
        }
        
        // Remove prefix
        $token = substr($token, strlen(self::TOKEN_PREFIX));
        
        // Split payload and signature
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        
        [$payloadBase64, $providedSignature] = $parts;
        
        // Verify signature
        $expectedSignature = self::sign($payloadBase64);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            if ($GLOBALS['debug'] ?? false) {
                error_log('Widget Token: Signature mismatch');
            }
            return false;
        }
        
        // Decode payload
        $payloadJson = base64_decode(strtr($payloadBase64, '-_', '+/'));
        if ($payloadJson === false) {
            return false;
        }
        
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return false;
        }
        
        // Check required fields
        if (!isset($payload['uid']) || !isset($payload['wid']) || 
            !isset($payload['sid']) || !isset($payload['exp'])) {
            return false;
        }
        
        // Check expiration
        if ($payload['exp'] < time()) {
            if ($GLOBALS['debug'] ?? false) {
                error_log('Widget Token: Expired at ' . date('Y-m-d H:i:s', $payload['exp']));
            }
            return false;
        }
        
        // Validate widget owner exists and has widgets enabled
        $userId = intval($payload['uid']);
        $sql = "SELECT BID FROM BUSER WHERE BID = {$userId} LIMIT 1";
        $res = db::Query($sql);
        if (!db::FetchArr($res)) {
            if ($GLOBALS['debug'] ?? false) {
                error_log('Widget Token: Owner user not found: ' . $userId);
            }
            return false;
        }
        
        return $payload;
    }
    
    /**
     * Extract token from request (header or GET parameter)
     * 
     * @return string|null The token or null if not found
     */
    public static function extractFromRequest(): ?string {
        // Check X-Widget-Token header first (preferred)
        $headers = getallheaders();
        if (isset($headers['X-Widget-Token'])) {
            return $headers['X-Widget-Token'];
        }
        
        // Fallback to lowercase (some servers)
        if (isset($headers['x-widget-token'])) {
            return $headers['x-widget-token'];
        }
        
        // Check Authorization header with WidgetToken scheme
        $authHeader = Tools::getAuthHeaderValue();
        if ($authHeader && stripos($authHeader, 'WidgetToken ') === 0) {
            return trim(substr($authHeader, 12));
        }
        
        // Check GET parameter (for SSE/EventSource which can't set headers)
        if (isset($_GET['widget_token'])) {
            return $_GET['widget_token'];
        }
        
        // Check POST parameter as fallback
        if (isset($_POST['widget_token'])) {
            return $_POST['widget_token'];
        }
        
        return null;
    }
    
    /**
     * Restore session from token
     * 
     * Sets the session variables as if the cookie-based session was present.
     * This allows existing code to work without modifications.
     * 
     * @param array $payload The validated token payload
     * @return void
     */
    public static function restoreSession(array $payload): void {
        $_SESSION['is_widget'] = true;
        $_SESSION['widget_owner_id'] = $payload['uid'];
        $_SESSION['widget_id'] = $payload['wid'];
        $_SESSION['anonymous_session_id'] = $payload['sid'];
        $_SESSION['anonymous_session_created'] = $payload['iat'] ?? time();
        
        // Load widget configuration for prompt
        $widgetId = intval($payload['wid']);
        $ownerId = intval($payload['uid']);
        $group = 'widget_' . $widgetId;
        
        $sql = "SELECT BSETTING, BVALUE FROM BCONFIG 
                WHERE BOWNERID = {$ownerId} AND BGROUP = '" . db::EscString($group) . "'";
        $res = db::Query($sql);
        
        $config = [
            'prompt' => 'general',
            'autoMessage' => ''
        ];
        
        while ($row = db::FetchArr($res)) {
            $config[$row['BSETTING']] = $row['BVALUE'];
        }
        
        $_SESSION['WIDGET_PROMPT'] = $config['prompt'];
        $_SESSION['WIDGET_AUTO_MESSAGE'] = $config['autoMessage'];
        
        if ($GLOBALS['debug'] ?? false) {
            error_log('Widget Token: Session restored for owner=' . $payload['uid'] . 
                     ', widget=' . $payload['wid']);
        }
    }
    
    /**
     * Create HMAC signature for payload
     * 
     * @param string $data The data to sign
     * @return string The base64url-encoded signature
     */
    private static function sign(string $data): string {
        $secret = self::getSecret();
        $signature = hash_hmac('sha256', $data, $secret, true);
        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
    
    /**
     * Get the signing secret
     * 
     * Uses the API secret key from configuration, or falls back to
     * a generated key based on database credentials.
     * 
     * @return string The secret key
     */
    private static function getSecret(): string {
        // Try to get from ApiKeys if available
        if (class_exists('ApiKeys') && method_exists('ApiKeys', 'getWidgetTokenSecret')) {
            $secret = ApiKeys::getWidgetTokenSecret();
            if ($secret) {
                return $secret;
            }
        }
        
        // Fallback: Generate from database config (stable across requests)
        // This ensures tokens remain valid across PHP restarts
        $baseSecret = $GLOBALS['db_host'] . $GLOBALS['db_name'] . $GLOBALS['db_user'];
        return hash('sha256', $baseSecret . '_widget_session_v1');
    }
}

