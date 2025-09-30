<?php
// Chat History Log Page
// Allows users to browse through their complete chat history with pagination and filters

if (!isset($_SESSION['USERPROFILE'])) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['USERPROFILE']['BID'];
?>
<link rel="stylesheet" href="assets/statics/fa/css/all.min.css">
<link rel="stylesheet" href="node_modules/@highlightjs/cdn-assets/styles/googlecode.min.css">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-3" id="contentMain" style="background-color: #f5f5f5; min-height: 100vh;">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3">
        <h1 class="h2">
            <i class="fas fa-history text-primary"></i> Chat History Log
        </h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.location.href='index.php/chat'">
                <i class="fas fa-comments"></i> Back to Chat
            </button>
        </div>
    </div>
    
    <!-- Statistics Summary Card -->
    <div class="card mb-4 shadow-sm" style="background-color: white; border: none;">
        <div class="card-body py-4">
            <div class="row text-center" id="statsContainer">
                <div class="col-md-3">
                    <div class="text-muted small mb-2">Total Prompts</div>
                    <div class="h3 mb-0 text-primary" id="statTotalPrompts">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small mb-2">With Attachments</div>
                    <div class="h3 mb-0 text-info" id="statWithFiles">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small mb-2">Conversations</div>
                    <div class="h3 mb-0 text-success" id="statConversations">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small mb-2">Date Range</div>
                    <div class="h6 mb-0" id="statDateRange">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 shadow-sm" style="background-color: white; border: none;">
        <div class="card-header" style="background-color: white; border-bottom: 1px solid #e0e0e0;">
            <h5 class="card-title mb-0">
                <i class="fas fa-filter"></i> Search Filters
            </h5>
        </div>
        <div class="card-body">
            <form id="filterForm">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="filterKeyword" class="form-label"><strong>Keyword Search</strong></label>
                        <input type="text" class="form-control" id="filterKeyword" name="keyword" placeholder="Search in messages...">
                    </div>
                    <div class="col-md-2">
                        <label for="filterHasAttachments" class="form-label"><strong>Has Files</strong></label>
                        <select class="form-select" id="filterHasAttachments" name="hasAttachments">
                            <option value="">All</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filterDateFrom" class="form-label"><strong>From Date</strong></label>
                        <input type="date" class="form-control" id="filterDateFrom" name="dateFrom">
                    </div>
                    <div class="col-md-3">
                        <label for="filterDateTo" class="form-label"><strong>To Date</strong></label>
                        <input type="date" class="form-control" id="filterDateTo" name="dateTo">
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="clearFiltersBtn">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Chat History List -->
    <div id="historyContainer">
        <div class="text-center py-5">
            <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
            <p class="mt-3 text-muted">Loading chat history...</p>
        </div>
    </div>

    <!-- Pagination -->
    <nav aria-label="Chat history pagination" id="paginationContainer" style="display: none;">
        <ul class="pagination justify-content-center" id="paginationList">
        </ul>
    </nav>

</main>

<!-- Markdown and Highlight.js -->
<script src="node_modules/markdown-it/dist/markdown-it.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/highlight.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/php.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/json.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/javascript.min.js"></script>
<script src="node_modules/@highlightjs/cdn-assets/languages/python.min.js"></script>

<script>
// Initialize markdown parser
const md = window.markdownit({
    html: true,
    linkify: true,
    typographer: true,
    breaks: true,
    highlight: function (str, lang) {
        if (lang && hljs.getLanguage(lang)) {
            try {
                return hljs.highlight(str, { language: lang }).value;
            } catch (__) {}
        }
        return '';
    }
});

// Add link target attributes
md.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    const aIndex = tokens[idx].attrIndex('target');
    if (aIndex < 0) {
        tokens[idx].attrPush(['target', '_blank']);
    } else {
        tokens[idx].attrs[aIndex][1] = '_blank';
    }
    const relIndex = tokens[idx].attrIndex('rel');
    if (relIndex < 0) {
        tokens[idx].attrPush(['rel', 'noopener noreferrer']);
    } else {
        tokens[idx].attrs[relIndex][1] = 'noopener noreferrer';
    }
    return self.renderToken(tokens, idx, options);
};

let currentPage = 1;
let currentFilters = {};

