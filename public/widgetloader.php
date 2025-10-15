<?php
// Get parameters early to detect mobile mode
$isMobile = isset($_REQUEST['mobile']) && $_REQUEST['mobile'] == '1';

// Robust HTTPS detection: X-Forwarded-Proto or baseUrl prefix
$forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
$baseHttps = isset($GLOBALS['baseUrl']) && strpos($GLOBALS['baseUrl'], 'https://') === 0;
$isHttps = ($forwardedProto === 'https') ||
           (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
           $baseHttps;

// Set session cookie parameters based on mode
if (function_exists('session_set_cookie_params')) {
    if ($isMobile) {
        // Mobile mode: Use standard session cookies (no SameSite=None needed)
        // Since we're opening in a new tab on the same domain, we don't have third-party cookie issues
        $cookieParams = [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps ? true : false,
            'httponly' => true,
            'samesite' => 'Lax'  // Standard same-site for better security
        ];
    } else {
        // Desktop iframe mode: Ensure session cookies work inside third-party iframes
        $cookieParams = [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps ? true : false,
            'httponly' => true,
            'samesite' => 'None'
        ];
    }
    @session_set_cookie_params($cookieParams);
}

session_start();

// Core app files via Composer autoload and centralized includes
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// Get remaining parameters
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$widgetId = isset($_REQUEST['widgetid']) ? intval($_REQUEST['widgetid']) : 1;
$referrerUrl = isset($_REQUEST['referrer']) ? trim($_REQUEST['referrer']) : '';

// Validate parameters
if ($uid <= 0 || $widgetId < 1 || $widgetId > 9) {
    echo '<h1>Invalid widget parameters!</h1>';
    exit;
}

// SECURITY FIX: Only create new anonymous session if one doesn't exist
// This ensures each visitor gets a unique, persistent session for file isolation
if (!isset($_SESSION['anonymous_session_id']) || !isset($_SESSION['is_widget'])) {
    // First time visitor or session expired - create new session
    $_SESSION['is_widget'] = true;
    $_SESSION['widget_owner_id'] = $uid;
    $_SESSION['widget_id'] = $widgetId;
    $_SESSION['anonymous_session_id'] = uniqid('widget_', true) . '_' . bin2hex(random_bytes(8)); // Generate unique session ID
    $_SESSION['anonymous_session_created'] = time(); // Add creation timestamp for timeout validation
} else {
    // Existing session - validate timeout
    $sessionTimeout = 86400; // 24 hours
    $sessionCreated = $_SESSION['anonymous_session_created'] ?? 0;

    if ((time() - $sessionCreated) > $sessionTimeout) {
        // Session expired, clear and recreate with NEW anonymous_session_id
        unset($_SESSION['is_widget']);
        unset($_SESSION['widget_owner_id']);
        unset($_SESSION['widget_id']);
        unset($_SESSION['anonymous_session_id']);
        unset($_SESSION['anonymous_session_created']);

        // Recreate session with new ID
        $_SESSION['is_widget'] = true;
        $_SESSION['widget_owner_id'] = $uid;
        $_SESSION['widget_id'] = $widgetId;
        $_SESSION['anonymous_session_id'] = uniqid('widget_', true) . '_' . bin2hex(random_bytes(8)); // Generate new unique session ID
        $_SESSION['anonymous_session_created'] = time();
    } else {
        // Session still valid - update widget context but keep anonymous_session_id
        $_SESSION['is_widget'] = true;
        $_SESSION['widget_owner_id'] = $uid;
        $_SESSION['widget_id'] = $widgetId;
    }
}

// Note: Rate limiting is now handled in the chat submission API calls, not here
// This allows the widget interface to load but limits actual message sending

// Get widget configuration from database
$group = 'widget_' . $widgetId;
$sql = 'SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = ' . $uid . " AND BGROUP = '" . db::EscString($group) . "'";
$res = db::Query($sql);

$config = [
    'color' => '#007bff',
    'position' => 'bottom-right',
    'autoMessage' => '',
    'prompt' => 'general',
    'widgetLogo' => '' // Logo file path if configured
];

while ($row = db::FetchArr($res)) {
    $config[$row['BSETTING']] = $row['BVALUE'];
}

// Function to darken color for better contrast with white text
// Multiplies each RGB component by 0.4 as requested
function darkenColor($hexColor, $factor = 0.4)
{
    // Remove # if present
    $hexColor = ltrim($hexColor, '#');

    // Convert to RGB
    if (strlen($hexColor) == 3) {
        // Short form (e.g., #F00)
        $r = hexdec(str_repeat(substr($hexColor, 0, 1), 2));
        $g = hexdec(str_repeat(substr($hexColor, 1, 1), 2));
        $b = hexdec(str_repeat(substr($hexColor, 2, 1), 2));
    } else {
        // Long form (e.g., #FF0000)
        $r = hexdec(substr($hexColor, 0, 2));
        $g = hexdec(substr($hexColor, 2, 2));
        $b = hexdec(substr($hexColor, 4, 2));
    }

    // Multiply by factor (0.4 for 40% brightness)
    $r = round($r * $factor);
    $g = round($g * $factor);
    $b = round($b * $factor);

    // Ensure values stay in range 0-255
    $r = max(0, min(255, $r));
    $g = max(0, min(255, $g));
    $b = max(0, min(255, $b));

    // Convert back to hex
    return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT)
               . str_pad(dechex($g), 2, '0', STR_PAD_LEFT)
               . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}

