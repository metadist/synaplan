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

// Central reasoning detection configuration
const REASONING_CONFIG = {
  // Explicit reasoning tags
  tags: ['think', 'reason', 'reasoning', 'thinking'],
  
  // Language indicators for implicit reasoning
  indicators: [
    'Okay, so',
    'I remember that',
    'I should explain',
    'I\'ll make sure to',
    'No need for search',
    'I\'ll structure the response',
    'I\'ll present this',
    'Since all the information',
    'I won\'t need to set',
    'I think',
    'I believe',
    'Let me think',
    'First, let me',
    'I need to',
    'I should',
    'I\'ll make sure',
    'I\'ll structure',
    'I\'ll present'
  ],
  
  // Minimum lengths for detection
  minComplexLength: 300,
  minReasoningLength: 100,
  minAnswerLength: 50
};

// Function to check if text contains reasoning patterns
function hasReasoningPatterns(text) {
  // Check for explicit tags
  const tagPattern = new RegExp(`<(${REASONING_CONFIG.tags.join('|')})>|<\\/(${REASONING_CONFIG.tags.join('|')})>`, 'i');
  if (tagPattern.test(text)) {
    return true;
  }
  
  // Check for implicit reasoning indicators
  const hasIndicators = REASONING_CONFIG.indicators.some(indicator => 
    text.toLowerCase().includes(indicator.toLowerCase())
  );
  
  return text.length > REASONING_CONFIG.minComplexLength && hasIndicators;
}

// Initialize markdown-it once with HTML support (if not already done)
if (typeof window.markdownit !== 'undefined' && !window.md) {
    window.md = window.markdownit({ 
        html: true, 
        linkify: true, 
        breaks: true 
    });
}

