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
$mode = isset($_REQUEST['mode']) ? trim($_REQUEST['mode']) : '';

// Validate parameters
if ($uid <= 0 || $widgetId < 1 || $widgetId > 9) {
    echo "console.error('Invalid widget parameters: uid=$uid, widgetid=$widgetId');";
    // Load widget notifications script
    echo "
document.addEventListener('DOMContentLoaded', function() {
    var script = document.createElement('script');
    script.src = '{$GLOBALS['baseUrl']}assets/statics/js/system-notifications.js';
    script.async = true;
    document.head.appendChild(script);
});
";
    exit;
}

// Get widget configuration from database
$group = 'widget_' . $widgetId;
$sql = 'SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = ' . $uid . " AND BGROUP = '" . db::EscString($group) . "'";
$res = db::Query($sql);

$config = [
    'color' => '#007bff',
    'iconColor' => '#ffffff',
    'position' => 'bottom-right',
    'autoMessage' => '',
    'prompt' => 'general',
    'autoOpen' => '0',
    'widgetLogo' => '', // Widget logo file path
    // Inline-box defaults
    'integrationType' => 'floating-button',
    'inlinePlaceholder' => 'Ask me anything...',
    'inlineButtonText' => 'Ask',
    'inlineFontSize' => '18',
    'inlineTextColor' => '#212529',
    'inlineBorderRadius' => '8'
];

while ($row = db::FetchArr($res)) {
    $config[$row['BSETTING']] = $row['BVALUE'];
}

// Function to darken color for better contrast with white text
// Multiplies each RGB component by 0.4 as requested
function darkenWidgetColor($hexColor, $factor = 0.4)
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

// Get the base URL for the widget (ensure trailing slash)
$baseUrl = rtrim($GLOBALS['baseUrl'], '/') . '/';
$widgetUrl = $baseUrl . 'widgetloader.php?uid=' . $uid . '&widgetid=' . $widgetId;

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
// Load widget rate limit notifications only if rate limiting enabled
<?php if (ApiKeys::isRateLimitingEnabled()): ?>
// Inject centralized URLs for widget
window.SYNAPLAN_SYSTEM_URLS = {
    pricing: '<?php echo addslashes(ApiKeys::getPricingUrl()); ?>',
    account: '<?php echo addslashes(ApiKeys::getAccountUrl()); ?>',
    upgrade: '<?php echo addslashes(ApiKeys::getUpgradeUrl()); ?>',
    base: '<?php echo addslashes(ApiKeys::getBaseUrl()); ?>'
};

(function() {
    var script = document.createElement('script');
    script.src = '<?php echo rtrim($GLOBALS['baseUrl'], '/'); ?>/assets/statics/js/system-notifications.js';
    script.async = true;
    document.head.appendChild(script);
})();
<?php endif; ?>

