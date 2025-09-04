<?php
// this page can be included in the widget loader page
// or as part of the admin section of synaplan
// We have to set the values and the prompt config correctly.
//
// CHAT HISTORY LOADING:
// The chat history is now loaded dynamically via API calls instead of being
// rendered server-side on page load. Users see three buttons (10, 20, 30 messages)
// and can click one to load the desired amount of chat history. This improves
// page load performance and provides better user control over history loading.
//
// API Endpoint: api.php?action=loadChatHistory&amount={10|20|30}
// Backend Method: Frontend::loadChatHistory($amount)
// JavaScript: js/chathistory.js - handles the API calls and rendering
// Dependencies: jQuery, markdown-it, highlight.js, Font Awesome

// Check if this is widget mode
$isWidgetMode = isset($_SESSION['WIDGET_PROMPT']);
$widgetPrompt = $_SESSION['WIDGET_PROMPT'] ?? 'general';
$widgetAutoMessage = $_SESSION['WIDGET_AUTO_MESSAGE'] ?? '';

// Check if this is anonymous widget mode
$isAnonymousWidget = isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true;
?>
<link rel="stylesheet" href="fa/css/all.min.css">
<!-- Add highlight.js CSS -->
<link rel="stylesheet" href="node_modules/@highlightjs/cdn-assets/styles/googlecode.min.css">
<script>
// Initialize widget flags early so downstream scripts (chat.js) can read them
window.isAnonymousWidget = <?php echo $isAnonymousWidget ? 'true' : 'false'; ?>;
window.isWidgetMode = <?php echo $isWidgetMode ? 'true' : 'false'; ?>;
</script>
<main class="col-md-9 ms-sm-auto col-lg-10 px-1 px-md-3 py-2 py-md-1 content-main-bg" id="contentMain">
    <!-- Chat Page Header (now inside main, above chat-container) -->
    <?php if (!$isWidgetMode): ?>
    <div class="chat-page-header">
        <div style="flex: 1; min-width: 280px;">
            <div class="dropdown custom-prompt-dropdown">
                <button class="btn btn-light dropdown-toggle" type="button" id="promptDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="dropdown-main">🤖 Default AI Assistant</span>
                    <span class="dropdown-desc">General-purpose AI assistant for all tasks</span>
                </button>
                <ul class="dropdown-menu" aria-labelledby="promptDropdownBtn">
                    <li>
                        <a class="dropdown-item" href="#" data-value="tools:sort">
                            <span class="dropdown-main">🤖 Default AI Assistant</span>
                            <span class="dropdown-desc">General-purpose AI assistant for all tasks</span>
                        </a>
                    </li>
                    <?php
                        $prompts = BasicAI::getAllPrompts();
                        foreach($prompts as $prompt) {
                            $ownerHint = $prompt['BOWNERID'] != 0 ? "👤" : "🏢";
                            $desc = htmlspecialchars(substr($prompt['BSHORTDESC'],0,84));
                            echo "<li>
                                <a class='dropdown-item' href='#' data-value='".htmlspecialchars($prompt['BTOPIC'])."'>
                                    <span class='dropdown-main'>{$ownerHint} {$prompt['BTOPIC']}</span>
                                    <span class='dropdown-desc'>{$desc}</span>
                                </a>
                            </li>";
                        }
                    ?>
                </ul>
                <input type="hidden" name="promptConfigSelect" id="promptConfigSelect" value="tools:sort">
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- Modern Chat Interface -->
    <div class="chat-container">
        <!-- Enhanced chatbox with shadow and modern styling -->
        <div class="chatbox">
            <div class="chat-messages" id="chatModalBody">
                <div class="messages-container">
                    <!-- Chat History Loading Buttons -->
                    <?php if (!$isAnonymousWidget): ?>
                    <div id="chatHistoryButtons" class="py-4">
                        <div class="d-flex justify-content-between">
                            <div class="d-flex gap-3">
                                <button type="button" class="btn btn-outline-primary btn-sm load-history-btn m-2" data-amount="10">
                                    <i class="fas fa-clock me-1"></i>
                                    <span class="badge bg-primary">10</span>
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm load-history-btn m-2" data-amount="20">
                                    <i class="fas fa-clock me-1"></i>
                                    <span class="badge bg-primary">20</span>
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm load-history-btn m-2" data-amount="30">
                                    <i class="fas fa-clock me-1"></i>
                                    <span class="badge bg-primary">30</span>
                                </button>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm hide-history-btn m-2" title="Hide chat history options">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <small class="text-muted m-2">Click to load chat history</small>
                    </div>
                    <?php endif; ?>
                    
                    <ul id="chatHistory">
                        <!-- Chat messages will be loaded here via API -->
                    </ul>
                </div>
            </div>
                    <!-- Enhanced Chat Input Area -->
                    <div class="chat-input-container px-2 px-md-3 py-2 py-md-3">
                        <!-- Enhanced File Preview Area (Overlay) -->
                        <div id="filesDiv" class="file-preview-container">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-paperclip text-primary me-2"></i>
                                    <h6 class="mb-0 text-primary fw-bold">Attached Files</h6>
                                </div>
                                <button type="button" id="closeFilesDiv" class="btn btn-sm btn-outline-danger close-files-btn">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            
                            <div class="file-actions mb-3">
                                <button type="button" id="manualFileSelect" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-plus me-1"></i> Add More Files
                                </button>
                                <span id="loader" class="ms-2 text-muted hidden">
                                    <i class="fas fa-spinner fa-spin"></i> Processing...
                                </span>
                            </div>
                            
                            <input id="fileInput" type="file" multiple style="display:none;" />
                            
                            <!-- Enhanced File Preview Grid -->
                            <div id="filePreview" class="file-preview-grid"></div>
                        </div>

                        <form id="chatForm" enctype="multipart/form-data">

                            <!-- Enhanced Input Controls -->
                            <div class="input-controls-wrapper">
                                
                                <!-- Action Buttons -->
                                <div class="action-buttons d-flex gap-1">
                                    <button type="button" id="attachButton" class="btn btn-light attach-btn">
                                        <i class="fas fa-paperclip text-primary"></i>
                                    </button>
                                    <?php if (!$isAnonymousWidget): ?>
                                    <button type="button" id="speakButton" class="btn btn-light d-none d-md-flex speak-btn" title="Hold and speak">
                                        <i class="fas fa-microphone text-success"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>

                                <!-- Message Input -->
                                <div class="message-input-wrapper">
                                    <div id="messageInput" 
                                        class="message-input" 
                                        contenteditable="true" 
                                        placeholder="Type your message...">
                                    </div>
                                </div>

                                <!-- Send Button -->
                                <button type="button" id="sendButton" class="btn btn-primary send-btn">
                                    <i class="fas fa-paper-plane text-white"></i>
                                </button>
                            </div>
                        </form>
                    </div>
        </div>
    </div>