// Function to handle AI responses with reasoning blocks (flexible detection)
function handleThinkReasoning(text, targetId) {
  // Flexible reasoning detection - look for various tags and patterns
  const reasoningPatterns = [
    // Standard <think>...</think> pattern
    /<think>([\s\S]*?)<\/think>/i,
    // <reason>...</reason> pattern
    /<reason>([\s\S]*?)<\/reason>/i,
    // <reasoning>...</reasoning> pattern
    /<reasoning>([\s\S]*?)<\/reasoning>/i,
    // <thinking>...</thinking> pattern
    /<thinking>([\s\S]*?)<\/thinking>/i,
    // Pattern with only closing tag (common with AI models)
    /([\s\S]*?)<\/think>/i,
    /([\s\S]*?)<\/reason>/i,
    /([\s\S]*?)<\/reasoning>/i,
    /([\s\S]*?)<\/thinking>/i,
    // Pattern with only opening tag
    /<think>([\s\S]*?)$/i,
    /<reason>([\s\S]*?)$/i,
    /<reasoning>([\s\S]*?)$/i,
    /<thinking>([\s\S]*?)$/i
  ];
  
  let reasoning = null;
  let cleanedText = text;
  let matchedPattern = null;
  
  // Try each pattern to find reasoning content
  for (let pattern of reasoningPatterns) {
    const match = pattern.exec(text);
    if (match) {
      reasoning = match[1].trim();
      // Remove the matched pattern from the text
      cleanedText = text.replace(pattern, '').trim();
      matchedPattern = pattern;
      console.log('Found reasoning pattern:', pattern.source);
      console.log('Reasoning content:', reasoning);
      break;
    }
  }
  
  // If no explicit tags found, try to detect reasoning patterns in plain text
  if (!reasoning) {
    const reasoningDetection = detectImplicitReasoning(text);
    if (reasoningDetection) {
      reasoning = reasoningDetection.reasoning;
      cleanedText = reasoningDetection.answer;
      console.log('Detected implicit reasoning');
    }
  }
  
  if (!reasoning) {
    // No reasoning block found → render normally
    console.log('No reasoning pattern found, rendering normally');
    const rendered = window.md ? window.md.render(text) : text.replace(/\n/g, "<br>");
    $("#" + targetId).html(rendered);
    
    // Make images and videos responsive using Bootstrap classes
    $("#" + targetId).find('img:not(.ai-logo):not(.ai-meta-logo):not(.dropdown-logo), video').each(function() {
      $(this).addClass('img-fluid rounded my-2 d-block')
             .css('object-fit', 'contain');
    });
    return;
  }

  console.log('Found reasoning, processing...');
  console.log('Cleaned text:', cleanedText);

  // Try to parse JSON and extract BTEXT for display only
  let displayText = cleanedText;
  try {
    // First, try to find JSON in the cleaned text
    const jsonMatch = cleanedText.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      const jsonString = jsonMatch[0];
      const jsonData = JSON.parse(jsonString);
      if (jsonData.BTEXT) {
        displayText = jsonData.BTEXT;
        console.log('Successfully extracted BTEXT:', displayText);
      } else {
        console.log('JSON parsed but no BTEXT found');
      }
    } else {
      console.log('No JSON object found in cleaned text');
    }
  } catch (e) {
    console.log('JSON parsing failed:', e.message);
    // If not valid JSON, use the cleaned text as is
    displayText = cleanedText;
  }

  console.log('Display text:', displayText);

  // Render main answer (only the BTEXT part)
  const rendered = window.md ? window.md.render(displayText) : displayText.replace(/\n/g, "<br>");
  $("#" + targetId).html(rendered);
  
  // Make images and videos responsive using Bootstrap classes
  $("#" + targetId).find('img:not(.ai-logo):not(.ai-meta-logo):not(.dropdown-logo), video').each(function() {
    $(this).addClass('img-fluid rounded my-2 d-block')
           .css('object-fit', 'contain');
  });

  // Append collapsible reasoning with the ORIGINAL reasoning content
  const reasoningHtml = `
    <div class="mt-3 pt-2 border-top">
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-sm" 
                type="button" 
                data-bs-toggle="collapse" 
                data-bs-target="#reasoning-${targetId}"
                aria-expanded="false"
                aria-controls="reasoning-${targetId}">
          <i class="fas fa-brain me-1"></i>
          <span class="reasoning-text">Show reasoning</span>
        </button>
        <small class="text-muted">AI's internal thought process</small>
      </div>
      <div class="collapse mt-2" id="reasoning-${targetId}">
        <div class="card border-0 bg-light">
          <div class="card-body p-3">
            <div class="d-flex align-items-center mb-2">
              <i class="fas fa-lightbulb text-warning me-2"></i>
              <small class="text-muted fw-medium">AI Reasoning</small>
            </div>
            <div class="reasoning-content" style="max-height: 300px; overflow-y: auto; font-size: 0.875rem; line-height: 1.5;">
              <pre class="mb-0 text-dark" style="white-space: pre-wrap; font-family: inherit;">${reasoning}</pre>
            </div>
          </div>
        </div>
      </div>
    </div>`;
  
  console.log('Appending reasoning HTML');
  $("#" + targetId).append(reasoningHtml);
  
  // Initialize Bootstrap collapse for the new element
  if (typeof bootstrap !== 'undefined') {
    const collapseElement = document.getElementById(`reasoning-${targetId}`);
    if (collapseElement) {
      new bootstrap.Collapse(collapseElement, { toggle: false });
    }
  }
  
  // Add click handler to update button text
  const button = document.querySelector(`[data-bs-target="#reasoning-${targetId}"]`);
  if (button) {
    const textSpan = button.querySelector('.reasoning-text');
    
    button.addEventListener('click', function() {
      const isExpanded = this.getAttribute('aria-expanded') === 'true';
      textSpan.textContent = isExpanded ? 'Show reasoning' : 'Hide reasoning';
    });
  }
  
  console.log('Reasoning button setup complete');
}

