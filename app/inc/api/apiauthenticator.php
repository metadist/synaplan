<?php
/**
 * API Authentication Handler
 * 
 * Handles all authentication logic for API requests including
 * Bearer token auth, session auth, and anonymous widget sessions.
 * 
 * @package API
 */

class ApiAuthenticator {
    
    /** @var array Endpoints that allow anonymous widget users */
    private static $anonymousAllowedEndpoints = [
        'messageNew',
        'againOptions', 
        'chatStream',
        'getMessageFiles',
        'userRegister'
    ];
    
    /** @var array Endpoints that require authenticated user sessions */
    private static $authenticatedOnlyEndpoints = [
        'ragUpload',
        'docSum',
        'promptLoad',
        'promptUpdate',
        'deletePrompt',
        'getPromptDetails',
        'getFileGroups',
        'changeGroupOfFile',
        'getProfile',
        'loadChatHistory',
        'getWidgets',
        'saveWidget',
        'deleteWidget',
        'messageAgain',
        'getApiKeys',
        'createApiKey',
        'setApiKeyStatus',
        'deleteApiKey',
        'getMailhandler',
        'saveMailhandler',
        'mailTestConnection',
        'mailOAuthStart',
        'mailOAuthCallback',
        'mailOAuthStatus',
        'mailOAuthDisconnect'
    ];
    
    /**
     * Handle Bearer token authentication
     * 
     * @return bool True if authentication was handled (success or failure)
     */
    public static function handleBearerAuth(): bool {
        $authHeader = Tools::getAuthHeaderValue();
        
        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            return false; // No Bearer token present
        }
        
        $apiKey = trim(substr($authHeader, 7));
        
        if (strlen($apiKey) <= 20) {
            self::sendUnauthorized('Invalid API key format');
            return true;
        }
        
        // Validate API key
        $sql = "SELECT BOWNERID, BID, BSTATUS FROM BAPIKEYS WHERE BKEY = '".DB::EscString($apiKey)."' LIMIT 1";
        $res = DB::Query($sql);
        $row = DB::FetchArr($res);
        
        if (!$row || $row['BSTATUS'] !== 'active') {
            self::sendUnauthorized('Invalid or inactive API key');
            return true;
        }
        
        // Load user profile
        $userRes = DB::Query("SELECT * FROM BUSER WHERE BID = ".intval($row['BOWNERID'])." LIMIT 1");
        $userArr = DB::FetchArr($userRes);
        
        if (!$userArr) {
            self::sendUnauthorized('User account not found');
            return true;
        }
        
        // Set session
        $_SESSION['USERPROFILE'] = $userArr;
        $_SESSION['AUTH_MODE'] = 'api_key';
        
        // Update last used timestamp
        DB::Query("UPDATE BAPIKEYS SET BLASTUSED = ".time()." WHERE BID = ".intval($row['BID']));
        
        // Check rate limit
        $rateLimitResult = ['allowed' => true]; // Simplified for now
        if (!$rateLimitResult['allowed']) {
            http_response_code(429);
            echo json_encode(['error' => 'Rate limit exceeded']);
            exit;
        }
        
