<?php
// Set content type to JavaScript
require_once('inc/_confsys.php');
require_once('inc/_confdb.php');

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
    'prompt' => 'general'
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
        z-index: 999999;
    `;
    chatButton.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor">
            <path d="M2.25 6.75c0-1.657 1.343-3 3-3h13.5c1.657 0 3 1.343 3 3v6.75c0 1.657-1.343 3-3 3H9.005a.75.75 0 0 0-.53.22L4.5 20.25v-3.75h-.75c-1.657 0-3-1.343-3-3V6.75z"/>
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
        z-index: 9999998;
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    `;

    // Create chat container
    const chatContainer = document.createElement('div');
    chatContainer.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 380px;
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

    // Auto-open functionality (if auto message is configured)
    <?php if (!empty($config['autoMessage'])): ?>
    setTimeout(() => {
        chatButton.click();
    }, 3000); // Auto-open after 3 seconds
    <?php endif; ?>
})(); 