// Load user statistics
function loadUserStats() {
    fetch('api.php?action=getUserStats')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.stats) {
                const stats = data.stats;
                document.getElementById('statTotalPrompts').textContent = stats.total_prompts || 0;
                document.getElementById('statWithFiles').textContent = stats.prompts_with_files || 0;
                document.getElementById('statConversations').textContent = stats.unique_conversations || 0;
                
                if (stats.first_message && stats.last_message) {
                    const firstDate = new Date(stats.first_message).toLocaleDateString();
                    const lastDate = new Date(stats.last_message).toLocaleDateString();
                    document.getElementById('statDateRange').textContent = firstDate + ' - ' + lastDate;
                } else {
                    document.getElementById('statDateRange').textContent = 'No messages yet';
                }
            }
        })
        .catch(error => {
            console.error('Error loading stats:', error);
        });
}

// Load chat history
function loadChatHistory(page = 1) {
    currentPage = page;
    
    const formData = new FormData();
    formData.append('action', 'getChatHistoryLog');
    formData.append('page', page);
    
    // Add filters
    for (const key in currentFilters) {
        if (currentFilters[key]) {
            formData.append(key, currentFilters[key]);
        }
    }
    
    document.getElementById('historyContainer').innerHTML = `
        <div class="text-center py-5">
            <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
            <p class="mt-3 text-muted">Loading chat history...</p>
        </div>
    `;
    
    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderChatHistory(data.prompts);
            renderPagination(data.pagination);
        } else {
            document.getElementById('historyContainer').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> Error loading chat history: ${data.error || 'Unknown error'}
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('historyContainer').innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> Error loading chat history: ${error.message}
            </div>
        `;
    });
}

// Render chat history items
function renderChatHistory(prompts) {
    const container = document.getElementById('historyContainer');
    
    if (prompts.length === 0) {
        container.innerHTML = `
            <div class="alert alert-info text-center" style="background-color: white;">
                <i class="fas fa-info-circle"></i> No messages found matching your filters.
            </div>
        `;
        return;
    }
    
    let html = '';
    
    prompts.forEach(prompt => {
        const hasFiles = prompt.files && prompt.files.length > 0;
        
        html += `
            <div class="chat-history-item-wrapper mb-4">
                <!-- User Prompt Card -->
                <div class="card shadow-sm chat-history-item" style="background-color: white; border: none;">
                    <div class="card-body p-4">
                        <!-- Meta Information Pills at Top -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge rounded-pill bg-secondary" style="font-size: 0.75rem; font-weight: normal;">
                                    <i class="fas fa-user"></i> User Request
                                </span>
                                <span class="badge rounded-pill bg-secondary" style="font-size: 0.75rem; font-weight: normal;">
                                    <i class="fas fa-tag"></i> ${prompt.topic || 'general'}
                                </span>
                                ${hasFiles ? '<span class="badge rounded-pill bg-secondary" style="font-size: 0.75rem; font-weight: normal;"><i class="fas fa-paperclip"></i> ' + prompt.files.length + ' file(s)</span>' : ''}
                            </div>
                            <span class="badge rounded-pill bg-light text-muted" style="font-size: 0.75rem; font-weight: normal;">
                                <i class="fas fa-clock"></i> ${prompt.datetime}
                            </span>
                        </div>
                        
                        <!-- Prompt Text - Large and Left-Aligned -->
                        <div class="py-3">
                            <p class="mb-0" style="font-size: 1.15rem; line-height: 1.6; color: #000000; font-weight: 500;">
                                ${escapeHtml(prompt.text)}
                            </p>
                        </div>
                        
                        <!-- Attached Files -->
                        ${hasFiles ? `
                            <div class="mt-3 pt-3" style="border-top: 1px solid #e9ecef;">
                                <div class="d-flex flex-wrap gap-2">
                                    ${prompt.files.map(file => `
                                        <button class="btn btn-sm btn-outline-secondary rounded-pill" onclick="downloadFile('${file.path}')" style="font-size: 0.8rem;">
                                            <i class="fas fa-file"></i> ${file.name}
                                        </button>
                                    `).join('')}
                                </div>
                            </div>
                        ` : ''}
                        
                        <!-- Load Answer Button -->
                        <div class="mt-3">
                            <button class="btn btn-outline-primary btn-sm rounded-pill load-answer-btn" 
                                    data-prompt-id="${prompt.id}"
                                    onclick="toggleAnswers(${prompt.id}, this)">
                                <i class="fas fa-chevron-down"></i> Load AI Answer
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Answer Container (Hidden by default, separate card) -->
                <div id="answer-${prompt.id}" class="mt-3" style="display: none;">
                    <div class="text-center py-3">
                        <i class="fas fa-spinner fa-spin"></i> Loading answer...
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// Toggle answers visibility
function toggleAnswers(promptId, button) {
    const answerDiv = document.getElementById('answer-' + promptId);
    const icon = button.querySelector('i');
    
    if (answerDiv.style.display === 'none') {
        // Load and show answers
        answerDiv.style.display = 'block';
        icon.className = 'fas fa-chevron-up';
        button.innerHTML = '<i class="fas fa-chevron-up"></i> Hide AI Answer';
        
        loadAnswers(promptId);
    } else {
        // Hide answers
        answerDiv.style.display = 'none';
        icon.className = 'fas fa-chevron-down';
        button.innerHTML = '<i class="fas fa-chevron-down"></i> Load AI Answer';
    }
}

// Helper function to check if text is valid JSON
function isValidJson(text) {
    try {
        JSON.parse(text);
        return true;
    } catch (e) {
        return false;
    }
}

// Helper function to parse and format JSON response
function parseJsonResponse(text) {
    try {
        // Normalize quotes first (handle smart quotes)
        const normalizedText = text
            .replace(/[\u201C\u201D\u201E\u00AB\u00BB]/g, '"')
            .replace(/[\u2018\u2019]/g, "'");
        
        // Try to find JSON in the text (look for the last complete JSON object)
        const jsonMatch = normalizedText.match(/\{[\s\S]*\}/);
        if (!jsonMatch) {
            return { isJson: false, formatted: text };
        }
        
        const jsonData = JSON.parse(jsonMatch[0]);
        
        // Check if this looks like a database record (has BID, BUSERID, etc.)
        // If so, only extract the relevant display fields
        const isDbRecord = jsonData.BID || jsonData.BUSERID || jsonData.BDATETIME;
        
        // Extract main components
        const btext = jsonData.BTEXT || '';
        const bfiletext = jsonData.BFILETEXT || '';
        const bfilepath = jsonData.BFILEPATH || '';
        const bfiletype = jsonData.BFILETYPE || '';
        
        // If there's no content to display, return as non-JSON
        if (!btext && !bfiletext && !bfilepath) {
            return { isJson: false, formatted: text };
        }
        
        // Build formatted response
        let formatted = '';
        
        // Add BTEXT (main response text) - this is the primary content
        if (btext) {
            formatted += btext;
        }
        
        // Add shortened BFILETEXT if present and not empty
        if (bfiletext && bfiletext.trim().length > 0) {
            const maxLength = 300;
            const shortened = bfiletext.length > maxLength 
                ? bfiletext.substring(0, maxLength) + '...' 
                : bfiletext;
            
            formatted += '\n\n<div class="mt-3 p-3 rounded" style="background-color: #f8f9fa; border-left: 3px solid #6c757d;">';
            formatted += '<div class="d-flex align-items-center mb-2">';
            formatted += '<i class="fas fa-file-alt text-muted me-2"></i>';
            formatted += '<small class="text-muted fw-bold">Extracted File Content</small>';
            formatted += '</div>';
            formatted += '<div style="font-size: 0.85rem; line-height: 1.5; color: #495057; white-space: pre-wrap; font-family: monospace;">' + escapeHtml(shortened) + '</div>';
            formatted += '</div>';
        }
        
        // Add inline file preview if file was generated
        if (bfilepath && bfilepath.trim().length > 0) {
            const fileUrl = 'up/' + bfilepath;
            const fileType = (bfiletype || '').toLowerCase();
            
            if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(fileType)) {
                formatted += '\n\n<div class="generated-file-container mt-3 text-center">';
                formatted += '<img src="' + fileUrl + '" class="generated-image img-fluid rounded" alt="Generated Image" loading="lazy" style="max-width: 100%; height: auto; box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.1);">';
                formatted += '</div>';
            } else if (['mp4', 'webm', 'mov', 'avi'].includes(fileType)) {
                formatted += '\n\n<div class="generated-file-container mt-3">';
                formatted += '<video src="' + fileUrl + '" class="generated-video img-fluid rounded" controls preload="metadata" style="max-width: 100%; height: auto; box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.1);">Your browser does not support the video tag.</video>';
                formatted += '</div>';
            } else if (fileType) {
                // For other file types, show download link
                const fileName = bfilepath.split('/').pop();
                formatted += '\n\n<div class="mt-3">';
                formatted += '<a href="' + fileUrl + '" class="btn btn-outline-primary btn-sm" download>';
                formatted += '<i class="fas fa-download me-1"></i> Download ' + escapeHtml(fileName);
                formatted += '</a>';
                formatted += '</div>';
            }
        }
        
        // If formatted is empty (no relevant content), return original text
        if (!formatted.trim()) {
            return { isJson: false, formatted: text };
        }
        
        return { isJson: true, formatted: formatted, isDbRecord: isDbRecord };
    } catch (e) {
        console.log('JSON parsing failed:', e);
        return { isJson: false, formatted: text };
    }
}