</main>
<script src="node_modules/markdown-it/dist/markdown-it.min.js"></script>
<!-- Add highlight.js JS -->
<script src="node_modules/@highlightjs/cdn-assets/highlight.min.js"></script> 
<script src="node_modules/@highlightjs/cdn-assets/languages/php.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/json.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/javascript.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/python.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/java.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/cpp.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/csharp.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/sql.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/bash.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/css.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/xml.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/sql.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/go.min.js"></script>
<script src="js/speech.js?v=<?php echo filemtime('js/speech.js'); ?>"></script>
<script src="js/chat.js?v=<?php echo filemtime('js/chat.js'); ?>"></script>
<script src="js/chathistory.js?v=<?php echo filemtime('js/chathistory.js'); ?>"></script>

<script>
    // enable everything
    const md = window.markdownit({
        html: true,
        linkify: true,
        typographer: true,
        breaks: true,
    });
    // After: const md = window.markdownit({ ... });
    md.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    // If the link does not already have target, add target="_blank"
    const aIndex = tokens[idx].attrIndex('target');
    if (aIndex < 0) {
        tokens[idx].attrPush(['target', '_blank']); // add new attribute
    } else {
        tokens[idx].attrs[aIndex][1] = '_blank';    // replace value
    }
    // Add rel="noopener noreferrer" for security
    const relIndex = tokens[idx].attrIndex('rel');
    if (relIndex < 0) {
        tokens[idx].attrPush(['rel', 'noopener noreferrer']);
    } else {
        tokens[idx].attrs[relIndex][1] = 'noopener noreferrer';
    }
    return self.renderToken(tokens, idx, options);
    };

    // Make md globally accessible for chat.js
    window.md = md;

    // Global variable to store current prompt configuration
    let currentPromptConfig = null;

    // Called when prompt configuration dropdown changes
    function onPromptConfigChange() {
        const promptSelect = document.getElementById('promptConfigSelect');
        if (!promptSelect) {
            console.log("Prompt config select not found (widget mode)");
            return;
        }
        const selected = promptSelect.value;
        console.log("Selected prompt config:", selected);
        
        // Fetch prompt configuration details including tools and AI model
        const formData = new FormData();
        formData.append('action', 'getPromptDetails');
        formData.append('promptKey', selected);

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Error:', data.error);
                alert('Error loading prompt configuration: ' + data.error);
            } else {
                // Store the complete configuration for future use
                currentPromptConfig = {
                    BTOPIC: data.BTOPIC,
                    BPROMPT: data.BPROMPT,
                    BSHORTDESC: data.BSHORTDESC,
                    SETTINGS: data.SETTINGS || []
                };
                
                console.log("Loaded prompt config:", currentPromptConfig);
                
                // You can add UI updates here if needed
                // For example, showing which tools are enabled, current AI model, etc.
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while loading the prompt configuration');
        });
    }

    // Function to get current prompt configuration
    function getCurrentPromptConfig() {
        return currentPromptConfig;
    }

    // Widget mode detection - define this globally before other scripts
    window.isAnonymousWidget = <?php echo $isAnonymousWidget ? 'true' : 'false'; ?>;
    window.isWidgetMode = <?php echo $isWidgetMode ? 'true' : 'false'; ?>;

    // Widget-specific functionality
    <?php if ($isWidgetMode): ?>
    // Style override for widget meta line
    const style = document.createElement('style');
    style.textContent = `
      /* Only style the widget meta label, not other text like inputs */
      .message-footer .js-ai-meta .widget-meta {
        color: #6c757d !important;
        font-size: 0.85rem !important;
      }

      /* Widget-mode font scaling */
      html, body, #contentMain {
        font-size: 90% !important;
      }
      h1, h2 {
        font-size: 70% !important;
      }

      /* Hide AI avatar in widget mode to maximize message width */
      .ai-avatar {
        display: none !important;
      }
    `;
    document.head.appendChild(style);

    // Set the widget prompt as the current prompt configuration
    // Only need the topic/ID - the backend handles the actual prompt text
    currentPromptConfig = {
        BTOPIC: '<?php echo htmlspecialchars($widgetPrompt); ?>',
        BPROMPT: '', // Not needed - backend uses session
        BSHORTDESC: 'Widget Prompt',
        SETTINGS: []
    };

    // Display auto message as static welcome message if configured
    <?php if (!empty($widgetAutoMessage)): ?>
    $(document).ready(function() {
        setTimeout(function() {
            // Add the auto message as a static AI message (not sent via API)
            $("#chatHistory").append(`
                <li class="message-item ai-message">
                    <div class="ai-avatar">
                        <i class="fas fa-robot text-white"></i>
                    </div>
                    <div class="message-content">
                        <div class="message-bubble ai-bubble">
                            <div class="message-content"><?php echo htmlspecialchars($widgetAutoMessage); ?></div>
                            <span class="message-time ai-time">${new Date().toLocaleTimeString()}</span>
                        </div>
                    </div>
                </li>
            `);
            // Scroll to show the welcome message
            $("#chatModalBody").scrollTop($("#chatModalBody").prop("scrollHeight"));
        }, 500); // Wait 0.5 seconds after page load
    });
    <?php endif; ?>
    <?php endif; ?>
