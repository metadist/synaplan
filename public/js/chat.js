// Anonymous widget mode detection (set in c_chat.php)
// When true, restricts functionality for anonymous widget users:
// - File uploads limited to JPG, GIF, PNG, PDF
// - Microphone functionality disabled
// - Chat history loading disabled
// - Rate limiting applied
const isAnonymousWidget = typeof window.isAnonymousWidget !== 'undefined' ? window.isAnonymousWidget : false;
// Widget mode flag (compact embedded UI)
const isWidgetMode = typeof window.isWidgetMode !== 'undefined' ? window.isWidgetMode : false;

// ========================================
// GROQ/DeepSeek REASONING HANDLING
// ========================================
// Handles <think>/<reason> tags: shows final answer,
// reasoning hidden behind collapsible toggle.
// Uses BTEXT from JSON when available.
// ========================================


const GROQ_REASONING_CONFIG = {
  tags: ['think', 'reason', 'reasoning', 'thinking'],
  maxInputChars: 10000,
  maxReasoningChars: 2000
};

function hasGroqReasoningPatterns(text) {
  if (!text || text.length > GROQ_REASONING_CONFIG.maxInputChars) return false;
  
  // Only detect reasoning if explicit tags are present
  const tagPattern = new RegExp(`<(${GROQ_REASONING_CONFIG.tags.join('|')})>`, 'i');
  return tagPattern.test(text);
}

function findReasoningBlock(text) {
  const tagGroup = GROQ_REASONING_CONFIG.tags.join('|');
  const patterns = [
    new RegExp(`<(${tagGroup})>([\\s\\S]*?)<\\/\\1>`, 'i'),  // paired
    new RegExp(`<(${tagGroup})>([\\s\\S]*?)$`, 'i'),         // opening-only
    new RegExp(`^([\\s\\S]*?)<\\/(?:${tagGroup})>`, 'i')     // closing-only
  ];
  for (let i = 0; i < patterns.length; i++) {
    const m = patterns[i].exec(text);
    if (m) {
      const content = (i === 2) ? m[1] : m[2];
      const cleaned = text.replace(m[0], '').trim();
      return { reasoning: content.trim(), cleaned };
    }
  }
  return null;
}

// helpers
function normalizeQuotes(s) {
  return s
    .replace(/[\u201C\u201D\u201E\u00AB\u00BB]/g, '"')
    .replace(/[\u2018\u2019]/g, "'");
}

function extractLastJson(s) {
  if (typeof s !== 'string') return null;
  const txt = normalizeQuotes(s).trim();
  const m = txt.match(/\{[\s\S]*\}\s*$/);
  if (!m) return null;
  try { return JSON.parse(m[0]); } catch { return null; }
}

function extractBTEXT(text) {
  const match = text.match(/"BTEXT"\s*:\s*"([^"]*(?:\\.[^"]*)*)"/);
  if (!match) return null;
  try {
    const escaped = match[1].replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\r?\n/g, '\\n');
    return JSON.parse('"' + escaped + '"');
  } catch { return null; }
}

function handleGroqReasoning(text, targetId, renderFn) {
  const safeRender = renderFn || ((t) => t.replace(/[<>&]/g, (c) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])).replace(/\n/g, '<br>'));

  if (!text || text.length > GROQ_REASONING_CONFIG.maxInputChars) {
    $("#" + targetId).html(safeRender(text));
    return;
  }

  const fenceIndex = text.indexOf('```');
  const prefix = fenceIndex > -1 ? text.slice(0, fenceIndex) : text;
  const suffix = fenceIndex > -1 ? text.slice(fenceIndex) : '';

  let reasoning = null;
  let cleanedPrefix = prefix;

  const tagResult = findReasoningBlock(prefix);
  if (tagResult) { reasoning = tagResult.reasoning; cleanedPrefix = tagResult.cleaned; }

  // no reasoning → try last JSON first, else BTEXT regex, else raw
  if (!reasoning) {
    const obj = extractLastJson(text);
    if (obj && typeof obj.BTEXT === 'string') {
      $("#" + targetId).html(safeRender(obj.BTEXT));
      return;
    }
    const btext = extractBTEXT(text);
    $("#" + targetId).html(safeRender(btext || text));
    return;
  }

  if (reasoning.length > GROQ_REASONING_CONFIG.maxReasoningChars) {
    reasoning = reasoning.slice(0, GROQ_REASONING_CONFIG.maxReasoningChars) + ' …';
  }

  const cleanedText = cleanedPrefix + suffix;
  const displayText =
    extractBTEXT(cleanedText) ||
    (extractLastJson(cleanedText)?.BTEXT) ||
    cleanedText;

  const escapedReasoning = reasoning.replace(/[<>&]/g, (c) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));

  // Show reasoning toggle and populate content
  $(`#reasoning-toggle-${targetId}`).show();
  $(`#reasoning-${targetId} pre`).text(escapedReasoning);
  
  // Initialize Bootstrap collapse with proper settings
  if (typeof bootstrap !== 'undefined') {
    const el = document.getElementById(`reasoning-${targetId}`);
    if (el) {
      const collapse = new bootstrap.Collapse(el, { 
        toggle: false,
        parent: null
      });
      // Ensure it starts collapsed
      collapse.hide();
    }
  }
  
  // Render the main message content
  $("#" + targetId).html(safeRender(displayText));

  const btn = document.querySelector(`[data-bs-target="#reasoning-${targetId}"]`);
  if (btn) {
    const span = btn.querySelector('.reasoning-text');
    btn.addEventListener('click', function() {
      const isExpanded = this.getAttribute('aria-expanded') === 'true';
      span.textContent = isExpanded ? 'Show reasoning' : 'Hide reasoning';
    });
  }
}

// ========================================
// END GROQ AI REASONING HANDLING
// ========================================


// Initialize markdown-it once with HTML support
if (typeof window.markdownit !== 'undefined' && !window.md) {
    window.md = window.markdownit({ 
        html: true, 
        linkify: true, 
        breaks: true 
    });
}

// Keep track of user-attached files (pasted or manually selected).
let attachedFiles = [];

// References
const attachButton  = document.getElementById('attachButton');
const filesDiv      = document.getElementById('filesDiv');
const closeFilesDiv = document.getElementById('closeFilesDiv');
const fileInput     = document.getElementById('fileInput');
const manualFileSelect = document.getElementById('manualFileSelect');
const filePreview   = document.getElementById('filePreview');
const messageInput  = document.getElementById('messageInput');
const sendButton    = document.getElementById('sendButton');
const sendButtonMobile = document.getElementById('sendButtonMobile');
let aiTextBuffer = [];
// ------------------------------------------------------------
const loaders = new Map();