// Load answers for a specific prompt
function loadAnswers(promptId) {
    const answerDiv = document.getElementById('answer-' + promptId);
    
    const formData = new FormData();
    formData.append('action', 'getAnswersForPrompt');
    formData.append('promptId', promptId);
    
    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.answers && data.answers.length > 0) {
            let html = '';
            data.answers.forEach(answer => {
                // Check if answer is JSON and parse it
                const parsed = parseJsonResponse(answer.text);
                const displayText = parsed.formatted;
                
                // Render markdown
                const renderedText = md.render(displayText);
                
                html += `
                    <div class="card shadow-sm" style="background-color: white; border: none; border-left: 4px solid #28a745;">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <span class="badge rounded-pill bg-success" style="font-size: 0.75rem; font-weight: normal;">
                                        <i class="fas fa-robot"></i> AI Response
                                    </span>
                                    ${parsed.isJson && parsed.isDbRecord ? '<span class="badge rounded-pill bg-primary" style="font-size: 0.7rem; font-weight: normal;"><i class="fas fa-database"></i> Parsed</span>' : ''}
                                    ${parsed.isJson && !parsed.isDbRecord ? '<span class="badge rounded-pill bg-info" style="font-size: 0.7rem; font-weight: normal;"><i class="fas fa-code"></i> Structured</span>' : ''}
                                </div>
                                <span class="badge rounded-pill bg-light text-muted" style="font-size: 0.75rem; font-weight: normal;">
                                    <i class="fas fa-clock"></i> ${answer.datetime}
                                </span>
                            </div>
                            <div class="ai-response-content" style="font-size: 1rem; line-height: 1.7; color: #000000;">
                                ${renderedText}
                            </div>
                            ${answer.status ? '<div class="mt-3 pt-2" style="border-top: 1px solid #e9ecef;"><span class="badge rounded-pill bg-light text-muted" style="font-size: 0.7rem;">Status: ' + answer.status + '</span></div>' : ''}
                        </div>
                    </div>
                `;
            });
            answerDiv.innerHTML = html;
            
            // Apply syntax highlighting
            answerDiv.querySelectorAll('pre code').forEach((block) => {
                hljs.highlightBlock(block);
            });
        } else {
            answerDiv.innerHTML = `
                <div class="card shadow-sm" style="background-color: white; border: none;">
                    <div class="card-body">
                        <div class="alert alert-warning mb-0" style="border: none; background-color: #fff3cd;">
                            <i class="fas fa-info-circle"></i> No AI response found for this message.
                        </div>
                    </div>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error loading answers:', error);
        answerDiv.innerHTML = `
            <div class="card shadow-sm" style="background-color: white; border: none;">
                <div class="card-body">
                    <div class="alert alert-danger mb-0" style="border: none;">
                        <i class="fas fa-exclamation-triangle"></i> Error loading answer: ${error.message}
                    </div>
                </div>
            </div>
        `;
    });
}

// Render pagination
function renderPagination(pagination) {
    const container = document.getElementById('paginationContainer');
    const list = document.getElementById('paginationList');
    
    if (pagination.totalPages <= 1) {
        container.style.display = 'none';
        return;
    }
    
    container.style.display = 'block';
    
    let html = '';
    
    // Previous button
    html += `
        <li class="page-item ${!pagination.hasPrevPage ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadChatHistory(${pagination.currentPage - 1}); return false;">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
        </li>
    `;
    
    // Page numbers (show max 5 pages at a time)
    const startPage = Math.max(1, pagination.currentPage - 2);
    const endPage = Math.min(pagination.totalPages, pagination.currentPage + 2);
    
    if (startPage > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="loadChatHistory(1); return false;">1</a></li>`;
        if (startPage > 2) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `
            <li class="page-item ${i === pagination.currentPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="loadChatHistory(${i}); return false;">${i}</a>
            </li>
        `;
    }
    
    if (endPage < pagination.totalPages) {
        if (endPage < pagination.totalPages - 1) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        html += `<li class="page-item"><a class="page-link" href="#" onclick="loadChatHistory(${pagination.totalPages}); return false;">${pagination.totalPages}</a></li>`;
    }
    
    // Next button
    html += `
        <li class="page-item ${!pagination.hasNextPage ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadChatHistory(${pagination.currentPage + 1}); return false;">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    `;
    
    list.innerHTML = html;
}

