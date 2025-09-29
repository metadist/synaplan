/**
 * Centralized System Notifications for Synaplan
 * Used across chat.js, chathistory.js, and other components
 */

// ========== CENTRALIZED LINK MANAGEMENT ==========

// Note: System links are now loaded from backend/ENV - these are fallbacks only
// Use dynamic URLs if available from backend injection
const SYSTEM_LINKS = window.SYNAPLAN_SYSTEM_URLS || {
    pricing: 'https://www.synaplan.com/pricing',
    account: 'https://www.synaplan.com/account', 
    upgrade: 'https://www.synaplan.com/pricing'
};

// ========== UNIFIED SYSTEM MESSAGE RENDERER ==========

/**
 * Show any system message in consistent style
 */
function showSystemMessage(type, title, message, options = {}) {
    const messageId = 'system-msg-' + Date.now();
    const upgradeMessage = options.showUpgrade ? getUpgradeMessage() : '';
    const timerHtml = options.resetTime ? createTimerHtml(messageId, options.resetTime) : '';
    
    // Use consistent chat message structure
    const messageHtml = `
        <li id="${messageId}" class="message-item ai-message" style="animation: fadeIn 0.5s ease-out;">
            <div class="ai-avatar" style="background: #6c757d;">
                <i class="fas fa-${options.icon || 'exclamation-circle'}"></i>
            </div>
            <div class="message-content">
                <div class="ai-meta mb-1">
                    <span class="ai-label" style="color: #6c757d; font-weight: 600;">✨ System</span>
                    <span class="message-time" style="color: #6c757d; font-size: 0.8em;">
                        ${new Date().toLocaleTimeString()}
                    </span>
                </div>
                <div class="ai-text" style="padding: 4px 0;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                        <span style="
                            font-size: 20px; 
                            opacity: 0.7;
                            animation: pulse 2s infinite ease-in-out;
                        ">${options.emoji || '⚠️'}</span>
                        <div>
                            <strong style="color: #495057;">${title}</strong>
                            <div style="color: #6c757d; font-size: 0.9em; margin-top: 2px;">
                                ${message}
                            </div>
                        </div>
                    </div>
                    ${timerHtml}
                    ${upgradeMessage}
                </div>
            </div>
        </li>
    `;
    
    // Insert into chat history
    const chatHistory = document.getElementById('chatHistory');
    if (chatHistory) {
        chatHistory.insertAdjacentHTML('beforeend', messageHtml);
        const chatContainer = chatHistory.closest('.chat-messages-container') || chatHistory.parentElement;
        if (chatContainer) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
    }
    
    // Start timer if provided
    if (options.resetTime) {
        startMiniTimer('timer-' + messageId, options.resetTime);
    }
}

/**
 * Show rate limit notification (unified, with duplicate prevention)
 */
function showRateLimitNotification(data) {
    // Prevent duplicates with timestamp-based key
    const now = Math.floor(Date.now() / 1000);
    const rateLimitKey = `rate_limit_${data.message || 'default'}_${Math.floor(now / 10)}`; // 10-second window
    
    if (window.lastRateLimitKey === rateLimitKey) {
        console.log('Rate limit notification already shown in this window');
        return; // Skip duplicate
    }
    
    window.lastRateLimitKey = rateLimitKey;
    
    showSystemMessage(
        'warning',
        'Usage Limit Reached', 
        data.message || 'Please wait before sending another message',
        {
            emoji: '⏳',
            icon: 'hourglass-half',
            resetTime: data.reset_time,
            showUpgrade: true
        }
    );
}

/**
 * Show empty message warning
 */
function showEmptyMessageWarning() {
    showSystemMessage(
        'warning',
        'Empty Message',
        'Please enter a message or attach a file before sending.',
        {
            emoji: '✏️',
            icon: 'edit',
            showUpgrade: false
        }
    );
}

/**
 * Show generic error message
 */
function showErrorMessage(title, message) {
    showSystemMessage(
        'error',
        title,
        message,
        {
            emoji: '❌',
            icon: 'exclamation-circle',
            showUpgrade: false
        }
    );
}

/**
 * Get appropriate upgrade message based on user context
 */