// Calculate darkened color for user message bubbles (40% brightness for good contrast)
$darkenedUserColor = darkenColor($config['color'], 0.4);

// Set the prompt topic for the chat
$_SESSION['WIDGET_PROMPT'] = $config['prompt'];
$_SESSION['WIDGET_AUTO_MESSAGE'] = $config['autoMessage'];
$_SESSION['WIDGET_USER_BUBBLE_COLOR'] = $darkenedUserColor; // For user message bubbles in chat

// Set headers to prevent caching and allow iframe embedding
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: ALLOWALL'); // Allow cross-origin iframe embedding
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Chat Widget</title>
    <base href="<?php echo $GLOBALS['baseUrl']; ?>">
    <!-- Bootstrap CSS -->
    <link href="node_modules/bootstrap/dist/css/bootstrap.min.css?v=<?php echo @filemtime('node_modules/bootstrap/dist/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <!-- Dashboard CSS - includes all chat interface styles -->
    <link href="assets/statics/css/dashboard.css?v=<?php echo @filemtime('assets/statics/css/dashboard.css'); ?>" rel="stylesheet">
    <style>
        /* Widget-specific overrides */
        html, body {
            height: 100%;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: white;
        }
        /* Expand embedded chat to full width inside iframe, overriding Bootstrap grid */
        #contentMain { width: 100% !important; max-width: none !important; }
        .container, .container-fluid { max-width: none !important; padding-left: 0 !important; padding-right: 0 !important; }
        .row { margin-left: 0 !important; margin-right: 0 !important; }
        .col-md-9, .col-lg-10, .ms-sm-auto, .px-md-4 { width: 100% !important; max-width: none !important; flex: 1 1 auto !important; }
        .chat-container, .chatbox { width: 100% !important; }
        
        .widget-header {
            background: <?php echo $config['color']; ?>;
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: bold;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .widget-logo {
            height: 32px;
            width: auto;
            max-width: 48px;
            object-fit: contain;
        }
        
        .widget-back-button {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: background 0.2s ease;
            z-index: 10;
        }
        
        .widget-back-button:hover,
        .widget-back-button:active {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .widget-back-button svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }
        
        .widget-content {
            height: calc(var(--sp-dvh, 100vh) - 60px);
            display: flex;
            flex-direction: column;
            overflow: hidden; /* avoid double scrollbars; let .chat-messages scroll */
            position: relative; /* positioning context for cookie banner */
        }
        
        <?php if ($isMobile): ?>
        /* Mobile full-screen optimizations */
        .widget-content {
            height: calc(var(--sp-dvh, 100vh) - 60px);
        }
        
        .widget-header {
            padding: 12px 15px;
            font-size: 18px;
        }
        
        /* Ensure full screen on mobile */
        html, body {
            overflow: hidden;
            position: fixed;
            width: 100%;
        }
        <?php endif; ?>

        /* Remove default padding/margins from embedded main container */
        #contentMain {
            padding: 0 !important;
            margin: 0 !important;
            height: 100%;
            display: flex;
            flex-direction: column;
            background: transparent;
        }
        
        /* Widget-specific chat container adjustments */
        .chat-container {
            height: 100%;
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0; /* allow inner scroller to size correctly */
        }
        
        .chatbox {
            height: 100%;
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0; /* allow .chat-messages to use remaining height */
            border: none;
            box-shadow: none;
        }
        
        .chat-messages {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 15px;
        }
        
        .chat-input-container {
            border-top: 1px solid #e9ecef;
            background: white;
            padding: 15px;
            flex-shrink: 0; /* keep input anchored at bottom */
        }

        /* Hide action row (copy, Again, models dropdown) in widget mode only */
        .message-footer .js-ai-actions { 
            display: none !important; 
        }
        
        
        /* Widget-specific input layout */
        .input-controls-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }
        
        .action-buttons {
            flex-shrink: 0;
        }
        
        .message-input-wrapper {
            flex: 1;
            min-width: 0;
        }
        
        /* Responsive adjustments for widget */
        @media (max-width: 480px) {
            .chat-input-container {
                padding: 10px;
            }
            
            .input-controls-wrapper {
                gap: 8px;
            }
            
            .message-input {
                min-height: 36px;
                padding: 8px 12px;
                font-size: 16px;
            }
            
            .send-btn, .attach-btn {
                width: 36px;
                height: 36px;
            }
        }
    </style>
    <!-- jQuery - needed for chat functionality -->
    <script src="node_modules/jquery/dist/jquery.min.js?v=<?php echo @filemtime('node_modules/jquery/dist/jquery.min.js'); ?>"></script>
    <!-- Centralized System Notifications -->
    <script src="assets/statics/js/system-notifications.js?v=<?php echo @filemtime('assets/statics/js/system-notifications.js'); ?>"></script>