// Function to detect implicit reasoning in plain text responses
function detectImplicitReasoning(text) {
  // Remove message ID prefix if present
  const cleanText = text.replace(/^\[\d+\]\s*/, '');
  
  // Check if text contains reasoning indicators
  const hasReasoningLanguage = REASONING_CONFIG.indicators.some(indicator => 
    cleanText.toLowerCase().includes(indicator.toLowerCase())
  );
  
  if (!hasReasoningLanguage) {
    return null; // No reasoning language detected
  }
  
  // Look for reasoning patterns
  const reasoningPatterns = [
    // Reasoning before numbered lists
    /^(.*?)(?=\n\d+\.\s|^1\.\s|^Schritt\s|^Step\s)/s,
    // Reasoning before "So," "Therefore," etc.
    /^(.*?)(?=\n(So|Therefore|Thus|Hence|Consequently|As a result|In conclusion|To answer|The answer is))/s,
    // Reasoning before action items
    /^(.*?)(?=\n(Um ein|Um eine|Um das|Um die|To register|To apply|To create|To set up|To start|To begin))/s
  ];
  
  for (let pattern of reasoningPatterns) {
    const match = pattern.exec(cleanText);
    if (match && match[1].trim().length > REASONING_CONFIG.minReasoningLength) {
      const reasoning = match[1].trim();
      const answer = cleanText.substring(match[0].length).trim();
      
      // Only split if we have both parts and answer is not too short
      if (answer.length > REASONING_CONFIG.minAnswerLength) {
        return {
          reasoning: reasoning,
          answer: answer
        };
      }
    }
  }
  
  return null;
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
        body: formData,
        credentials: 'include'
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
            // Use unified error notification for rate limits
            if (data.error.includes('rate_limit_exceeded') || data.error.includes('Rate limit')) {
                if (typeof showRateLimitNotification !== 'undefined') {
                    showRateLimitNotification({error: 'rate_limit_exceeded', message: data.error});
                }
            }
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
        // Use unified error notification
        if (typeof showErrorMessage !== 'undefined') {
            showErrorMessage('Network Error', 'Failed to load chat history');
        }
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
            
            // Check for rate limit notification FIRST (before any other processing)
            if (displayText && displayText.startsWith('RATE_LIMIT_NOTIFICATION: ')) {
                try {
                    const jsonStr = displayText.substring('RATE_LIMIT_NOTIFICATION: '.length);
                    const rateLimitData = JSON.parse(jsonStr);
                    
                    // Use the same styling as chat.js but for history display
                    const upgradeMessage = (typeof getUpgradeMessage !== 'undefined') ? getUpgradeMessage() : 
                        `<div class="border-top pt-2 mt-2"><small class="text-muted">Need higher limits? <a href="https://www.synaplan.com/" target="_blank" class="text-decoration-none fw-semibold" style="color: #6c757d;">Upgrade your plan →</a></small></div>`;
                    
                    const resetTime = rateLimitData.reset_time || (Math.floor(Date.now() / 1000) + 300);
                    const currentTime = Math.floor(Date.now() / 1000);
                    const timeRemaining = Math.max(0, resetTime - currentTime);
                    
                    // Format the time remaining
                    let timeDisplay = "expired";
                    if (timeRemaining > 0) {
                        const days = Math.floor(timeRemaining / 86400);
                        const hours = Math.floor((timeRemaining % 86400) / 3600);
                        const minutes = Math.floor((timeRemaining % 3600) / 60);
                        
                        if (days > 0) {
                            timeDisplay = `${days}d ${hours}h`;
                        } else if (hours > 0) {
                            timeDisplay = `${hours}h ${minutes}m`;
                        } else {
                            timeDisplay = `${minutes}m`;
                        }
                    }
                    
                    messageHtml = `
                        <li class="message-item ai-message">
                            <div class="message-header">
                                <img src="/assets/statics/img/chat-avatar.svg" alt="AI" class="ai-avatar">
                                <div class="provider-info">
                                    <span class="provider-name">${chat.BVENDOR || 'AI Provider'} / ${chat.BMODEL || 'system'}</span>
                                    <span class="provider-separator">·</span>
                                    <span class="provider-type">${chat.BTOPIC || 'notification'}</span>
                                </div>
                            </div>
                            <div class="message-bubble ai-bubble">
                                <div style="padding: 4px 0;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                                        <span style="font-size: 20px; opacity: 0.7;">⏳</span>
                                        <div>
                                            <strong style="color: #495057;">Usage Limit Reached</strong>
                                            <div style="color: #6c757d; font-size: 0.9em; margin-top: 2px;">
                                                ${rateLimitData.message}
                                            </div>
                                        </div>
                                    </div>
                                    <div style="color: #495057; font-size: 0.9em; margin-bottom: 12px;">
                                        Reset time: <span style="font-family: 'SFMono-Regular', Consolas, monospace; color: #6f42c1; font-weight: 500;">${timeDisplay}</span>
                                    </div>
                                    ${upgradeMessage}
                                </div>
                                <span class="message-time ai-time">${formatDateTime(chat.BDATETIME)}</span>
                            </div>
                        </li>
                    `;
                    chatHistory.insertAdjacentHTML('beforeend', messageHtml);
                    return; // Skip normal processing
                } catch (e) {
                    console.error('Error parsing rate limit data:', e);
                    // Fall through to normal processing
                }
            }
            
            // Check if text contains <think> block
            let mdText = '';
            if (displayText.includes('<think>')) {
                // For <think> blocks, we'll render the content after the message is added to DOM
                mdText = displayText;
            } else {
                // Process markdown with HTML support (do not escape AI messages)
                mdText = window.md ? window.md.render(displayText) : displayText;
            }
            
            let fileHtml = '';
            if (hasFile && chat.BFILEPATH) {
                const fileUrl = "up/" + chat.BFILEPATH;
                
                if (['png', 'jpg', 'jpeg'].includes(chat.BFILETYPE)) {
                    fileHtml = `<div class='generated-file-container'><img src='${fileUrl}' class='generated-image img-fluid rounded my-2 d-block' alt='Generated Image' loading='lazy' style='object-fit: contain;'></div>`;
                } else if (['mp4', 'webm'].includes(chat.BFILETYPE)) {
                    fileHtml = `<div class='generated-file-container'><video src='${fileUrl}' class='generated-video img-fluid rounded my-2 d-block' controls preload='metadata' style='object-fit: contain;'>Your browser does not support the video tag.</video></div>`;
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
                const logoUrl = `assets/statics/img/ai-logos/${serviceName}.svg`;
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
                const avatarLogoUrl = `assets/statics/img/ai-logos/${serviceName}.svg`;
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
                        <div class="reasoning-toggle" id="reasoning-toggle-rep${chat.BID}" style="display: none; margin-bottom: 0.5rem;">
                          <button class="btn btn-link btn-sm p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#reasoning-rep${chat.BID}" aria-expanded="false" aria-controls="reasoning-rep${chat.BID}" style="font-size: 0.75rem; text-decoration: none;">
                            <i class="fas fa-brain me-1" style="font-size: 0.7rem;"></i><span class="reasoning-text">Show reasoning</span>
                          </button>
                        </div>
                        <div class="collapse reasoning-content mb-2" id="reasoning-rep${chat.BID}">
                          <div class="reasoning-content" style="max-height:200px;overflow-y:auto;font-size:.875rem;line-height:1.5;background-color:#f8f9fa;border-radius:0.375rem;padding:0.75rem;border-left:3px solid #dee2e6;max-width:100%;box-sizing:border-box;">
                            <pre class="mb-0 text-muted" style="white-space:pre-wrap;font-family:inherit;word-wrap:break-word;overflow-wrap:break-word;font-size:0.8rem;line-height:1.4;margin:0;"></pre>
                          </div>
                        </div>
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
    
    // Handle <think> blocks for AI messages after they're added to DOM
    messages.forEach(chat => {
        if (chat.BDIRECT === 'OUT') {
            const displayText = chat.displayText || chat.BTEXT;
            
            // Check if this is from Groq first
            const isFromGroq = chat.aiService && chat.aiService.toLowerCase().includes('groq');
            console.log('Chat from Groq:', isFromGroq, 'Service:', chat.aiService);
            
            if (isFromGroq && hasGroqReasoningPatterns(displayText)) {
                const targetId = `rep${chat.BID}`;
                handleGroqReasoning(displayText, targetId);
            }
        }
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