// File type validation for anonymous users
function isFileTypeAllowed(file) {
    if (!isAnonymousWidget) {
        return true; // All file types allowed for authenticated users
    }
    
    // Anonymous users can only upload: JPG, GIF, PNG, PDF
    const allowedTypes = [
        'image/jpeg',
        'image/jpg', 
        'image/gif',
        'image/png',
        'application/pdf'
    ];
    
    const allowedExtensions = ['.jpg', '.jpeg', '.gif', '.png', '.pdf'];
    
    // Check MIME type
    if (allowedTypes.includes(file.type)) {
        return true;
    }
    
    // Check file extension as fallback
    const fileName = file.name.toLowerCase();
    return allowedExtensions.some(ext => fileName.endsWith(ext));
}

function startWaitingLoader(parentId) {
    //console.log('startWaitingLoader', parentId);
    const parent = document.getElementById(parentId);
    if (!parent) return;
    const loader = document.createElement('div');
    parent.appendChild(loader);
    loaders.set(parentId, loader);
    loader.className = 'rotating-loader';
}

function stopWaitingLoader(parentId) {
    //console.log('stopWaitingLoader', parentId);
    const parent = document.getElementById(parentId);
    const loader = loaders.get(parentId);
    if (parent && loader && parent.contains(loader)) {
        parent.removeChild(loader);
        loaders.delete(parentId);
    } else if (loader) {
        // If loader exists but is not a child of parent, just clean up the reference
        loaders.delete(parentId);
    }
}

// Function to reset the file upload section completely
function resetFileUploadSection() {
    // Clear the attached files array
    attachedFiles.length = 0;
    
    // Clear the file input value to allow re-uploading the same file
    if (fileInput) {
        fileInput.value = '';
    }
    
    // Update the file preview to clear it
    updateFilePreview();
    
    // Hide the files div
    if (filesDiv) {
        filesDiv.style.display = 'none';
        filesDiv.classList.remove('active');
    }
    
    // Stop any loading indicators
    stopLoading();
}

// Initialize event listeners only if elements exist
function initializeEventListeners() {
    // Show/hide the attach area
    if (attachButton) {
        attachButton.addEventListener('click', () => {
            if (filesDiv) {
                filesDiv.style.display = 'block';
                filesDiv.classList.add('active');
                startLoading();
                if (fileInput) fileInput.click();
            }
        });
    }
    
    if (closeFilesDiv) {
        closeFilesDiv.addEventListener('click', () => {
            if (filesDiv) {
                filesDiv.style.display = 'none';
                filesDiv.classList.remove('active');
            }
        });
    }

    // Clicking "Select Files" triggers the hidden file input
    if (manualFileSelect) {
        manualFileSelect.addEventListener('click', () => {
            if (fileInput) fileInput.click();
        });
    }

    // 1) Manually selected files
    if (fileInput) {
        fileInput.addEventListener('change', () => {
            for (const file of fileInput.files) {
                if (isFileTypeAllowed(file)) {
                    attachedFiles.push(file);
                } else {
                    // Show error for disallowed file types
                    if (isAnonymousWidget) {
                        alert('Anonymous users can only upload JPG, GIF, PNG, and PDF files.');
                    }
                }
            }
            updateFilePreview();
        });
    }

    // 2) Pasting images/documents into the contenteditable
    if (messageInput) {
        messageInput.addEventListener('paste', (event) => {
            const items = event.clipboardData?.items || [];
            let didPasteFile = false;

            for (const item of items) {
                if (item.kind === 'file') {
                    const file = item.getAsFile();
                    if (file) {
                        if (isFileTypeAllowed(file)) {
                            attachedFiles.push(file);
                            didPasteFile = true;
                        } else {
                            // Show error for disallowed file types
                            if (isAnonymousWidget) {
                                alert('Anonymous users can only upload JPG, GIF, PNG, and PDF files.');
                            }
                        }
                    }
                }
            }
            
            // If we got any files, prevent them from appearing as raw text
            // and update the file preview UI.
            if (didPasteFile) {
                event.preventDefault();
                updateFilePreview();
            } else {
                // Handle text paste - strip color styling
                event.preventDefault();
                
                // Get plain text from clipboard
                const text = event.clipboardData.getData('text/plain');
                
                // Insert text at cursor position
                const selection = window.getSelection();
                if (selection.rangeCount > 0) {
                    const range = selection.getRangeAt(0);
                    range.deleteContents();
                    range.insertNode(document.createTextNode(text));
                    
                    // Move cursor to end of inserted text
                    range.collapse(false);
                    selection.removeAllRanges();
                    selection.addRange(range);
                } else {
                    // Fallback: append to end
                    messageInput.textContent += text;
                }
            }
        });
        
        messageInput.addEventListener('keydown', function(e) {
            // In anonymous widget mode: Enter sends, Shift+Enter makes newline
            if (isAnonymousWidget && e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
                e.preventDefault();
                handleSendMessage();
                return;
            }
            // Default (full app): Ctrl+Enter or Alt+Enter sends
            if (e.key === 'Enter' && (e.ctrlKey || e.altKey)) {
                e.preventDefault();
                handleSendMessage();
            }
        });
        
        // Arrow key handling for message history
        messageInput.addEventListener("keydown", function (e) {
            if (e.key === "ArrowUp") {
                e.preventDefault();
                if (messageHistory.length > 0) {
                    if (historyIndex === -1) {
                        historyIndex = messageHistory.length - 1;
                    } else if (historyIndex > 0) {
                        historyIndex--;
                    }
                    setMessageInput(messageHistory[historyIndex]);
                }
            } else if (e.key === "ArrowDown") {
                e.preventDefault();
                if (historyIndex >= 0 && historyIndex < messageHistory.length - 1) {
                    historyIndex++;
                    setMessageInput(messageHistory[historyIndex]);
                } else {
                    historyIndex = -1;
                    setMessageInput('');
                }
            }
        });
    }
}


const loader = document.getElementById('loader');

function startLoading() {
  if (loader) {
    loader.classList.remove('hidden');
  }
}