// Download file helper
function downloadFile(filePath) {
    window.open('up/' + filePath, '_blank');
}

// HTML escape helper
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize page
$(document).ready(function() {
    // Load initial data
    loadUserStats();
    loadChatHistory(1);
    
    // Filter form submission
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentFilters = {
            keyword: $('#filterKeyword').val(),
            hasAttachments: $('#filterHasAttachments').val(),
            dateFrom: $('#filterDateFrom').val(),
            dateTo: $('#filterDateTo').val()
        };
        loadChatHistory(1);
    });
    
    // Clear filters
    $('#clearFiltersBtn').on('click', function() {
        $('#filterForm')[0].reset();
        currentFilters = {};
        loadChatHistory(1);
    });
});
</script>

<style>
body {
    background-color: #e8e8e8;
}

#contentMain {
    background-color: #e8e8e8 !important;
}

.chat-history-item-wrapper {
    transition: transform 0.2s;
}

.chat-history-item {
    transition: box-shadow 0.3s, transform 0.2s;
    border-radius: 0.5rem;
    background-color: #ffffff !important;
}

.chat-history-item:hover {
    box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,0.15) !important;
    transform: translateY(-2px);
}

.card {
    background-color: #ffffff !important;
    border-radius: 0.5rem;
}

.card-body {
    color: #000000;
}

