<?php
// Set content type to JavaScript
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

header('Content-Type: application/javascript');

// Force session cookies to be compatible with third-party iframes (SameSite=None; Secure)
// This ensures that the widget iframe can send session cookies for API calls
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

// Get parameters
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$widgetId = isset($_REQUEST['widgetid']) ? intval($_REQUEST['widgetid']) : 1;

// Validate parameters
if ($uid <= 0 || $widgetId < 1 || $widgetId > 9) {
    echo "console.error('Invalid widget parameters: uid=$uid, widgetid=$widgetId');";
    exit;
}

// Get widget configuration from database
$group = "widget_" . $widgetId;
$sql = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = " . $uid . " AND BGROUP = '" . db::EscString($group) . "'";
$res = db::Query($sql);

$config = [
    'color' => '#007bff',
    'iconColor' => '#ffffff',
    'position' => 'bottom-right',
    'autoMessage' => '',
    'prompt' => 'general',
    'autoOpen' => '0'
];

while ($row = db::FetchArr($res)) {
    $config[$row['BSETTING']] = $row['BVALUE'];
}

// Get the base URL for the widget
$baseUrl = $GLOBALS["baseUrl"];
$widgetUrl = $baseUrl . "widgetloader.php?uid=" . $uid . "&widgetid=" . $widgetId;

// Determine position CSS
$positionCSS = '';
switch ($config['position']) {
    case 'bottom-left':
        $positionCSS = 'left: 20px;';
        break;
    case 'bottom-center':
        $positionCSS = 'left: 50%; transform: translateX(-50%);';
        break;
    default: // bottom-right
        $positionCSS = 'right: 20px;';
        break;
}

// Determine chat panel side CSS so it opens on the same side as the button
$panelSideCSS = '';
switch ($config['position']) {
    case 'bottom-left':
        $panelSideCSS = 'left: 20px; right: auto;';
        break;
    case 'bottom-center':
        $panelSideCSS = 'left: 50%; transform: translateX(-50%);';
        break;
    default: // bottom-right
        $panelSideCSS = 'right: 20px; left: auto;';
        break;
}