function stopLoading() {
  if (loader) {
    loader.classList.add('hidden');
  }
}
// Enhanced file preview with modern grid layout
function updateFilePreview() {
  if (!filePreview) return;
  
  filePreview.innerHTML = '';

  if (attachedFiles.length > 0) {
    attachedFiles.forEach((file, index) => {
      const fileCard = document.createElement('div');
      fileCard.className = 'file-preview-card';
      
      // File type icon and preview
      const previewSection = document.createElement('div');
      previewSection.className = 'file-preview-section';

      if (file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.className = 'file-preview-image';
        img.onload = () => URL.revokeObjectURL(img.src);
        previewSection.appendChild(img);
      } else {
        // File type icon
        const iconElement = document.createElement('i');
        let iconClass = 'fas fa-file file-preview-icon default';
        let iconColorClass = '';
        
        if (file.type.includes('pdf')) {
          iconClass = 'fas fa-file-pdf file-preview-icon pdf';
        } else if (file.type.includes('word') || file.name.endsWith('.docx')) {
          iconClass = 'fas fa-file-word file-preview-icon word';
        } else if (file.type.includes('audio') || file.name.endsWith('.mp3')) {
          iconClass = 'fas fa-file-audio file-preview-icon audio';
        } else if (file.type.includes('video') || file.name.endsWith('.mp4')) {
          iconClass = 'fas fa-file-video file-preview-icon video';
        } else if (file.type.includes('text') || file.name.endsWith('.txt')) {
          iconClass = 'fas fa-file-alt file-preview-icon text';
        }
        
        iconElement.className = iconClass;
        previewSection.appendChild(iconElement);
      }

      // Remove button
      const removeBtn = document.createElement('button');
      removeBtn.innerHTML = '<i class="fas fa-times"></i>';
      removeBtn.className = 'file-remove-btn';
      removeBtn.onclick = () => {
        attachedFiles.splice(index, 1);
        updateFilePreview();
              if (attachedFiles.length === 0) {
        if (filesDiv) {
            filesDiv.style.display = 'none';
            filesDiv.classList.remove('active');
        }
      }
      };

      // File info
      const fileInfo = document.createElement('div');
      fileInfo.className = 'file-info';
      
      const fileName = document.createElement('div');
      fileName.textContent = file.name.length > 20 ? file.name.substring(0, 17) + '...' : file.name;
      fileName.className = 'file-name';
      
      const fileSize = document.createElement('div');
      fileSize.textContent = `${(file.size / 1024).toFixed(1)} KB`;
      fileSize.className = 'file-size';

      fileInfo.appendChild(fileName);
      fileInfo.appendChild(fileSize);
      
      fileCard.appendChild(previewSection);
      fileCard.appendChild(removeBtn);
      fileCard.appendChild(fileInfo);
      
      filePreview.appendChild(fileCard);
    });
    
    if (filesDiv) {
        filesDiv.style.display = 'block';
        filesDiv.classList.add('active');
    }
    stopLoading();
  } else {
    if (filesDiv) {
        filesDiv.style.display = 'none';
        filesDiv.classList.remove('active');
    }
  }
}
// history handling in the input

const messageHistory = [];
let historyIndex = -1;

function setMessageInput(text) {
    if (!messageInput) return;
    messageInput.textContent = text;
    placeCaretAtEnd(messageInput);
}

function placeCaretAtEnd(el) {
    const range = document.createRange();
    const sel = window.getSelection();
    range.selectNodeContents(el);
    range.collapse(false);
    sel.removeAllRanges();
    sel.addRange(range);
}