.ai-response-content {
    max-height: none;
    overflow-y: auto;
    color: #000000;
}

.ai-response-content p {
    margin-bottom: 1rem;
    color: #000000;
}

.ai-response-content img {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
    margin: 1rem 0;
}

.ai-response-content pre {
    background-color: #f8f9fa;
    padding: 1.25rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    border: 1px solid #e9ecef;
}

.ai-response-content code {
    font-size: 0.9rem;
    color: #000000;
}

.ai-response-content h1,
.ai-response-content h2,
.ai-response-content h3,
.ai-response-content h4,
.ai-response-content h5,
.ai-response-content h6 {
    margin-top: 1.5rem;
    margin-bottom: 1rem;
    font-weight: 600;
    color: #000000;
}

.ai-response-content ul,
.ai-response-content ol {
    padding-left: 2rem;
    margin-bottom: 1rem;
    color: #000000;
}

.ai-response-content li {
    color: #000000;
}

.ai-response-content blockquote {
    border-left: 4px solid #dee2e6;
    padding-left: 1rem;
    margin-left: 0;
    color: #333333;
}

.ai-response-content strong,
.ai-response-content b {
    color: #000000;
}

.badge.rounded-pill {
    padding: 0.35em 0.75em;
}

.pagination .page-link {
    border-radius: 0.375rem;
    margin: 0 0.125rem;
}

/* Loading spinner styling */
.text-center .fa-spinner {
    color: #6c757d;
}

/* Ensure text in all elements is dark */
.chat-history-item .card-body p,
.chat-history-item .card-body div,
.chat-history-item .card-body span:not(.badge) {
    color: #000000;
}

/* Generated file containers */
.generated-file-container {
    margin: 1rem 0;
}

.generated-image,
.generated-video {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
}

/* File content preview box */
.ai-response-content .bg-light {
    background-color: #f8f9fa !important;
}
</style>