        return true;
    }
    
    /**
     * Check if action is allowed for current session
     * 
     * @param string $action The API action to check
     * @return bool True if action is allowed
     */
    public static function isActionAllowed(string $action): bool {
        if (in_array($action, self::$authenticatedOnlyEndpoints)) {
            return self::checkAuthenticatedAccess();
        } elseif (in_array($action, self::$anonymousAllowedEndpoints)) {
            return self::checkAnonymousOrAuthenticatedAccess();
        }
        
        // Unknown endpoint
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
        exit;
    }
    
    /**
     * Check authenticated user access
     * 
     * @return bool True if user is authenticated
     */
    private static function checkAuthenticatedAccess(): bool {
        if (!isset($_SESSION["USERPROFILE"]) || !isset($_SESSION["USERPROFILE"]["BID"])) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required for this endpoint']);
            exit;
        }
        
        return true;
    }
    
    /**
     * Check anonymous widget or authenticated access
     * 
     * @return bool True if access is allowed
     */
    private static function checkAnonymousOrAuthenticatedAccess(): bool {
        $isAnonymousWidget = isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true;
        
        if ($isAnonymousWidget) {
            return self::validateAnonymousWidgetSession();
        } else {
            return self::checkRegularUserSession();
        }
    }
    
    /**
     * Validate anonymous widget session
     * 
     * @return bool True if session is valid
     */
    private static function validateAnonymousWidgetSession(): bool {
        if ($GLOBALS["debug"]) {
            error_log("API Debug - Processing as anonymous widget session");
        }
        
        // Validate required session variables
        if (!isset($_SESSION["widget_owner_id"]) || 
            !isset($_SESSION["widget_id"]) || 
            !isset($_SESSION["anonymous_session_id"])) {
            
            if ($GLOBALS["debug"]) {
                error_log("API Debug - Missing required session variables for anonymous widget");
            }
            
            http_response_code(401);
            echo json_encode(['error' => 'Invalid anonymous widget session']);
            exit;
        }
        
        // Check session timeout
        if (!Frontend::validateAnonymousSession()) {
            if ($GLOBALS["debug"]) {
                error_log("API Debug - Anonymous session validation failed");
            }
            
            http_response_code(401);
            echo json_encode(['error' => 'Anonymous session expired. Please refresh the page.']);
            exit;
        }
        
        // Apply rate limit primarily to message creation endpoint only,
        // so SSE streaming (chatStream) doesn't consume the same budget.
        $currentAction = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
        if ($currentAction === 'messageNew') {
            // Tiny server-side guard against empty messages (in case frontend validation is bypassed)
            $rawMessage = isset($_POST['message']) ? trim((string)$_POST['message']) : '';
            $hasFiles = false;
            if (!empty($_FILES['files']) && isset($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
                foreach ($_FILES['files']['name'] as $idx => $name) {
                    if (!empty($name) && isset($_FILES['files']['size'][$idx]) && (int)$_FILES['files']['size'][$idx] > 0) { $hasFiles = true; break; }
                }
            }
            if ($rawMessage === '' && !$hasFiles) {
                http_response_code(400);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'Empty message']);
                exit;
            }
            $rateLimitKey = Tools::buildRateLimitKey(0, [
                'owner_id' => (int)$_SESSION["widget_owner_id"],
                'widget_id' => (int)$_SESSION["widget_id"]
            ]);
            $rateLimitResult = Tools::checkRateLimit($rateLimitKey, 60, 10); // 10 requests/min

            if (!$rateLimitResult['allowed']) {
                http_response_code(429);
                header('Content-Type: application/json; charset=UTF-8');
                if (isset($rateLimitResult['retry_after'])) {
                    header('Retry-After: ' . (int)$rateLimitResult['retry_after']);
                }
                echo json_encode([
                    'error' => 'Rate limit exceeded',
                    'retry_after' => $rateLimitResult['retry_after']
                ]);
                exit;
            }
        }
        
        return true;
    }
    
    /**
     * Check regular user session
     * 
     * @return bool True if user session is valid
     */
    private static function checkRegularUserSession(): bool {
        if ($GLOBALS["debug"]) {
            error_log("API Debug - Processing as authenticated user session");
        }
        
        if (!isset($_SESSION["USERPROFILE"]) || !isset($_SESSION["USERPROFILE"]["BID"])) {
            if ($GLOBALS["debug"]) {
                error_log("API Debug - Missing USERPROFILE for authenticated user");
            }
            
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        
        return true;
    }
    
    /**
     * Send unauthorized response and exit
     * 
     * @param string $message Error message
     */
    private static function sendUnauthorized(string $message): void {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $message]);
        exit;
    }
    
    /**
     * Log debug information about session state
     */
    public static function logSessionDebugInfo(): void {
        if (!$GLOBALS["debug"]) {
            return;
        }
        
        $isAnonymousWidget = isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true;
        
        error_log("API Debug - Session state:");
        error_log("  is_widget: " . (isset($_SESSION["is_widget"]) ? $_SESSION["is_widget"] : "NOT SET"));
        error_log("  USERPROFILE: " . (isset($_SESSION["USERPROFILE"]) ? "SET" : "NOT SET"));
        error_log("  widget_owner_id: " . (isset($_SESSION["widget_owner_id"]) ? $_SESSION["widget_owner_id"] : "NOT SET"));
        error_log("  widget_id: " . (isset($_SESSION["widget_id"]) ? $_SESSION["widget_id"] : "NOT SET"));
        error_log("  anonymous_session_id: " . (isset($_SESSION["anonymous_session_id"]) ? $_SESSION["anonymous_session_id"] : "NOT SET"));
        error_log("  isAnonymousWidget: " . ($isAnonymousWidget ? "TRUE" : "FALSE"));
    }
}