// Output the widget JavaScript
?>
(function() {
    // Create widget container
    const widgetContainer = document.createElement('div');
    widgetContainer.id = 'synaplan-chat-widget';
    widgetContainer.style.cssText = `
        position: fixed;
        bottom: 20px;
        <?php echo $positionCSS; ?>
        z-index: 2147483645;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    `;

    // Create floating button
    const chatButton = document.createElement('button');
    chatButton.id = 'synaplan-chat-button';
    chatButton.style.cssText = `
        width: 60px !important;
        height: 60px !important;
        min-width: 60px !important;
        min-height: 60px !important;
        max-width: 60px !important;
        max-height: 60px !important;
        border-radius: 50% !important;
        background: <?php echo $config['color']; ?> !important;
        border: none !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        cursor: pointer !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        transition: transform 0.3s ease, box-shadow 0.3s ease !important;
        color: <?php echo $config['iconColor']; ?> !important;
        padding: 0 !important;
        margin: 0 !important;
        z-index: 2147483645 !important;
        box-sizing: border-box !important;
        outline: none !important;
        -webkit-appearance: none !important;
        appearance: none !important;
        position: relative !important;
    `;
    // Inline SVG icon to ensure consistent rendering on iOS/Android and other platforms
    chatButton.innerHTML = `
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="display:block; pointer-events:none;">
        <path d="M4 4.75C4 3.7835 4.7835 3 5.75 3H18.25C19.2165 3 20 3.7835 20 4.75V14.25C20 15.2165 19.2165 16 18.25 16H8.41421L5.70711 18.7071C5.07714 19.3371 4 18.8898 4 17.9929V4.75Z" fill="<?php echo $config['iconColor']; ?>"/>
      </svg>
    `;
    chatButton.setAttribute('aria-label', 'Open chat');
    chatButton.setAttribute('title', 'Chat');
    chatButton.setAttribute('type', 'button');

    // Create overlay container
    const overlay = document.createElement('div');
    overlay.id = 'synaplan-chat-overlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 2147483646;
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    `;

    // Create chat container
    const chatContainer = document.createElement('div');
    chatContainer.id = 'synaplan-chat-container';
    chatContainer.style.cssText = `
        position: fixed;
        bottom: 20px;
        <?php echo $panelSideCSS; ?>
        width: 420px;
        max-width: 500px;
        height: 600px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        z-index: 2147483647;
        display: none;
        opacity: 0;
        transition: all 0.3s ease;
        overflow: hidden;
    `;

    // Create close button
    const closeButton = document.createElement('button');
    closeButton.style.cssText = `
        position: absolute !important;
        top: 12px !important;
        right: 12px !important;
        background: none !important;
        border: none !important;
        color: #6c757d !important;
        font-size: 20px !important;
        cursor: pointer !important;
        z-index: 10000000 !important;
        padding: 4px !important;
        border-radius: 50% !important;
        width: 32px !important;
        height: 32px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        transition: background-color 0.2s ease !important;
        box-sizing: border-box !important;
        margin: 0 !important;
        outline: none !important;
        -webkit-appearance: none !important;
        appearance: none !important;
    `;
    closeButton.innerHTML = '×';
    closeButton.onmouseover = () => closeButton.style.background = '#f8f9fa';
    closeButton.onmouseout = () => closeButton.style.background = 'transparent';

    // Create iframe container (initially empty)
    const iframeContainer = document.createElement('div');
    iframeContainer.style.cssText = `
        width: 100%;
        height: 100%;
        background: white;
    `;

    // (Removed external icon font to avoid cross-domain/font loading)

    // Assemble the widget
    chatContainer.appendChild(closeButton);
    chatContainer.appendChild(iframeContainer);
    overlay.appendChild(chatContainer);
    widgetContainer.appendChild(chatButton);
    document.body.appendChild(widgetContainer);
    document.body.appendChild(overlay);

    // Responsive rules for small screens (e.g., iPhone 14)
    const responsiveStyle = document.createElement('style');
    responsiveStyle.textContent = `
      #synaplan-chat-container { width: 420px; max-width: 500px; }
      @media (max-width: 500px) {
        #synaplan-chat-container {
          left: 10px !important;
          right: 10px !important;
          width: auto !important;
          bottom: 10px !important;
          height: calc(var(--sp-dvh, 100vh) - 20px) !important;
        }
        @supports (height: 100dvh) {
          #synaplan-chat-container {
            height: calc(100dvh - 20px) !important;
          }
        }
      }
    `;
    document.head.appendChild(responsiveStyle);

    // Add enforced z-index and side rules with !important to beat host CSS
    const enforcedStyle = document.createElement('style');
    enforcedStyle.textContent = `
      #synaplan-chat-widget { 
        position: fixed !important; 
        bottom: 20px !important; 
        z-index: 2147483645 !important; 
      }
      #synaplan-chat-overlay { 
        position: fixed !important; 
        top: 0 !important; 
        left: 0 !important; 
        z-index: 2147483646 !important; 
        width: 100% !important;
        height: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
      }
      #synaplan-chat-container { 
        position: fixed !important; 
        bottom: 20px !important; 
        z-index: 2147483647 !important; 
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
      }
      #synaplan-chat-button {
        box-sizing: border-box !important;
      }
      #synaplan-chat-container button {
        box-sizing: border-box !important;
      }
    `;
    document.head.appendChild(enforcedStyle);

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

    // Function to create and load iframe
    const loadChatFrame = () => {
        if (iframeContainer.children.length === 0) {
            const chatFrame = document.createElement('iframe');
            chatFrame.style.cssText = `
                width: 100%;
                height: 100%;
                border: none;
                background: white;
            `;
            // Allow requesting storage access from within the iframe (Safari iOS)
            try { chatFrame.setAttribute('allow', 'storage-access-by-user-activation'); } catch (e) {}
            chatFrame.src = '<?php echo $widgetUrl; ?>';
            iframeContainer.appendChild(chatFrame);
        }
    };

    // Handle button click
    chatButton.addEventListener('click', () => {
        overlay.style.display = 'block';
        chatContainer.style.display = 'block';
        setTimeout(() => {
            overlay.style.opacity = '1';
            chatContainer.style.opacity = '1';
            loadChatFrame(); // Load iframe content when button is clicked
        }, 10);
    });

    // Handle close button click
    closeButton.addEventListener('click', () => {
        overlay.style.opacity = '0';
        chatContainer.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
            chatContainer.style.display = 'none';
        }, 300);
    });

    // Close on overlay click
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            closeButton.click();
        }
    });

    // Add hover effect to chat button
    chatButton.onmouseover = () => {
        chatButton.style.transform = 'scale(1.1)';
        chatButton.style.boxShadow = '0 6px 16px rgba(0, 0, 0, 0.2)';
    };
    chatButton.onmouseout = () => {
        chatButton.style.transform = 'scale(1)';
        chatButton.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.15)';
    };

    // Auto-open functionality if explicitly enabled in config
    <?php if (!empty($config['autoOpen']) && $config['autoOpen'] === '1'): ?>
    setTimeout(() => {
        chatButton.click();
    }, 3000);
    <?php endif; ?>
})(); 