// 3) Sending logic (front-end demonstration)
function handleSendMessage() {
    // Grab the user's text - use textContent instead of innerText to preserve actual newlines
    if (!messageInput) return;
    const userMessage = messageInput.textContent.trim();
    const actionMessage = 'messageNew';
    
    // Debug: Log the message to verify newlines are preserved
    console.log('Sending message:', JSON.stringify(userMessage));
    console.log('User message (raw):', userMessage, Array.from(userMessage));
    
    // Get the selected prompt configuration
    const selectedPromptId = document.getElementById('promptConfigSelect')?.value || 'general';
    
    // Example: Show everything in console
    // console.log('Message text:', userMessage);
    //console.log('Attached files:', attachedFiles);
    if (userMessage) {
        messageHistory.push(userMessage);
        if (messageHistory.length > 10) messageHistory.shift(); // Keep last 10
    }
    historyIndex = -1;

    // Real-world usage: create FormData, append files, send via fetch/AJAX
    let formData = new FormData();
    formData.append('message', userMessage);
    formData.append('action', actionMessage);
    formData.append('promptId', selectedPromptId);
    
    attachedFiles.forEach(file => {
      formData.append('files[]', file, file.name);
    });

    fetch('api.php', {
      method: 'POST',
      body: formData
    })
    .then(res => {
      if (!res.ok) {
        if (res.status === 401) {
          throw new Error('Authentication required. Please refresh the page.');
        } else if (res.status === 429) {
          throw new Error('Rate limit exceeded. Please wait a moment before sending another message.');
        } else {
          throw new Error('Network response was not ok');
        }
      }
      return res.json();
    })
    .then(data => {
      console.log('Server response:', data);
      if (data.error) {
        // Handle API errors
        if (data.error.includes('Rate limit exceeded')) {
          alert('Rate limit exceeded. Please wait a moment before sending another message.');
        } else if (data.error.includes('Invalid anonymous widget session')) {
          alert('Session expired. Please refresh the page.');
        } else {
          alert('Error: ' + data.error);
        }
        return;
      }
      if (data.success) {
        // Clear out the form for demonstration
        data.message = data.message.replace(/\\\"/g, '"');
        
        // Extract file count from server response
        let fileCount = 0;
        if (data.fileCount !== undefined) {
            fileCount = data.fileCount;
        } else if (data.message && data.message.includes('(+ ') && data.message.includes(' files)')) {
            // Fallback: Extract file count from message like "message text<br><small>(+ 3 files)</small>"
            const match = data.message.match(/\(\+ (\d+) files?\)/);
            if (match) {
                fileCount = parseInt(match[1]);
            }
        }
        
        // Add user message with exact same styling as PHP-rendered messages
        let fileAttachmentHtml = '';
        if (fileCount > 0) {
            fileAttachmentHtml = `
                <div class="file-attachment-header" onclick="showMessageFiles(${data.lastIds[0]})">
                    <i class="fas fa-paperclip paperclip-icon"></i>
                    <span>${fileCount} file${fileCount > 1 ? 's' : ''} attached</span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </div>
                <div id="files-${data.lastIds[0]}" class="message-files" style="display: none;">
                    <!-- File details will be loaded here -->
                </div>
            `;
        }
        
        // Clean up the message text to remove the file count info since we're showing it separately
        let cleanMessage = data.message;
        if (fileCount > 0) {
          cleanMessage = data.message.replace(/<br>\s*<small>\(\+ \d+ files?\)<\/small>/g, '');
        }
        
        // Convert literal \n to real newlines first
        cleanMessage = cleanMessage.replace(/\\r\\n/g, '\n').replace(/\\n/g, '\n').replace(/\\r/g, '');
        // Then convert newlines to <br>
        cleanMessage = cleanMessage.replace(/\n/g, '<br>');
        
        $("#chatHistory").append(`
          <li class="message-item user-message">
            <div class="message-bubble user-bubble">
              <p>${cleanMessage}</p>
              ${fileAttachmentHtml}
              <span class="message-time user-time">${data.time}</span>
            </div>
          </li>
        `);
        
        // now start the sse stream and show the server messages
        if(Array.isArray(data.lastIds)) {
          let AItextBlock = `START_${data.lastIds[0]}_${Date.now()}_${Math.random().toString(36).slice(2,7)}`;
          // Conditionally render actions (copy, again, dropdown) only when NOT in widget mode
          let actionsHtml = '';
          if (!isWidgetMode) {
            actionsHtml = `
                    <div class="d-flex align-items-center gap-1 justify-content-end js-ai-actions">
                      <button class="btn btn-outline-secondary btn-sm py-1 px-2 js-copy-message" 
                              data-message-id="${AItextBlock}"
                              aria-label="Copy message content">
                        <i class="fas fa-copy small"></i>
                      </button>
                      
                      <button class="btn btn-outline-secondary btn-sm py-1 px-2 js-again" aria-label="Again">
                        <!-- Large screens: full text -->
                        <span class="d-none d-lg-inline">
                          <small class="again-text">Again…</small>
                        </span>
                        <!-- Small/medium screens: icon + model name -->
                        <span class="d-inline d-lg-none">
                          <i class="fas fa-recycle me-1"></i><small class="again-text-mobile">Again…</small>
                        </span>
                      </button>

                      <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm py-1 px-2 dropdown-toggle js-again-dd"
                                data-bs-toggle="dropdown"
                                data-bs-display="static"
                                data-bs-boundary="viewport"
                                data-bs-offset="0,8"
                                aria-expanded="false">
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end js-again-menu small" style="max-height: 250px; overflow-y: auto; min-width: 200px; max-width: 280px;"></ul>
                      </div>
                    </div>`;
          }
          const metaTextInitial = isWidgetMode ? 'Support chat AI by synaplan' : 'Generated by … / … · …';
          const metaTextClass = isWidgetMode ? 'js-ai-meta-text widget-meta' : 'js-ai-meta-text';
          $("#chatHistory").append(`
                        <li class="message-item ai-message" data-in-id="${data.lastIds[0]}">
              <div class="ai-avatar">
                <i class="fas fa-robot text-white"></i>
              </div>
              <div class="message-content">
                <div class="reasoning-toggle" id="reasoning-toggle-${AItextBlock}" style="display: none; margin-bottom: 0.5rem;">
                  <button class="btn btn-link btn-sm p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#reasoning-${AItextBlock}" aria-expanded="false" aria-controls="reasoning-${AItextBlock}" style="font-size: 0.75rem; text-decoration: none;">
                    <i class="fas fa-brain me-1" style="font-size: 0.7rem;"></i><span class="reasoning-text">Show reasoning</span>
                  </button>
                </div>
                <div class="collapse reasoning-content mb-2" id="reasoning-${AItextBlock}">
                  <div class="reasoning-content" style="max-height:200px;overflow-y:auto;font-size:.875rem;line-height:1.5;background-color:#f8f9fa;border-radius:0.375rem;padding:0.75rem;border-left:3px solid #dee2e6;max-width:100%;box-sizing:border-box;">
                    <pre class="mb-0 text-muted" style="white-space:pre-wrap;font-family:inherit;word-wrap:break-word;overflow-wrap:break-word;font-size:0.8rem;line-height:1.4;margin:0;"></pre>
                  </div>
                </div>
                <span id="system${AItextBlock}" class="system-message"></span>
                <div class="message-bubble ai-bubble">
                  <div id="${AItextBlock}" class="message-content"></div>
                  <span class="message-time ai-time">${data.time}</span>
                  
                  <!-- Bootstrap responsive footer -->
                  <div class="mt-1 pt-1 border-top d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-end gap-1 message-footer">
                    <!-- Left: meta -->
                    <div class="text-muted small d-flex align-items-center flex-wrap gap-2 js-ai-meta">
                      <span class="d-inline-flex align-items-center justify-content-center bg-white border rounded p-1 me-1 d-none ai-meta-logo-wrapper">
                        <img class="d-block ai-meta-logo" src="img/single_bird.svg" width="12" height="12" alt="AI Provider">
                      </span>
                      <span class="${metaTextClass} small">${metaTextInitial}</span>
                    </div>
                    <!-- Right: actions (omitted in widget mode) -->
                    ${actionsHtml}
                  </div>
                </div>
              </div>
            </li>
          `);
          startWaitingLoader(AItextBlock);
          sseStream(data, AItextBlock);
          
          // Update thread state after creating new AI message
          updateThreadState();
          $("#chatModalBody").scrollTop( $("#chatModalBody").prop("scrollHeight") );
        }
        
        // Reset the file upload section only on successful upload
        resetFileUploadSection();
      }
    })
    .catch(err => {
      console.error(err);
      // Handle specific error messages for anonymous users
      if (isAnonymousWidget) {
        if (err.message.includes('Authentication required')) {
          alert('Session expired. Please refresh the page to continue.');
        } else if (err.message.includes('Rate limit exceeded')) {
          alert('Rate limit exceeded. Please wait a moment before sending another message.');
        } else {
          alert('Connection error. Please try again.');
        }
      } else {
        alert('Error: ' + err.message);
      }
      // Don't reset file upload section on error - let user retry with same files
    });
    
    // Clear out the form for demonstration
    messageInput.textContent = '';
    // Note: File upload section is now reset only on success via resetFileUploadSection()
}

// Add event listeners for both send buttons
if (sendButton) {
    sendButton.addEventListener('click', handleSendMessage);
}
if (sendButtonMobile) {
    sendButtonMobile.addEventListener('click', handleSendMessage);
}

  // Initialize all event listeners
  initializeEventListeners();

// ------------------------------------------------------------
function sseStream(data, outputObject, originalButton = null) {
  const outId = outputObject; // Keep local reference
  aiTextBuffer[outId] = ''; // Initialize buffer
  const ids = data.lastIds.join(',');
  const selectedPromptId = document.getElementById('promptConfigSelect')?.value || 'general';
  
  // Build URL with optional Again parameters
  let streamUrl = `api.php?action=chatStream&lastIds=${ids}&promptId=${selectedPromptId}`;
  if (data.again && data.again.model_id) {
    streamUrl += `&again_model_id=${data.again.model_id}&again=1`;
  }
  
  const eventSource = new EventSource(streamUrl);

  eventSource.onmessage = function(event) {
    console.log('SSE message:', event.data);
    
    // Guard JSON parsing with try/catch
    let eventMessage;
    try {
      eventMessage = JSON.parse(event.data);
    } catch (e) {
      console.warn('Non-JSON SSE data ignored:', event.data);
      return; // Ignore non-JSON lines
    }
    
    // Bail out early if invalid message
    if (!eventMessage || !eventMessage.status) {
      return;
    }
    
    if(eventMessage.status == 'ai_processing') {
      //stopLoading(outId);
      if(eventMessage.message.includes('<loading>')) {
        stopWaitingLoader(outId);
      }
      outMessage = eventMessage.message.replace(/\\\"/g, '"');
      // In anonymous widget mode, hide WEBWIDGET: prefix from display
      if (isAnonymousWidget) {
        outMessage = outMessage.replace(/^\s*WEBWIDGET:\s*/, '');
      }
      aiTextBuffer[outId] += outMessage;
      // Use aiRender instead of direct HTML to handle markdown/HTML properly during streaming
      aiRender(outId);
      $("#chatModalBody").scrollTop( $("#chatModalBody").prop("scrollHeight") );
      //console.log('Processing:', eventMessage.step);
    }

    if(eventMessage.status == 'pre_processing') {
      if(eventMessage.message == 'status.show') {
          // later useage
      } else if(eventMessage.message == 'status.hide') {
          // later useage        
      } else {
        // Use proper DOM manipulation to avoid HTML escaping
        const systemElement = document.getElementById(`system${outId}`);
        if (systemElement) {
          if (typeof window.isWidgetMode !== 'undefined' && window.isWidgetMode) {
            // In widget mode, replace content to avoid overflow
            systemElement.textContent = eventMessage.message;
          } else {
            // In standard mode, append messages as spans
            const messageSpan = document.createElement('span');
            messageSpan.textContent = eventMessage.message;
            systemElement.appendChild(messageSpan);
          }
        }
      }
      //console.log('Processing:', eventMessage.step);
    }

    if(eventMessage.status == 'done') {
      stopWaitingLoader(outId);
      eventSource.close(); // Optional
      
      // Write final message to buffer and render
      if (eventMessage.message) {
        let finalMsg = eventMessage.message.replace(/\\\"/g, '"');
        if (isAnonymousWidget) {
          finalMsg = finalMsg.replace(/^\s*WEBWIDGET:\s*/, '');
        }
        aiTextBuffer[outId] = finalMsg;
        aiRender(outId);
      }
      
      // Clear buffer after final render
      delete aiTextBuffer[outId];
      
      // Handle SSE meta for Again functionality
      if (eventMessage.meta) {
        const $messageItem = $(`#${outId}`).closest('.message-item');
        hydrateFromSSEMeta($messageItem, eventMessage.meta);
        
        // Always call againOptions for full hydration
        const prevId = $messageItem.find('.js-again').data('prev-id');
        if (prevId) {
          hydrateAgainOptions($messageItem, prevId);
        }
        
        // Inject generated files directly from SSE meta
        if (eventMessage.meta && eventMessage.meta.filePath && eventMessage.meta.fileType) {
          injectFilePreview(outId, eventMessage.meta.filePath, eventMessage.meta.fileType);
        }
      }
    } else if (eventMessage.status === 'error') {
      // Handle SSE error frames
      stopWaitingLoader(outId);
      const systemElement = document.getElementById(`system${outId}`);
      if (systemElement) {
        systemElement.textContent = eventMessage.message || 'Error occurred';
        systemElement.className = 'system-message text-danger';
      }
      eventSource.close();
      
      // Re-enable original Again button if this was an Again request
      if (originalButton && originalButton.length) {
        const prevLabel = originalButton.data('prev-label') || 'Again...';
        originalButton.prop('disabled', false).text(prevLabel).removeData('prev-label');
      }
      
      // Re-enable Again button in this new message
      const $newMessageItem = $(`#${outId}`).closest('.message-item');
      const $againBtn = $newMessageItem.find('.js-again');
      if ($againBtn.length && $againBtn.prop('disabled')) {
        const prevLabel = $againBtn.data('prev-label') || 'Again...';
        $againBtn.prop('disabled', false).text(prevLabel).removeData('prev-label');
      }
    }
  };

  // ------------------------------------------------------------
  eventSource.onerror = function(error) {
    console.error('SSE error:', error);
    eventSource.close(); // Optional
    stopWaitingLoader(outId);
    
    // Optionally show connection closed message if buffer is empty
    if (!aiTextBuffer[outId] || aiTextBuffer[outId].trim() === '') {
      const systemElement = document.getElementById(`system${outId}`);
      if (systemElement) {
        systemElement.textContent = 'Connection closed';
      }
    }
    // Do NOT call aiRender here to prevent crashes
  };  
}

// function AI RENDER
function aiRender(targetId) {
  const text = typeof aiTextBuffer[targetId] === 'string' ? aiTextBuffer[targetId] : '';

   // Debug: Log what we're rendering
   console.log('aiRender called for targetId:', targetId);
   console.log('Text content:', text);
  
  // Only handle Groq reasoning if there are actual reasoning patterns
  const hasReasoningPatterns = hasGroqReasoningPatterns(text);
  
  if (hasReasoningPatterns) {
    handleGroqReasoning(text, targetId);
    return;
  }
  
  // Detect if text looks like HTML (starts with < or contains common HTML tags)
  const looksLikeHTML = text.startsWith('<') || 
                       /(<(img|video|a|p|div|br|h[1-6]|span|ul|ol|li|small)\b[^>]*>)/i.test(text);
  
  let renderedText;
  
  if (looksLikeHTML) {
    // If it looks like HTML, insert raw HTML (no markdown processing)
    renderedText = text;
  } else if (typeof window.md !== 'undefined') {
    // Use markdown-it to render markdown with HTML support
    renderedText = window.md.render(text);
  } else {
    // Fallback if markdown-it is not available
    renderedText = text.replace(/\n/g, '<br>');
  }
  
  $("#" + targetId).html(renderedText);
}

// Function to show file details for a specific message
function showMessageFiles(messageId) {
    const filesContainer = document.getElementById(`files-${messageId}`);
    if (!filesContainer) {
        console.error('Files container not found for message ID:', messageId);
        return;
    }
    
    const attachmentDiv = filesContainer.previousElementSibling;
    if (!attachmentDiv) {
        console.error('Attachment header not found for message ID:', messageId);
        return;
    }
    
    const chevronIcon = attachmentDiv.querySelector('.fa-chevron-down, .fa-chevron-up');
    
    if (filesContainer.style.display === 'none') {
        // Show files
        filesContainer.style.display = 'block';
        if (chevronIcon) {
            chevronIcon.className = 'fas fa-chevron-up';
        }
        
        // Show loading indicator
        filesContainer.innerHTML = '<div style="font-size: 0.75rem; opacity: 0.7; padding: 8px; text-align: center;"><i class="fas fa-spinner fa-spin"></i> Loading files...</div>';
        
        // Load file details via AJAX
        const formData = new FormData();
        formData.append('action', 'getMessageFiles');
        formData.append('messageId', messageId);
        
        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.files && data.files.length > 0) {
                let filesHtml = '';
                data.files.forEach(file => {
                    const fileName = file.BFILEPATH ? file.BFILEPATH.split('/').pop() : 'Unknown file';
                    const fileIcon = getFileIcon(file.BFILETYPE);
                    const fileUrl = `up/${file.BFILEPATH}`;
                    
                    filesHtml += `
                        <div class="file-item">
                            <i class="${fileIcon}" style="font-size: 0.8rem;"></i>
                            <span style="flex: 1; word-break: break-all;">${fileName}</span>
                            <a href="${fileUrl}" target="_blank" style="color: rgba(255,255,255,0.8);">
                                <i class="fas fa-external-link-alt" style="font-size: 0.7rem;"></i>
                            </a>
                        </div>
                    `;
                });
                filesContainer.innerHTML = filesHtml;
            } else {
                filesContainer.innerHTML = '<div style="font-size: 0.75rem; opacity: 0.7; padding: 8px;">No file details available</div>';
            }
        })
        .catch(error => {
            console.error('Error loading file details:', error);
            filesContainer.innerHTML = '<div style="font-size: 0.75rem; opacity: 0.7; padding: 8px;">Error loading file details</div>';
        });
    } else {
        // Hide files
        filesContainer.style.display = 'none';
        if (chevronIcon) {
            chevronIcon.className = 'fas fa-chevron-down';
        }
    }
}

