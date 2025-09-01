/**
 * Chat History Loading Functionality
 * 
 * This script handles the dynamic loading of chat history via API calls.
 * Users can click buttons to load 10, 20, or 30 messages, which will
 * replace the button interface with the actual chat history.
 * 
 * Dependencies:
 * - jQuery (for DOM manipulation and AJAX)
 * - markdown-it (for markdown processing)
 * - highlight.js (for syntax highlighting)
 * - Font Awesome (for icons)
 */

// Initialize markdown-it once with HTML support (if not already done)
if (typeof window.markdownit !== 'undefined' && !window.md) {
    window.md = window.markdownit({ 
        html: true, 
        linkify: true, 
        breaks: true 
    });
}

// Function to load chat history via API
function loadChatHistory(amount) {
    const buttonsContainer = document.getElementById('chatHistoryButtons');
    const chatHistory = document.getElementById('chatHistory');
    
    // Show loading state
    buttonsContainer.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading ${amount} messages...</p>
        </div>
    `;
    
    // Make API call
    const formData = new FormData();
    formData.append('action', 'loadChatHistory');
    formData.append('amount', amount);
    
    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Error:', data.error);
            buttonsContainer.innerHTML = `
                <div class="text-center py-4">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Error loading chat history: ${data.error}
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                        <i class="fas fa-redo me-1"></i> Retry
                    </button>
                </div>
            `;
        } else if (data.success && data.messages) {
            // Remove the buttons container
            buttonsContainer.remove();
            
            // Render messages
            renderChatHistory(data.messages);
            
            // Scroll to bottom
            setTimeout(function() {
                $("#chatModalBody").scrollTop($("#chatModalBody").prop("scrollHeight"));
            }, 100);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        buttonsContainer.innerHTML = `
            <div class="text-center py-4">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Network error loading chat history
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="location.reload()">
                    <i class="fas fa-redo me-1"></i> Retry
                </button>
            </div>
        `;
    });
}

// Function to render chat history messages
function renderChatHistory(messages) {
    const chatHistory = document.getElementById('chatHistory');
    
    messages.forEach(chat => {
        let messageHtml = '';
        
        if (chat.BDIRECT === 'IN') {
            // User message
            messageHtml = `
                <li class="message-item user-message">
                    <div class="message-bubble user-bubble">
                        <p>${escapeHtml(chat.BTEXT)}</p>
                        ${chat.FILECOUNT > 0 ? `
                            <div class="file-attachment-header" onclick="showMessageFiles(${chat.BID})">
                                <i class="fas fa-paperclip paperclip-icon"></i>
                                <span>${chat.FILECOUNT} file${chat.FILECOUNT > 1 ? 's' : ''} attached</span>
                                <i class="fas fa-chevron-down chevron-icon"></i>
                            </div>
                            <div id="files-${chat.BID}" class="message-files" style="display: none;">
                                <!-- File details will be loaded here -->
                            </div>
                        ` : ''}
                        <span class="message-time user-time">${formatDateTime(chat.BDATETIME)}</span>
                    </div>
                </li>
            `;
        } else {
            // AI message
            const displayText = chat.displayText || chat.BTEXT;
            const hasFile = chat.hasFile || false;
            
            // Process markdown with HTML support (do not escape AI messages)
            const mdText = window.md ? window.md.render(displayText) : displayText;
            
            let fileHtml = '';
            if (hasFile && chat.BFILEPATH) {
                const fileUrl = "up/" + chat.BFILEPATH;
                
                if (['png', 'jpg', 'jpeg'].includes(chat.BFILETYPE)) {
                    fileHtml = `<div class='generated-file-container'><img src='${fileUrl}' class='generated-image' alt='Generated Image' loading='lazy'></div>`;
                } else if (['mp4', 'webm'].includes(chat.BFILETYPE)) {
                    fileHtml = `<div class='generated-file-container'><video src='${fileUrl}' class='generated-video' controls preload='metadata'>Your browser does not support the video tag.</video></div>`;
                }
            }
            
            // Generate meta text for footer
            // Remove "AI" prefix from service name for display (e.g., "AIOpenAI" -> "OpenAI")
            const service = chat.aiService ? chat.aiService.replace(/^AI/, '') : 'AI';
            const model = chat.aiModel || 'Model'; 
            const btag = chat.BTOPIC || 'chat';
            const metaText = `Generated by <strong>${service}</strong> / ${model} · ${btag}`;
            
            // Generate logo HTML
            let logoHtml = '';
            if (chat.aiService) {
                // Remove "AI" prefix from service name for logo path (e.g., "AIOpenAI" -> "openai")
                const serviceName = chat.aiService.replace(/^AI/, '').toLowerCase();
                const logoUrl = `img/ai-logos/${serviceName}.svg`;
                logoHtml = `
                    <span class="d-inline-flex align-items-center justify-content-center bg-white border rounded p-1 me-1 ai-meta-logo-wrapper">
                        <img class="d-block ai-meta-logo" src="${logoUrl}" width="12" height="12" alt="AI Provider" 
                             onerror="this.parentElement.classList.add('d-none')">
                    </span>
                `;
            }
            
            // Generate avatar HTML with AI logo support
            let avatarHtml = `<i class="fas fa-robot text-white ai-robot"></i>`;
            if (chat.aiService) {
                // Remove "AI" prefix from service name for logo path (e.g., "AIOpenAI" -> "openai")
                const serviceName = chat.aiService.replace(/^AI/, '').toLowerCase();
                const avatarLogoUrl = `img/ai-logos/${serviceName}.svg`;
                avatarHtml = `
                    <span class="d-inline-flex align-items-center justify-content-center bg-white border rounded p-1 d-none ai-logo-wrapper">
                        <img class="d-block ai-logo" src="${avatarLogoUrl}" width="16" height="16" alt="${chat.aiService}" 
                             onload="this.parentElement.classList.remove('d-none'); this.parentElement.nextElementSibling.style.display='none';"
                             onerror="this.parentElement.classList.add('d-none'); this.parentElement.nextElementSibling.style.display='block';">
                    </span>
                    <i class="fas fa-robot text-white ai-robot"></i>
                `;
            }
            
            // Generate system message HTML if SYSTEMTEXT exists
            let systemMessageContent = '';
            if (chat.SYSTEMTEXT && chat.SYSTEMTEXT.trim()) {
                systemMessageContent = escapeHtml(chat.SYSTEMTEXT);
            }
            
            messageHtml = `
                <li class="message-item ai-message" data-in-id="${chat.inId || chat.BID}">
                    <div class="ai-avatar">
                        ${avatarHtml}
                    </div>
                    <div class="message-content">
                        <span id="system${chat.BID}" class="system-message">${systemMessageContent}</span>
                        <div class="message-bubble ai-bubble">
                            <div id="rep${chat.BID}" class="message-content">
                                ${fileHtml}
                                ${mdText}
                            </div>

                            
                            <!-- Bootstrap responsive footer -->
                            <div class="mt-2 pt-2 border-top d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-end gap-2 message-footer">
                                <!-- Left: meta -->
                                <div class="text-muted small d-flex align-items-center flex-wrap gap-2 js-ai-meta">
                                    ${logoHtml}
                                    <span class="js-ai-meta-text">${metaText}</span>
                                </div>

                                <!-- Right: actions -->
                                <div class="d-flex align-items-center gap-2 justify-content-end">
                                    <button class="btn btn-outline-secondary btn-sm js-copy-message" 
                                            data-message-id="rep${chat.BID}"
                                            aria-label="Copy message content">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
            `;
        }
        
        chatHistory.innerHTML += messageHtml;
    });
    
    // Update thread state for history messages
    if (typeof updateThreadState === 'function') {
        updateThreadState();
    }
    
    // Apply syntax highlighting if highlight.js is available
    if (window.hljs) {
        chatHistory.querySelectorAll('pre code').forEach((block) => {
            window.hljs.highlightElement(block);
        });
    }
    

}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to format datetime
function formatDateTime(dateTimeStr) {
    if (!dateTimeStr) return '';
    
    // Convert YmdHis format to readable format
    const year = dateTimeStr.substring(0, 4);
    const month = dateTimeStr.substring(4, 6);
    const day = dateTimeStr.substring(6, 8);
    const hour = dateTimeStr.substring(8, 10);
    const minute = dateTimeStr.substring(10, 12);
    const second = dateTimeStr.substring(12, 14);
    
    return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
}

// Initialize chat history functionality when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if not in anonymous widget mode and elements exist
    if (typeof window.isAnonymousWidget === 'undefined' || !window.isAnonymousWidget) {
        // Add event listeners to history loading buttons
        const historyButtons = document.querySelectorAll('.load-history-btn');
        if (historyButtons.length > 0) {
            historyButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const amount = parseInt(this.getAttribute('data-amount'));
                    loadChatHistory(amount);
                });
            });
        }
        
        // Add event listener to hide history button
        const hideButtons = document.querySelectorAll('.hide-history-btn');
        if (hideButtons.length > 0) {
            hideButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const buttonsContainer = document.getElementById('chatHistoryButtons');
                    if (buttonsContainer) {
                        buttonsContainer.remove();
                    }
                });
            });
        }
    }
}); 