</script>

<script>
    // when document is ready, initialize prompt configuration
    $(document).ready(function() {
        // Only call onPromptConfigChange if it exists AND not in widget mode
        if (typeof onPromptConfigChange === 'function' && !window.isWidgetMode) {
            onPromptConfigChange();
        }
        // No need to scroll to bottom since chat history is loaded via buttons now
    });
</script>

<script>
// Only add dropdown event listeners if the dropdown exists (not in widget mode)
const dropdownItems = document.querySelectorAll('.custom-prompt-dropdown .dropdown-item');
if (dropdownItems.length > 0) {
    dropdownItems.forEach(function(item) {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            // Update button text
            var main = this.querySelector('.dropdown-main').textContent;
            var desc = this.querySelector('.dropdown-desc').textContent;
            const dropdownBtn = document.getElementById('promptDropdownBtn');
            if (dropdownBtn) {
                dropdownBtn.innerHTML =
                    '<span class="dropdown-main">' + main + '</span>' +
                    '<span class="dropdown-desc">' + desc + '</span>';
            }
            // Update hidden input
            const promptSelect = document.getElementById('promptConfigSelect');
            if (promptSelect) {
                promptSelect.value = this.getAttribute('data-value');
                // Optionally, trigger your config change logic
                if (typeof onPromptConfigChange === 'function') onPromptConfigChange();
            }
        });
    });
}
</script>