// Copy message functionality
$(document).on('click', '.js-copy-message', function(e) {
  e.preventDefault();
  const $button = $(this);
  const messageId = $button.data('message-id');
  
  if (!messageId) {
    console.warn('No message ID found for copy button');
    return;
  }
  
  const $messageContent = $(`#${messageId}`);
  if (!$messageContent.length) {
    console.warn('Message content not found:', messageId);
    return;
  }
  
  // Get text content, preserving line breaks
  let textContent = $messageContent.text().trim();
  
  // Copy to clipboard
  if (navigator.clipboard && window.isSecureContext) {
    // Modern async clipboard API
    navigator.clipboard.writeText(textContent).then(() => {
      // Visual feedback
      const originalIcon = $button.find('i').attr('class');
      $button.find('i').attr('class', 'fas fa-check text-success');
      setTimeout(() => {
        $button.find('i').attr('class', originalIcon);
      }, 1500);
    }).catch(err => {
      console.error('Failed to copy text: ', err);
      fallbackCopyTextToClipboard(textContent, $button);
    });
  } else {
    // Fallback for older browsers
    fallbackCopyTextToClipboard(textContent, $button);
  }
});

// Fallback copy function for older browsers
function fallbackCopyTextToClipboard(text, $button) {
  const textArea = document.createElement('textarea');
  textArea.value = text;
  textArea.style.position = 'fixed';
  textArea.style.left = '-999999px';
  textArea.style.top = '-999999px';
  document.body.appendChild(textArea);
  textArea.focus();
  textArea.select();
  
  try {
    document.execCommand('copy');
    // Visual feedback
    const originalIcon = $button.find('i').attr('class');
    $button.find('i').attr('class', 'fas fa-check text-success');
    setTimeout(() => {
      $button.find('i').attr('class', originalIcon);
    }, 1500);
  } catch (err) {
    console.error('Fallback copy failed: ', err);
  } finally {
    document.body.removeChild(textArea);
  }
}