function getUpgradeMessage() {
    const isWidget = (typeof isAnonymousWidget !== 'undefined' && isAnonymousWidget) || 
                     (typeof isWidgetMode !== 'undefined' && isWidgetMode);
    
    if (isWidget) {
        return `
            <div class="border-top pt-3 mt-3" style="border-color: #dee2e6 !important;">
                <div style="font-size: 0.9em; color: #6c757d; margin-bottom: 8px;">
                    💡 This is a demo with limited usage
                </div>
                <a href="${SYSTEM_LINKS.pricing}" target="_blank" style="
                    display: inline-block;
                    background: linear-gradient(135deg, #007bff, #0056b3);
                    color: white;
                    text-decoration: none;
                    padding: 8px 16px;
                    border-radius: 6px;
                    font-weight: 600;
                    font-size: 0.85em;
                    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.25);
                    transition: all 0.2s ease;
                " onmouseover="
                    this.style.transform='translateY(-1px)'; 
                    this.style.boxShadow='0 4px 12px rgba(0, 123, 255, 0.35)';
                " onmouseout="
                    this.style.transform='translateY(0)'; 
                    this.style.boxShadow='0 2px 8px rgba(0, 123, 255, 0.25)';
                ">
                    ✨ Create your account
                </a>
            </div>
        `;
    } else {
        return `
            <div class="border-top pt-3 mt-3" style="border-color: #dee2e6 !important;">
                <div style="font-size: 0.9em; color: #6c757d; margin-bottom: 8px;">
                    Need higher limits?
                </div>
                <a href="${SYSTEM_LINKS.upgrade}" target="_blank" style="
                    display: inline-block;
                    background: linear-gradient(135deg, #28a745, #1e7e34);
                    color: white;
                    text-decoration: none;
                    padding: 8px 16px;
                    border-radius: 6px;
                    font-weight: 600;
                    font-size: 0.85em;
                    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.25);
                    transition: all 0.2s ease;
                " onmouseover="
                    this.style.transform='translateY(-1px)'; 
                    this.style.boxShadow='0 4px 12px rgba(40, 167, 69, 0.35)';
                " onmouseout="
                    this.style.transform='translateY(0)'; 
                    this.style.boxShadow='0 2px 8px rgba(40, 167, 69, 0.25)';
                ">
                    🚀 Upgrade your plan
                </a>
            </div>
        `;
    }
}

/**
 * Create timer HTML for rate limits
 */
function createTimerHtml(messageId, resetTime) {
    const timerId = 'timer-' + messageId;
    return `
        <div style="color: #495057; font-size: 0.9em; margin-bottom: 12px;">
            Next available in: <span id="${timerId}" style="
                font-family: 'SFMono-Regular', Consolas, monospace;
                font-weight: 600;
                color: #212529;
                padding: 2px 6px;
                background: rgba(0,0,0,0.05);
                border-radius: 4px;
                transition: background-color 0.3s ease;
            ">calculating...</span>
        </div>
    `;
}

/**
 * Live timer for rate limits
 */
function startMiniTimer(timerId, resetTime) {
    const timerElement = document.getElementById(timerId);
    if (!timerElement) {
        console.warn('Timer element not found:', timerId);
        return;
    }
    
    // Clear any existing timer for this element
    if (timerElement.timerInterval) {
        clearInterval(timerElement.timerInterval);
    }
    
    function updateTimer() {
        const now = Math.floor(Date.now() / 1000);
        const secondsLeft = Math.max(0, resetTime - now);
        
        if (secondsLeft <= 0) {
            timerElement.innerHTML = '<span class="text-success">✓ Ready</span>';
            if (timerElement.timerInterval) {
                clearInterval(timerElement.timerInterval);
                delete timerElement.timerInterval;
            }
            return;
        }
        
        const days = Math.floor(secondsLeft / 86400);
        const hours = Math.floor((secondsLeft % 86400) / 3600);
        const minutes = Math.floor((secondsLeft % 3600) / 60);
        const seconds = secondsLeft % 60;
        
        let timeText = '';
        if (days > 0) {
            timeText = `${days}d ${hours}h`;
        } else if (hours > 0) {
            timeText = `${hours}h ${minutes}m`;
        } else if (minutes > 0) {
            timeText = `${minutes}m ${seconds.toString().padStart(2, '0')}s`;
            timerElement.style.background = 'rgba(255, 193, 7, 0.1)';
        } else {
            timeText = `${seconds}s`;
            timerElement.style.background = 'rgba(220, 53, 69, 0.1)';
        }
        
        timerElement.textContent = timeText;
    }
    
    // Initial update
    updateTimer();
    
    // Store interval on element to prevent memory leaks
    timerElement.timerInterval = setInterval(updateTimer, 1000);
}

// Add required CSS animations
if (!document.getElementById('system-notification-styles')) {
    const styleElement = document.createElement('style');
    styleElement.id = 'system-notification-styles';
    styleElement.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse {
            0%, 100% { opacity: 0.7; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.05); }
        }
    `;
    document.head.appendChild(styleElement);
}

// Export functions globally
window.showSystemMessage = showSystemMessage;
window.showRateLimitNotification = showRateLimitNotification;
window.showEmptyMessageWarning = showEmptyMessageWarning;
window.showErrorMessage = showErrorMessage;