(function() {
    // Public no-op close callback hook (integrators can override)
    try { window.synaplanWidgetOnClose = window.synaplanWidgetOnClose || function(){}; } catch (e) {}

    // Mobile detection helper
    function isMobileDevice() {
        try {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
                   (navigator.maxTouchPoints && navigator.maxTouchPoints > 2 && /MacIntel/.test(navigator.platform));
        } catch(e) {
            return false;
        }
    }

    // Helper to open chat in new window (mobile-optimized)
    function openChatInNewWindow(url) {
        try {
            // Store current URL as referrer for back button
            var referrerUrl = window.location.href;
            var separator = url.indexOf('?') > -1 ? '&' : '?';
            var fullUrl = url + separator + 'referrer=' + encodeURIComponent(referrerUrl) + '&mobile=1';
            
            // Open in new window/tab
            var chatWindow = window.open(fullUrl, '_blank');
            
            // Fallback if popup blocked - create a visible link
            if (!chatWindow || chatWindow.closed || typeof chatWindow.closed === 'undefined') {
                window.location.href = fullUrl;
            }
        } catch(e) {
            // Final fallback - direct navigation
            window.location.href = url + (url.indexOf('?') > -1 ? '&' : '?') + 'mobile=1';
        }
    }

    <?php if ($mode === 'inline-box' || $config['integrationType'] === 'inline-box'): ?>
    // =============================
    // Inline Box Integration Mode
    // =============================
    try {
        var _spUid = <?php echo json_encode($uid); ?>;
        var _spWid = <?php echo json_encode($widgetId); ?>;
        var _spIdBase = 'synaplan-inline-' + _spUid + '-' + _spWid;
        var _placeholder = <?php echo json_encode($config['inlinePlaceholder']); ?>;
        var _btnText = <?php echo json_encode($config['inlineButtonText']); ?>;
        var _fontSize = <?php echo json_encode((string)$config['inlineFontSize']); ?>; // in px
        var _textColor = <?php echo json_encode($config['inlineTextColor']); ?>;
        var _primary = <?php echo json_encode($config['color']); ?>;
        var _radius = <?php echo json_encode((string)$config['inlineBorderRadius']); ?>; // in px

        // Write inline container directly at script position
        document.write('\n<div id="' + _spIdBase + '-container" style="display:block;max-width:900px;margin:0 auto;padding:30px 20px;">\n' +
            '  <div id="' + _spIdBase + '-box" style="display:flex;align-items:stretch;gap:12px;width:100%;box-sizing:border-box;' +
            '       background:rgba(255,255,255,0.98);padding:10px;border-radius:' + Math.max(12, parseInt(_radius) + 4) + 'px;' +
            '       box-shadow:0 8px 30px rgba(0,0,0,0.15);backdrop-filter:blur(12px);transition:all 0.3s ease;">\n' +
            '    <div style="position:relative;flex:1;min-width:0;display:flex;align-items:center;">\n' +
            '      <input id="' + _spIdBase + '-input" type="text" placeholder="' + _placeholder.replace(/"/g, '&quot;') + '" ' +
            '        style="flex:1;width:100%;padding:20px 60px 20px 28px;font-size:' + _fontSize + 'px;color:#061c3e;' +
            '               background:white;border:2px solid rgba(6,28,62,0.08);border-radius:' + _radius + 'px;outline:none;' +
            '               font-weight:500;transition:all 0.3s ease;">\n' +
            '      <button id="' + _spIdBase + '-upload" type="button" title="Upload file" aria-label="Upload file" ' +
            '        style="position:absolute;right:12px;width:38px;height:38px;background:transparent;border:none;' +
            '               border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;' +
            '               transition:all 0.2s ease;color:#6c757d;">\n' +
            '        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="pointer-events:none;">\n' +
            '          <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48" ' +
            '                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n' +
            '        </svg>\n' +
            '      </button>\n' +
            '    </div>\n' +
            '    <button id="' + _spIdBase + '-btn" type="button" ' +
            '      style="padding:20px 40px;font-size:' + Math.max(14, Math.min(20, parseInt(_fontSize))) + 'px;' +
            '             color:#061c3e;background:linear-gradient(135deg, #00E5FF 0%, #00FF9D 100%);' +
            '             border:none;border-radius:' + _radius + 'px;cursor:pointer;white-space:nowrap;font-weight:600;' +
            '             transition:all 0.3s ease;box-shadow:0 4px 16px rgba(0,229,255,0.25);">' + _btnText.replace(/</g,'&lt;') + '</button>\n' +
            '  </div>\n' +
            '</div>\n');

        // Create overlay and append to body (not document.write) to avoid stacking context issues
        (function() {
            // Wait for DOM to be ready
            function appendOverlay() {
                if (!document.body) {
                    setTimeout(appendOverlay, 10);
                    return;
                }
                
                var overlayHtml = 
                    '<div id="' + _spIdBase + '-overlay" style="position:fixed !important;inset:0 !important;background:rgba(0,0,0,0.45);' +
                    'z-index:2147483647 !important;display:none;opacity:0;transition:opacity .25s ease;' +
                    'top:0 !important;left:0 !important;right:0 !important;bottom:0 !important;' +
                    'width:100vw !important;height:100vh !important;margin:0 !important;padding:0 !important;">' +
                    '  <div id="' + _spIdBase + '-panel" style="position:fixed !important;left:2.5vw;top:2.5vh;width:95vw;height:95vh;' +
                    '       background:#fff;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,0.2);' +
                    '       z-index:2147483647 !important;display:flex;flex-direction:column;overflow:hidden;">' +
                    '    <button id="' + _spIdBase + '-close" type="button" aria-label="Close" title="Close" ' +
                    '      style="position:absolute !important;top:12px !important;right:12px !important;width:36px;height:36px;border:none;border-radius:18px;' +
                    '             background:transparent;color:#6c757d;font-size:22px;cursor:pointer;z-index:2147483647 !important;">×</button>' +
                    '    <div id="' + _spIdBase + '-framewrap" style="flex:1 1 auto;width:100%;height:100%;background:#fff;"></div>' +
                    '  </div>' +
                    '</div>';
                
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = overlayHtml;
                document.body.appendChild(tempDiv.firstChild);
            }
            
            // Execute immediately or wait for DOM
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', appendOverlay);
            } else {
                appendOverlay();
            }
        })();

        // Inject enforced z-index CSS and mobile responsiveness
        var enforceZIndexStyle = document.createElement('style');
        enforceZIndexStyle.id = _spIdBase + '-enforce-z';
        enforceZIndexStyle.textContent = 
            '#' + _spIdBase + '-overlay { ' +
            '  position: fixed !important; ' +
            '  z-index: 2147483647 !important; ' +
            '  top: 0 !important; ' +
            '  left: 0 !important; ' +
            '  right: 0 !important; ' +
            '  bottom: 0 !important; ' +
            '  width: 100vw !important; ' +
            '  height: 100vh !important; ' +
            '  margin: 0 !important; ' +
            '  padding: 0 !important; ' +
            '} ' +
            '#' + _spIdBase + '-panel { ' +
            '  position: fixed !important; ' +
            '  z-index: 2147483647 !important; ' +
            '} ' +
            '#' + _spIdBase + '-close { ' +
            '  position: absolute !important; ' +
            '  z-index: 2147483647 !important; ' +
            '} ' +
            /* Desktop default: button auto-width, keep original styling */ +
            '#' + _spIdBase + '-btn { ' +
            '  width: auto !important; ' +
            '  flex: 0 0 auto !important; ' +
            '} ' +
            /* Mobile responsive styles (below 768px) */ +
            '@media (max-width: 767px) { ' +
            '  #' + _spIdBase + '-container { ' +
            '    padding: 12px 10px !important; ' +
            '    max-width: 100% !important; ' +
            '  } ' +
            '  #' + _spIdBase + '-box { ' +
            '    flex-direction: column !important; ' +
            '    gap: 8px !important; ' +
            '    padding: 6px !important; ' +
            '  } ' +
            '  #' + _spIdBase + '-input { ' +
            '    padding: 14px 50px 14px 16px !important; ' +
            '    font-size: 15px !important; ' +
            '  } ' +
            '  #' + _spIdBase + '-upload { ' +
            '    right: 8px !important; ' +
            '    width: 34px !important; ' +
            '    height: 34px !important; ' +
            '  } ' +
            '  #' + _spIdBase + '-upload svg { ' +
            '    width: 18px !important; ' +
            '    height: 18px !important; ' +
            '  } ' +
            '  #' + _spIdBase + '-btn { ' +
            '    padding: 14px 24px !important; ' +
            '    font-size: 15px !important; ' +
            '    width: 100% !important; ' +
            '  } ' +
            '}';
        document.head.appendChild(enforceZIndexStyle);

        // Attach behavior after overlay is added to DOM
        (function(){
            var inputEl = document.getElementById(_spIdBase + '-input');
            var btnEl = document.getElementById(_spIdBase + '-btn');
            var uploadEl = document.getElementById(_spIdBase + '-upload');
            var overlay, frameWrap, closeEl, panelEl;
            var isOpen = false;
            
            // Wait for overlay to be appended to body
            function initializeOverlay() {
                overlay = document.getElementById(_spIdBase + '-overlay');
                frameWrap = document.getElementById(_spIdBase + '-framewrap');
                closeEl = document.getElementById(_spIdBase + '-close');
                panelEl = document.getElementById(_spIdBase + '-panel');
                
                if (!overlay || !frameWrap || !closeEl || !panelEl) {
                    setTimeout(initializeOverlay, 50);
                    return;
                }
                
                // Now attach all event listeners
                attachEventListeners();
            }
            
            function attachEventListeners() {
                function applyInlineMobileLayout(){
                try {
                    if (!panelEl) return;
                    var isMobile = (window.matchMedia && window.matchMedia('(max-width: 500px)').matches);
                    if (!isMobile) {
                        // Desktop defaults
                        panelEl.style.left = '2.5vw';
                        panelEl.style.top = '2.5vh';
                        panelEl.style.right = '';
                        panelEl.style.bottom = '';
                        panelEl.style.transform = '';
                        panelEl.style.width = '95vw';
                        panelEl.style.height = '95vh';
                        return;
                    }
                    // Centered, reduced size modal on mobile
                    var dvh = (window.visualViewport && window.visualViewport.height) ? window.visualViewport.height : window.innerHeight;
                    panelEl.style.left = '50%';
                    panelEl.style.top = '50%';
                    panelEl.style.right = 'auto';
                    panelEl.style.bottom = 'auto';
                    panelEl.style.transform = 'translate(-50%, -50%)';
                    panelEl.style.width = 'min(92vw, 420px)';
                    // target ~70% of dynamic viewport height, clamp between 320px and 90vh-equivalent
                    var targetHeight = Math.min(Math.max(320, 0.7 * dvh), 0.9 * dvh);
                    panelEl.style.height = targetHeight + 'px';
                } catch (_e) {}
            }

            // Apply once and on viewport changes
            applyInlineMobileLayout();
            window.addEventListener('resize', applyInlineMobileLayout, { passive: true });
            window.addEventListener('orientationchange', applyInlineMobileLayout, { passive: true });
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', applyInlineMobileLayout, { passive: true });
                window.visualViewport.addEventListener('scroll', applyInlineMobileLayout, { passive: true });
            }

            function loadFrameOnce(){
                if (frameWrap && frameWrap.children.length === 0) {
                    var chatFrame = document.createElement('iframe');
                    chatFrame.style.cssText = 'width:100%;height:100%;border:none;background:#fff;display:block;';
                    // Note: storage-access-by-user-activation is a newer feature, skip for compatibility
                    // try { chatFrame.setAttribute('allow', 'storage-access-by-user-activation'); } catch(e) {}
                    chatFrame.src = <?php echo json_encode($widgetUrl); ?>;
                    frameWrap.appendChild(chatFrame);
                }
            }

            function openOverlay(){
                // Mobile devices: open in new window instead of overlay
                if (isMobileDevice()) {
                    openChatInNewWindow(<?php echo json_encode($widgetUrl); ?>);
                    return;
                }
                
                // Desktop: use overlay as before
                if (!overlay) return;
                overlay.style.display = 'block';
                // Force reflow for transition
                void overlay.offsetWidth;
                overlay.style.opacity = '1';
                isOpen = true;
                loadFrameOnce();
                // After frame is loaded, focus the chat input
                setTimeout(function(){
                    try {
                        var iframe = frameWrap && frameWrap.querySelector('iframe');
                        if (iframe && iframe.contentWindow && iframe.contentWindow.document) {
                            var doc = iframe.contentWindow.document;
                            var inputEl = doc.getElementById('messageInput');
                            if (inputEl && typeof inputEl.focus === 'function') {
                                inputEl.focus();
                                // Place caret at end for contenteditable
                                try {
                                    if (doc.getSelection && doc.createRange && inputEl.isContentEditable) {
                                        var range = doc.createRange();
                                        range.selectNodeContents(inputEl);
                                        range.collapse(false);
                                        var sel = doc.getSelection();
                                        sel.removeAllRanges();
                                        sel.addRange(range);
                                    }
                                } catch(_e) {}
                            }
                        }
                    } catch(e) { /* cross-domain safe try */ }
                }, 350);
            }

            function closeOverlay(){
                if (!overlay) return;
                overlay.style.opacity = '0';
                setTimeout(function(){
                    overlay.style.display = 'none';
                    isOpen = false;
                    try { window.synaplanWidgetOnClose(); } catch(e) {}
                }, 250);
            }

            if (inputEl) {
                inputEl.addEventListener('focus', openOverlay, { passive: true });
                inputEl.addEventListener('click', openOverlay, { passive: true });
                inputEl.addEventListener('keydown', function(e){ if(e.key==='Enter'){ openOverlay(); } });
            }
            if (btnEl) {
                btnEl.addEventListener('click', openOverlay, { passive: true });
            }
            if (uploadEl) {
                uploadEl.addEventListener('click', openOverlay, { passive: true });
            }
            if (closeEl) {
                closeEl.addEventListener('click', closeOverlay, { passive: true });
            }
            if (overlay) {
                overlay.addEventListener('click', function(e){ if (e.target === overlay) closeOverlay(); });
            }

            // =============================
            // EXPOSE GLOBAL API FOR EXTERNAL JAVASCRIPT
            // =============================
            // This allows the website owner to interact with and animate the widget input
            var currentTypeInterval = null; // Track current typing animation
            
            window.SynaplanInlineWidget = window.SynaplanInlineWidget || {};
            window.SynaplanInlineWidget[_spWid] = {
                // Get the input element
                getInput: function() {
                    return inputEl;
                },
                // Get the button element
                getButton: function() {
                    return btnEl;
                },
                // Get the upload button element
                getUploadButton: function() {
                    return uploadEl;
                },
                // Set the input text
                setText: function(text) {
                    // Clear any ongoing typing animation
                    if (currentTypeInterval) {
                        clearInterval(currentTypeInterval);
                        currentTypeInterval = null;
                    }
                    if (inputEl) inputEl.value = text;
                },
                // Get the current input text
                getText: function() {
                    return inputEl ? inputEl.value : '';
                },
                // Animate typing effect
                typeText: function(text, speed) {
                    speed = speed || 80; // ms per character
                    if (!inputEl) return;
                    
                    // Clear any previous typing animation to prevent overlap
                    if (currentTypeInterval) {
                        clearInterval(currentTypeInterval);
                        currentTypeInterval = null;
                    }
                    
                    inputEl.value = '';
                    var i = 0;
                    currentTypeInterval = setInterval(function() {
                        if (i < text.length) {
                            inputEl.value += text.charAt(i);
                            i++;
                        } else {
                            clearInterval(currentTypeInterval);
                            currentTypeInterval = null;
                        }
                    }, speed);
                    return currentTypeInterval; // return so caller can clear if needed
                },
                // Clear the input
                clear: function() {
                    // Stop any ongoing typing animation
                    if (currentTypeInterval) {
                        clearInterval(currentTypeInterval);
                        currentTypeInterval = null;
                    }
                    if (inputEl) inputEl.value = '';
                },
                // Focus the input
                focus: function() {
                    if (inputEl) inputEl.focus();
                },
                // Trigger the button click (open overlay)
                open: function() {
                    openOverlay();
                },
                // Close the overlay
                close: function() {
                    closeOverlay();
                },
                // Check if overlay is open
                isOpen: function() {
                    return isOpen;
                },
                // Add custom style to the input
                styleInput: function(cssProperties) {
                    if (!inputEl) return;
                    for (var prop in cssProperties) {
                        inputEl.style[prop] = cssProperties[prop];
                    }
                },
                // Add custom style to the button
                styleButton: function(cssProperties) {
                    if (!btnEl) return;
                    for (var prop in cssProperties) {
                        btnEl.style[prop] = cssProperties[prop];
                    }
                },
                // Add custom style to the container
                styleContainer: function(cssProperties) {
                    var container = document.getElementById(_spIdBase + '-box');
                    if (!container) return;
                    for (var prop in cssProperties) {
                        container.style[prop] = cssProperties[prop];
                    }
                },
                // Stop any ongoing typing animation
                stopTyping: function() {
                    if (currentTypeInterval) {
                        clearInterval(currentTypeInterval);
                        currentTypeInterval = null;
                    }
                }
            };

            // Also expose a shorthand if only one widget exists
            if (!window.SynaplanWidget) {
                window.SynaplanWidget = window.SynaplanInlineWidget[_spWid];
            }

            // Add hover effects with brand colors
            if (inputEl) {
                inputEl.addEventListener('focus', function() {
                    var box = document.getElementById(_spIdBase + '-box');
                    if (box) box.style.boxShadow = '0 12px 40px rgba(0,0,0,0.2)';
                    inputEl.style.borderColor = 'rgba(0,229,255,0.4)';
                    inputEl.style.background = '#ffffff';
                });
                inputEl.addEventListener('blur', function() {
                    var box = document.getElementById(_spIdBase + '-box');
                    if (box) box.style.boxShadow = '0 8px 30px rgba(0,0,0,0.15)';
                    inputEl.style.borderColor = 'rgba(6,28,62,0.08)';
                });
            }
            if (btnEl) {
                btnEl.addEventListener('mouseenter', function() {
                    btnEl.style.background = 'linear-gradient(135deg, #00c4a7 0%, #00b49a 100%)';
                    btnEl.style.color = 'white';
                    btnEl.style.transform = 'translateY(-2px)';
                    btnEl.style.boxShadow = '0 8px 24px rgba(0,212,170,0.4)';
                });
                btnEl.addEventListener('mouseleave', function() {
                    btnEl.style.background = 'linear-gradient(135deg, #00E5FF 0%, #00FF9D 100%)';
                    btnEl.style.color = '#061c3e';
                    btnEl.style.transform = 'translateY(0)';
                    btnEl.style.boxShadow = '0 4px 16px rgba(0,229,255,0.25)';
                });
            }
            if (uploadEl) {
                uploadEl.addEventListener('mouseenter', function() {
                    uploadEl.style.background = 'rgba(0,229,255,0.1)';
                    uploadEl.style.color = '#00E5FF';
                });
                uploadEl.addEventListener('mouseleave', function() {
                    uploadEl.style.background = 'transparent';
                    uploadEl.style.color = '#6c757d';
                });
            }
            } // End attachEventListeners
            
            // Start initialization
            initializeOverlay();
        })();
    } catch(e) {
        console.error('Synaplan inline widget init error:', e);
    }
    return; // Do not render floating button below when inline mode is used
    <?php endif; ?>

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
        overflow: hidden !important;
    `;
    <?php if (!empty($config['widgetLogo'])): ?>
    // Use logo if configured
    chatButton.innerHTML = `
      <img src="<?php echo addslashes($GLOBALS['baseUrl'] . 'up/' . $config['widgetLogo']); ?>" 
           alt="Chat" 
           style="width: 36px; height: 36px; object-fit: contain; display:block; pointer-events:none;"
           onerror="this.outerHTML='<svg width=\\'28\\' height=\\'28\\' viewBox=\\'0 0 24 24\\' fill=\\'none\\' xmlns=\\'http://www.w3.org/2000/svg\\' aria-hidden=\\'true\\' focusable=\\'false\\' style=\\'display:block; pointer-events:none;\\'><path d=\\'M4 4.75C4 3.7835 4.7835 3 5.75 3H18.25C19.2165 3 20 3.7835 20 4.75V14.25C20 15.2165 19.2165 16 18.25 16H8.41421L5.70711 18.7071C5.07714 19.3371 4 18.8898 4 17.9929V4.75Z\\' fill=\\'<?php echo $config['iconColor']; ?>\\'/></svg>'">
    `;
    <?php else: ?>
    // Inline SVG icon to ensure consistent rendering on iOS/Android and other platforms
    chatButton.innerHTML = `
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="display:block; pointer-events:none;">
        <path d="M4 4.75C4 3.7835 4.7835 3 5.75 3H18.25C19.2165 3 20 3.7835 20 4.75V14.25C20 15.2165 19.2165 16 18.25 16H8.41421L5.70711 18.7071C5.07714 19.3371 4 18.8898 4 17.9929V4.75Z" fill="<?php echo $config['iconColor']; ?>"/>
      </svg>
    `;
    <?php endif; ?>
    chatButton.setAttribute('aria-label', 'Open chat');
    chatButton.setAttribute('title', 'Chat');
    chatButton.setAttribute('type', 'button');

    // Create overlay container
    const overlay = document.createElement('div');
    overlay.id = 'synaplan-chat-overlay';
    overlay.style.cssText = `
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        background: rgba(0, 0, 0, 0.5);
        z-index: 2147483647 !important;
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

    // Responsive rules for small screens (e.g., iPhone)
    const responsiveStyle = document.createElement('style');
    responsiveStyle.textContent = `
      #synaplan-chat-container { width: 420px; max-width: 500px; }
      @media (max-width: 500px) {
        #synaplan-chat-container {
          top: 50% !important;
          left: 50% !important;
          right: auto !important;
          bottom: auto !important;
          transform: translate(-50%, -50%) !important;
          width: min(92vw, 420px) !important;
          /* centered modal with reduced height on mobile */
          height: min(70vh, calc(var(--sp-dvh, 100vh) - 140px)) !important;
          max-height: 90vh !important;
          z-index: 2147483647 !important;
        }
        @supports (height: 100dvh) {
          #synaplan-chat-container {
            height: min(70vh, calc(100dvh - 140px)) !important;
            max-height: 90dvh !important;
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
        z-index: 2147483646 !important; 
      }
      #synaplan-chat-overlay { 
        position: fixed !important; 
        top: 0 !important; 
        left: 0 !important; 
        z-index: 2147483647 !important; 
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
      /* Mobile overrides to center modal */
      @media (max-width: 500px) {
        #synaplan-chat-container {
          bottom: auto !important;
          top: 50% !important;
          left: 50% !important;
          right: auto !important;
          transform: translate(-50%, -50%) !important;
          width: min(92vw, 420px) !important;
          height: min(70vh, calc(var(--sp-dvh, 100vh) - 140px)) !important;
          max-height: 90vh !important;
          z-index: 2147483647 !important;
        }
        @supports (height: 100dvh) {
          #synaplan-chat-container {
            height: min(70vh, calc(100dvh - 140px)) !important;
            max-height: 90dvh !important;
          }
        }
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
            // Note: storage-access-by-user-activation is a newer feature, skip for compatibility
            // try { chatFrame.setAttribute('allow', 'storage-access-by-user-activation'); } catch (e) {}
            chatFrame.src = '<?php echo $widgetUrl; ?>';
            iframeContainer.appendChild(chatFrame);
        }
    };

    // Handle button click
    chatButton.addEventListener('click', () => {
        // Mobile devices: open in new window instead of overlay
        if (isMobileDevice()) {
            openChatInNewWindow('<?php echo $widgetUrl; ?>');
            return;
        }
        
        // Desktop: use overlay as before
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