// Again functionality
$(document).on('click', '.js-again', function(e) {
  e.preventDefault();
  const $button = $(this);
  
  // If already disabled, return
  if ($button.prop('disabled')) {
    return;
  }
  
  // Store current label and disable button
  const currentLabel = $button.text();
  $button.data('prev-label', currentLabel)
    .prop('disabled', true)
    .text('Working…');
  
  const chosenModelId = $button.data('chosen-model-id');
  const nextModelId = $button.data('next-model-id');
  const modelId = chosenModelId || nextModelId || null;
  const promptId = $('#promptConfigSelect').val();
  
  handleAgainMessage(modelId, promptId, $button);
});

$(document).on('click', '.js-model-choice', function(e) {
  e.preventDefault();
  const $link = $(this);
  const modelId = $link.data('model-id');
  const service = $link.data('service');
  const model = $link.data('model');
  
  const $againBtn = $link.closest('.js-ai-actions').find('.js-again');
  // Update both desktop and mobile text
  $againBtn.find('.again-text').text(`Again with ${model}`);
  $againBtn.find('.again-text-mobile').text(model);
  $againBtn.attr('data-chosen-model-id', modelId)
    .attr('title', `Replay with ${service} ${model}`);
});

function handleAgainMessage(modelId, promptId, $originalButton) {
  // Get inId from the closest AI message container
  const $messageItem = $originalButton.closest('.message-item.ai-message');
  const inId = $messageItem.data('in-id');
  
  const formData = new FormData();
  if (inId) formData.append('in_id', inId);
  if (modelId) formData.append('model_id', modelId);
  if (promptId) formData.append('promptId', promptId);
  
  fetch('api.php?action=messageAgain', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      // Create new AI message for Again response
      const AItextBlock = `START_AGAIN_${Date.now()}_${Math.random().toString(36).slice(2,7)}`;
      $("#chatHistory").append(`
        <li class="message-item ai-message" style="position: relative;">
          <div class="ai-avatar">
            <i class="fas fa-robot text-white"></i>
          </div>
          <div class="message-content">
            <div class="reasoning-toggle" id="reasoning-toggle-${AItextBlock}" style="display: none; margin-bottom: 0.5rem;">
              <button class="btn btn-link btn-sm p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#reasoning-${AItextBlock}" aria-expanded="false" aria-controls="reasoning-${AItextBlock}" style="font-size: 0.75rem; text-decoration: none;">
                <i class="fas fa-brain me-1" style="font-size: 0.7rem;"></i><span class="reasoning-text">Show reasoning</span>
              </button>
            </div>
            <div class="collapse reasoning-content mb-2" id="reasoning-${AItextBlock}">
              <div class="reasoning-content" style="max-height:200px;overflow-y:auto;font-size:.875rem;line-height:1.5;background-color:#f8f9fa;border-radius:0.375rem;padding:0.75rem;border-left:3px solid #dee2e6;max-width:100%;box-sizing:border-box;">
                <pre class="mb-0 text-muted" style="white-space:pre-wrap;font-family:inherit;word-wrap:break-word;overflow-wrap:break-word;font-size:0.8rem;line-height:1.4;margin:0;"></pre>
              </div>
            </div>
            <span id="system${AItextBlock}" class="system-message"></span>
            <div class="message-bubble ai-bubble">
              <div id="${AItextBlock}" class="message-content"></div>
              <span class="message-time ai-time">${data.time}</span>
              
              <!-- Bootstrap responsive footer -->
              <div class="mt-1 pt-1 border-top d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-end gap-1 message-footer">
                <!-- Left: meta -->
                <div class="text-muted small d-flex align-items-center flex-wrap gap-2 js-ai-meta">
                  <span class="d-inline-flex align-items-center justify-content-center bg-white border rounded p-1 me-1 d-none ai-meta-logo-wrapper">
                    <img class="d-block ai-meta-logo" width="12" height="12" alt="AI Provider">
                  </span>
                  <span class="js-ai-meta-text small">Generated by … / … · …</span>
                </div>

                <!-- Right: actions -->
                <div class="d-flex align-items-center gap-1 justify-content-end js-ai-actions">
                  <button class="btn btn-outline-secondary btn-sm py-1 px-2 js-copy-message" 
                          data-message-id="${AItextBlock}"
                          aria-label="Copy message content">
                    <i class="fas fa-copy small"></i>
                  </button>
                  
                  <button class="btn btn-outline-secondary btn-sm py-1 px-2 js-again" aria-label="Again">
                    <!-- Large screens: full text -->
                    <span class="d-none d-lg-inline">
                      <small class="again-text">Again…</small>
                    </span>
                    <!-- Small/medium screens: icon + model name -->
                    <span class="d-inline d-lg-none">
                      <i class="fas fa-recycle me-1"></i><small class="again-text-mobile">Again…</small>
                    </span>
                  </button>

                  <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm py-1 px-2 dropdown-toggle js-again-dd"
                            data-bs-toggle="dropdown"
                            data-bs-display="static"
                            data-bs-boundary="viewport"
                            data-bs-offset="0,8"
                            aria-expanded="false">
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end js-again-menu small" style="max-height: 250px; overflow-y: auto; min-width: 200px; max-width: 280px;"></ul>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </li>
      `);
      
      startWaitingLoader(AItextBlock);
      
      // Start SSE stream for Again request  
      const currentInId = $originalButton.closest('.message-item.ai-message').data('in-id');
      let streamUrl = `api.php?action=chatStream&again=1`;
      if (currentInId) streamUrl += `&in_id=${currentInId}`;
      if (modelId) streamUrl += `&model_id=${modelId}`;
      // Note: promptId intentionally excluded for Again requests
      
      const eventSource = new EventSource(streamUrl);
      
      // Handle SSE events for Again request
      aiTextBuffer[AItextBlock] = '';
      
      eventSource.onmessage = function(event) {
        try {
          const eventMessage = JSON.parse(event.data);
          if (!eventMessage || !eventMessage.status) { return; }
          
          if(eventMessage.status == 'ai_processing') {
            if(eventMessage.message.includes('<loading>')) {
              stopWaitingLoader(AItextBlock);
            }
            const outMessage = eventMessage.message.replace(/\\\"/g, '"');
            aiTextBuffer[AItextBlock] += outMessage;
            $("#" + AItextBlock).html(aiTextBuffer[AItextBlock]);
          }
          
          if(eventMessage.status == 'done') {
            stopWaitingLoader(AItextBlock);
            eventSource.close();
            
            // Write final message to buffer and render
            if (eventMessage.message) {
              aiTextBuffer[AItextBlock] = eventMessage.message.replace(/\\\"/g, '"');
              aiRender(AItextBlock);
            }
            
            // Clear buffer after final render
            delete aiTextBuffer[AItextBlock];
            
            // Handle SSE meta for Again functionality
            if (eventMessage.meta) {
              const $messageItem = $(`#${AItextBlock}`).closest('.message-item');
              hydrateFromSSEMeta($messageItem, eventMessage.meta);
              
              // Inject generated files directly from SSE meta
              if (eventMessage.meta && eventMessage.meta.filePath && eventMessage.meta.fileType) {
                injectFilePreview(AItextBlock, eventMessage.meta.filePath, eventMessage.meta.fileType);
              }
            }
            
            // Update thread state after Again message completes
            updateThreadState();
          } else if (eventMessage.status === 'error') {
            // Handle SSE error frames
            stopWaitingLoader(AItextBlock);
            const systemElement = document.getElementById(`system${AItextBlock}`);
            if (systemElement) {
              systemElement.textContent = eventMessage.message || 'Error occurred';
              systemElement.className = 'system-message text-danger';
            }
            eventSource.close();
            
            // Re-enable original Again button if this was an Again request
            if ($originalButton && $originalButton.length) {
              const prevLabel = $originalButton.data('prev-label') || 'Again...';
              $originalButton.prop('disabled', false).text(prevLabel).removeData('prev-label');
            }
          }
        } catch (error) {
          // Ignore non-JSON data
        }
      };
      
      eventSource.onerror = function(error) {
        console.error('SSE error:', error);
        eventSource.close();
        stopWaitingLoader(AItextBlock);
        
        if (!aiTextBuffer[AItextBlock] || aiTextBuffer[AItextBlock].trim() === '') {
          const systemElement = document.getElementById(`system${AItextBlock}`);
          if (systemElement) {
            systemElement.textContent = 'Connection closed';
          }
        }
        
        // Re-enable original button
        if ($originalButton && $originalButton.length) {
          const prevLabel = $originalButton.data('prev-label') || 'Again...';
          $originalButton.prop('disabled', false).text(prevLabel).removeData('prev-label');
        }
      };
      
      $("#chatModalBody").scrollTop($("#chatModalBody").prop("scrollHeight"));
    } else {
      handleAgainError(data.error);
      // Re-enable the original button on error
      if ($originalButton && $originalButton.length) {
        const prevLabel = $originalButton.data('prev-label') || 'Again...';
        $originalButton.prop('disabled', false).text(prevLabel).removeData('prev-label');
      }
    }
  })
  .catch(err => {
    console.error(err);
    alert('Connection error. Please try again.');
    // Re-enable the original button on error
    if ($originalButton && $originalButton.length) {
      const prevLabel = $originalButton.data('prev-label') || 'Again...';
      $originalButton.prop('disabled', false).text(prevLabel).removeData('prev-label');
    }
  });
}