</head>
<body>
    
    <div class="widget-header">
        <?php if ($isMobile && !empty($referrerUrl)): ?>
        <button class="widget-back-button" id="widgetBackButton" type="button" aria-label="Go back">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
        </button>
        <?php endif; ?>
        <?php if (!empty($config['widgetLogo'])): ?>
        <img src="<?php echo htmlspecialchars($GLOBALS['baseUrl'] . 'up/' . $config['widgetLogo']); ?>" alt="Logo" class="widget-logo" onerror="this.style.display='none'">
        <?php endif; ?>
        <span>Chat Support</span>
    </div>
    <div class="widget-content" id="spWidgetContent">
        <?php include __DIR__ . '/../frontend/c_chat.php'; ?>
    </div>
    <!-- Bootstrap JS - needed for dropdowns and other components -->
    <script src="node_modules/bootstrap/dist/js/bootstrap.bundle.min.js?v=<?php echo @filemtime('node_modules/bootstrap/dist/js/bootstrap.bundle.min.js'); ?>"></script>
    <script>
    (function() {
        // Mobile mode: Setup history and back button for proper navigation
        <?php if ($isMobile && !empty($referrerUrl)): ?>
        var referrerUrl = <?php echo json_encode($referrerUrl); ?>;
        
        // Push a history state so the back button works
        if (window.history && window.history.pushState) {
            // Replace current state with referrer info
            window.history.replaceState({ fromWidget: true, referrer: referrerUrl }, '', window.location.href);
            
            // Handle back button
            var backButton = document.getElementById('widgetBackButton');
            if (backButton) {
                backButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    // Navigate back to referrer
                    window.location.href = referrerUrl;
                }, { passive: false });
            }
            
            // Also handle browser back button
            window.addEventListener('popstate', function(event) {
                if (event.state && event.state.fromWidget && event.state.referrer) {
                    window.location.href = event.state.referrer;
                } else if (referrerUrl) {
                    window.location.href = referrerUrl;
                }
            });
        }
        <?php endif; ?>
        
        // Dynamic viewport height for iOS Safari and mobile browsers
        const updateSPDVH = () => {
            const vh = (window.visualViewport && window.visualViewport.height) ? window.visualViewport.height : window.innerHeight;
            document.documentElement.style.setProperty('--sp-dvh', vh + 'px');
        };
        updateSPDVH();
        window.addEventListener('resize', updateSPDVH, { passive: true });
        window.addEventListener('orientationchange', updateSPDVH, { passive: true });
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', updateSPDVH, { passive: true });
            window.visualViewport.addEventListener('scroll', updateSPDVH, { passive: true });
        }

        // Focus chat input as soon as the widget content is ready
        function focusMessageInput() {
            try {
                var el = document.getElementById('messageInput');
                if (el && typeof el.focus === 'function') {
                    // Scroll into view first (helps on mobile)
                    if (el.scrollIntoView) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    
                    // Focus the element
                    el.focus();
                    
                    // Place caret at end for contenteditable
                    if (window.getSelection && document.createRange && el.isContentEditable) {
                        var range = document.createRange();
                        range.selectNodeContents(el);
                        range.collapse(false);
                        var sel = window.getSelection();
                        sel.removeAllRanges();
                        sel.addRange(range);
                    }
                    
                    // For mobile devices, try to trigger the keyboard
                    <?php if ($isMobile): ?>
                    // Trigger click event to ensure keyboard shows on mobile
                    if (el.click) {
                        setTimeout(function() { el.click(); }, 50);
                    }
                    <?php endif; ?>
                    
                    return true;
                }
                return false;
            } catch (e) { 
                return false;
            }
        }
        
        // Try multiple times to ensure focus works (especially on mobile)
        var focusAttempts = 0;
        var maxFocusAttempts = <?php echo $isMobile ? '8' : '3'; ?>;
        
        function tryFocus() {
            if (focusMessageInput()) {
                return; // Success, stop trying
            }
            focusAttempts++;
            if (focusAttempts < maxFocusAttempts) {
                setTimeout(tryFocus, <?php echo $isMobile ? '100' : '200'; ?>);
            }
        }
        
        // Start trying to focus as soon as possible
        document.addEventListener('DOMContentLoaded', function() { 
            setTimeout(tryFocus, 50);
        });
        window.addEventListener('load', function() { 
            setTimeout(tryFocus, 100);
        });
        
        <?php if ($isMobile): ?>
        // Additional mobile-specific focus attempts
        // Try again after a short delay (helps with mobile browsers)
        setTimeout(tryFocus, 300);
        setTimeout(tryFocus, 600);
        
        // Also try when page becomes visible (mobile tab switching)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                setTimeout(focusMessageInput, 100);
            }
        });
        <?php endif; ?>

        <?php if (!$isMobile): ?>
        // iOS/Safari third-party cookie mitigation using Storage Access API (Desktop iframe mode only)
        const canRequest = document.hasStorageAccess && document.requestStorageAccess;
        if (canRequest) {
            // Only try when we appear to be in a third-party context
            try {
                document.hasStorageAccess().then((hasAccess) => {
                    if (hasAccess) return;
                    // Add a small banner prompting user to enable access
                    const parent = document.getElementById('spWidgetContent') || document.body;
                    const bar = document.createElement('div');
                    bar.style.cssText = 'position:absolute;left:0;right:0;bottom:0;background:#fff3cd;color:#856404;padding:10px 12px;border-top:1px solid #ffeeba;font-size:14px;z-index:2147483647;display:flex;align-items:center;justify-content:space-between;gap:12px;pointer-events:auto;touch-action:manipulation;transform:translateZ(0);';
                    bar.innerHTML = '<span>To enable chat on this site, please allow cookie access.</span>';
                    const btn = document.createElement('button');
                    btn.className = 'btn btn-sm btn-primary';
                    btn.textContent = 'Allow';
                    btn.style.cssText = 'pointer-events:auto;';
                    btn.addEventListener('click', function() {
                        document.requestStorageAccess().then(() => {
                            // Reload to use the session cookie
                            location.reload();
                        }).catch(() => {
                            // If denied, keep the bar visible
                        });
                    });
                    bar.appendChild(btn);
                    parent.appendChild(bar);
                });
            } catch (e) { /* ignore */ }
        }
        <?php endif; ?>
    })();
    </script>
</body>
</html> 