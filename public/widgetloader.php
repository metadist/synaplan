<?php
// Ensure session cookies work inside third-party iframes by setting SameSite=None; Secure
// Robust HTTPS detection: X-Forwarded-Proto or baseUrl prefix
$forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
$baseHttps = isset($GLOBALS['baseUrl']) && strpos($GLOBALS['baseUrl'], 'https://') === 0;
$isHttps = ($forwardedProto === 'https') ||
           (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
           $baseHttps;
if (function_exists('session_set_cookie_params')) {
    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps ? true : false,
        'httponly' => true,
        'samesite' => 'None'
    ];
    @session_set_cookie_params($cookieParams);
}
session_start();
// core app files with relative paths
$root = __DIR__.'/';
require_once($root . '/inc/_coreincludes.php');

// Get parameters
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$widgetId = isset($_REQUEST['widgetid']) ? intval($_REQUEST['widgetid']) : 1;

// Validate parameters
if ($uid <= 0 || $widgetId < 1 || $widgetId > 9) {
    echo "<h1>Invalid widget parameters!</h1>";
    exit;
}

// Set anonymous widget session variables
$_SESSION["is_widget"] = true;
$_SESSION["widget_owner_id"] = $uid;
$_SESSION["widget_id"] = $widgetId;
$_SESSION["anonymous_session_id"] = uniqid('widget_', true); // Generate unique session ID
$_SESSION["anonymous_session_created"] = time(); // Add creation timestamp for timeout validation

// Validate session timeout for existing sessions
if (isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true) {
    $sessionTimeout = 86400; // 24 hours
    $sessionCreated = $_SESSION["anonymous_session_created"] ?? 0;
    
    if ((time() - $sessionCreated) > $sessionTimeout) {
        // Session expired, clear and recreate
        unset($_SESSION["is_widget"]);
        unset($_SESSION["widget_owner_id"]);
        unset($_SESSION["widget_id"]);
        unset($_SESSION["anonymous_session_id"]);
        unset($_SESSION["anonymous_session_created"]);
        
        // Recreate session
        $_SESSION["is_widget"] = true;
        $_SESSION["widget_owner_id"] = $uid;
        $_SESSION["widget_id"] = $widgetId;
        $_SESSION["anonymous_session_id"] = uniqid('widget_', true); // Generate new unique session ID
        $_SESSION["anonymous_session_created"] = time();
    }
}

// Get widget configuration from database
$group = "widget_" . $widgetId;
$sql = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = " . $uid . " AND BGROUP = '" . db::EscString($group) . "'";
$res = db::Query($sql);

$config = [
    'color' => '#007bff',
    'position' => 'bottom-right',
    'autoMessage' => '',
    'prompt' => 'general'
];

while ($row = db::FetchArr($res)) {
    $config[$row['BSETTING']] = $row['BVALUE'];
}

// Set the prompt topic for the chat
$_SESSION['WIDGET_PROMPT'] = $config['prompt'];
$_SESSION['WIDGET_AUTO_MESSAGE'] = $config['autoMessage'];

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Widget</title>
    <base href="<?php echo $GLOBALS['baseUrl']; ?>">
    <!-- Bootstrap CSS -->
    <link href="node_modules/bootstrap/dist/css/bootstrap.min.css?v=<?php echo @filemtime('node_modules/bootstrap/dist/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <!-- Dashboard CSS - includes all chat interface styles -->
    <link href="css/dashboard.css?v=<?php echo @filemtime('css/dashboard.css'); ?>" rel="stylesheet">
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
        
        .widget-header {
            background: <?php echo $config['color']; ?>;
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: bold;
        }
        
        .widget-content {
            height: calc(100vh - 60px);
            display: flex;
            flex-direction: column;
            overflow: hidden; /* avoid double scrollbars; let .chat-messages scroll */
        }

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
        
        /* Hide file preview by default in widget mode */
        #filesDiv {
            display: none !important;
        }
        
        .file-preview-container {
            display: none !important;
        }
        
        .file-preview-container.active {
            display: block !important;
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
</head>
<body>
    <div class="widget-header">
        Chat Support
    </div>
    <div class="widget-content">
        <?php include('snippets/c_chat.php'); ?>
    </div>
    <!-- Bootstrap JS - needed for dropdowns and other components -->
    <script src="node_modules/bootstrap/dist/js/bootstrap.bundle.min.js?v=<?php echo @filemtime('node_modules/bootstrap/dist/js/bootstrap.bundle.min.js'); ?>"></script>
    <script>
    (function() {
        // iOS/Safari third-party cookie mitigation using Storage Access API
        const canRequest = document.hasStorageAccess && document.requestStorageAccess;
        if (!canRequest) return; // Not Safari or unsupported

        // Only try when we appear to be in a third-party context
        try {
            document.hasStorageAccess().then((hasAccess) => {
                if (hasAccess) return;
                // Add a small banner prompting user to enable access
                const bar = document.createElement('div');
                bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#fff3cd;color:#856404;padding:10px 12px;border-top:1px solid #ffeeba;font-size:14px;z-index:999999;display:flex;align-items:center;justify-content:space-between;gap:12px;';
                bar.innerHTML = '<span>To enable chat on this site, please allow cookie access.</span>';
                const btn = document.createElement('button');
                btn.className = 'btn btn-sm btn-primary';
                btn.textContent = 'Allow';
                btn.addEventListener('click', function() {
                    document.requestStorageAccess().then(() => {
                        // Reload to use the session cookie
                        location.reload();
                    }).catch(() => {
                        // If denied, keep the bar visible
                    });
                });
                bar.appendChild(btn);
                document.body.appendChild(bar);
            });
        } catch (e) { /* ignore */ }
    })();
    </script>
</body>
</html> 