function handleAgainError(errorMessage) {
  if (errorMessage && errorMessage.includes('Model not available')) {
    alert('Model not available or category mismatch.');
  } else {
    alert('Error: ' + (errorMessage || 'Unknown error'));
  }
}

function hydrateFromSSEMeta($messageItem, meta) {
  if (meta.service) {
    // Swap robot icon for service logo
    const $avatar = $messageItem.find('.ai-avatar');
    $avatar.html(`
      <span class="d-inline-flex align-items-center justify-content-center bg-white border rounded p-1 d-none ai-logo-wrapper">
        <img class="d-block ai-logo" width="16" height="16" alt="${meta.service}">
      </span>
      <i class="fas fa-robot text-white ai-robot"></i>
    `);
    
    const $avatarImg = $avatar.find('.ai-logo');
    const $avatarWrapper = $avatar.find('.ai-logo-wrapper');
    const $avatarRobot = $avatar.find('.ai-robot');
    
    $avatarImg.on('load', function() {
      $avatarRobot.hide();
      $avatarWrapper.removeClass('d-none');
    }).on('error', function() {
      $avatarRobot.show();
      $avatarWrapper.addClass('d-none');
    }).attr('src', `img/ai-logos/${meta.service.toLowerCase()}.svg`);
    
    // Update meta text and logo
    const $metaTextSpan = $messageItem.find('.js-ai-meta-text');
    const $metaLogoWrapper = $messageItem.find('.ai-meta-logo-wrapper');
    const $metaLogo = $messageItem.find('.ai-meta-logo');
    
    // In widget mode, keep the simplified label and do not overwrite with model info
    if (typeof window.isWidgetMode !== 'undefined' && window.isWidgetMode) {
      $metaTextSpan.addClass('widget-meta').text('Support chat AI by synaplan');
    } else {
      const badgeHtml = meta.isAgain ? ' <span class="badge bg-secondary ms-2">Again</span>' : '';
      $metaTextSpan.html(`Generated by <strong>${meta.service}</strong> / ${meta.model} · ${meta.btag}${badgeHtml}`);
    }
    
    // Set meta logo
    $metaLogo.on('load', function() {
      $metaLogoWrapper.removeClass('d-none');
    }).on('error', function() {
      $metaLogoWrapper.addClass('d-none');
    }).attr('src', `img/ai-logos/${meta.service.toLowerCase()}.svg`);
    
    // Set datasets
    if (meta.btag) $messageItem.attr('data-btag', meta.btag);
    if (meta.inId) $messageItem.attr('data-in-id', meta.inId);
    
    // Update Again button with predicted next
    const $againBtn = $messageItem.find('.js-again');
    if (meta.predictedNext) {
      // Update desktop and mobile text separately
      $againBtn.find('.again-text').text(`Again with ${meta.predictedNext.model}`);
      $againBtn.find('.again-text-mobile').text(meta.predictedNext.model);
      $againBtn.attr('data-next-model-id', meta.predictedNext.model_id);
    }
    
    // Populate dropdown with eligible models
    const $dropdownMenu = $messageItem.find('.js-again-menu');
    if (meta.eligible && meta.eligible.length > 0) {
      $dropdownMenu.empty();
      meta.eligible.forEach(model => {
        const $item = $(`
          <li><a class="dropdown-item js-model-choice d-flex align-items-center py-2 px-3" href="#" data-model-id="${model.model_id}" data-service="${model.service}" data-model="${model.model}" style="white-space: normal; line-height: 1.4;">
            <span class="d-inline-flex align-items-center justify-content-center bg-white border rounded p-1 me-2 flex-shrink-0 d-none dropdown-logo-wrapper">
              <img class="d-block dropdown-logo" width="12" height="12" alt="${model.service}">
            </span>
            <span class="text-truncate">
              <strong class="d-block">${model.service}</strong>
              <small class="text-muted">${model.model}</small>
            </span>
          </a></li>
        `);
        
        const $img = $item.find('.dropdown-logo');
        const $wrapper = $item.find('.dropdown-logo-wrapper');
        $img.on('load', function() {
          $wrapper.removeClass('d-none');
        }).on('error', function() {
          $wrapper.addClass('d-none');
        }).attr('src', `img/ai-logos/${model.service.toLowerCase()}.svg`);
        
        $dropdownMenu.append($item);
      });
    }
    
    // Update thread state if inId is available
    if (meta.inId) {
      updateThreadState(meta.inId);
    }
  }
}

