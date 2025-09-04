<?php
// Set content type to JavaScript
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

header('Content-Type: application/javascript');

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
        z-index: 999999;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    `;

    // Create floating button
    const chatButton = document.createElement('button');
    chatButton.id = 'synaplan-chat-button';
    chatButton.style.cssText = `
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: <?php echo $config['color']; ?>;
        border: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        color: white;
        font-size: 30px;
        padding-bottom: 5px;
        z-index: 999999;
    `;
    chatButton.innerHTML = '&#x1F5E9;';
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
        z-index: 9999998;
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
        right: 20px;
        width: 420px;
        max-width: 500px;
        height: 600px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        z-index: 9999999;
        display: none;
        opacity: 0;
        transition: all 0.3s ease;
        overflow: hidden;
    `;

    // Create close button
    const closeButton = document.createElement('button');
    closeButton.style.cssText = `
        position: absolute;
        top: 12px;
        right: 12px;
        background: none;
        border: none;
        color: #6c757d;
        font-size: 20px;
        cursor: pointer;
        z-index: 10000000;
        padding: 4px;
        border-radius: 50%;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
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
          height: calc(100vh - 20px) !important;
        }
      }
    `;
    document.head.appendChild(responsiveStyle);

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