// Note: hydrateAgainOptions removed - now handled entirely via SSE meta

// Thread state management using inId
function updateThreadState(inId) {
  if (!inId) {
    // Fallback: simple all-messages approach (only for current session messages)
    const $aiMessages = $('.message-item.ai-message').filter(function() {
      return $(this).find('.js-again').length > 0; // Only messages with Again functionality
    });
    if ($aiMessages.length <= 1) return;
    
    $aiMessages.each(function(index) {
      const $message = $(this);
      const isLast = (index === $aiMessages.length - 1);
      
      if (isLast) {
        $message.removeClass('opacity-75').find('.js-again, .js-again-dd').prop('disabled', false);
      } else {
        $message.addClass('opacity-75').find('.js-again, .js-again-dd').prop('disabled', true);
      }
    });
    return;
  }
  
  // Find all AI messages for this thread (same data-in-id) that have Again functionality
  const $threadMessages = $(`.message-item.ai-message[data-in-id="${inId}"]`).filter(function() {
    return $(this).find('.js-again').length > 0; // Only current session messages with Again buttons
  });
  
  if ($threadMessages.length <= 1) {
    return; // Single message, no state update needed
  }
  
  // Mark all but the last as stale
  $threadMessages.each(function(index) {
    const $message = $(this);
    const isLast = (index === $threadMessages.length - 1);
    
    if (isLast) {
      // Latest message: keep active
      $message.removeClass('opacity-75').find('.js-again, .js-again-dd').prop('disabled', false);
    } else {
      // Older messages: make stale (but keep copy button active)
      $message.addClass('opacity-75').find('.js-again, .js-again-dd').prop('disabled', true);
    }
  });
}

// Helper function to get appropriate file icon
function getFileIcon(fileType) {
    const iconMap = {
        'pdf': 'fas fa-file-pdf',
        'doc': 'fas fa-file-word',
        'docx': 'fas fa-file-word',
        'xls': 'fas fa-file-excel',
        'xlsx': 'fas fa-file-excel',
        'ppt': 'fas fa-file-powerpoint',
        'pptx': 'fas fa-file-powerpoint',
        'txt': 'fas fa-file-alt',
        'jpg': 'fas fa-file-image',
        'jpeg': 'fas fa-file-image',
        'png': 'fas fa-file-image',
        'gif': 'fas fa-file-image',
        'mp3': 'fas fa-file-audio',
        'wav': 'fas fa-file-audio',
        'mp4': 'fas fa-file-video',
        'avi': 'fas fa-file-video',
        'zip': 'fas fa-file-archive',
        'rar': 'fas fa-file-archive'
    };
    return iconMap[fileType.toLowerCase()] || 'fas fa-file';
}

// Function to inject file preview directly from SSE meta data
function injectFilePreview(outId, filePath, fileType) {
    if (!filePath || !fileType) {
        return;
    }
    
    // Check if file preview is already injected to prevent duplicates
    const messageElement = document.getElementById(outId);
    if (!messageElement || messageElement.querySelector('.generated-file-container')) {
        return; // Already has file preview or element not found
    }
    
    // Generate file preview HTML
    const fileUrl = "up/" + filePath;
    let fileHtml = '';
    
    if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(fileType.toLowerCase())) {
        fileHtml = `<div class='generated-file-container'><img src='${fileUrl}' class='generated-image' alt='Generated Image' loading='lazy'></div>`;
    } else if (['mp4', 'webm', 'mov', 'avi'].includes(fileType.toLowerCase())) {
        fileHtml = `<div class='generated-file-container'><video src='${fileUrl}' class='generated-video' controls preload='metadata'>Your browser does not support the video tag.</video></div>`;
    } else {
        // For other file types, show download link
        fileHtml = `<div class='generated-file-container'><a href='${fileUrl}' class='btn btn-outline-primary btn-sm' download><i class='fas fa-download'></i> Download ${fileType.toUpperCase()}</a></div>`;
    }
    
    // Inject file preview at the top of the message content
    if (fileHtml) {
        $(messageElement).prepend(fileHtml);
    }
}

// ------------------------------------------------------------
