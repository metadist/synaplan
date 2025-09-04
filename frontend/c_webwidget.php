<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Chat Widget Configuration</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/chat'">
                    <i class="fas fa-comments"></i> Chat
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/tools'">
                    <i class="fas fa-tools"></i> Tools
                </button>
            </div>
        </div>
    </div>

    <!-- Widget List Section -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-list"></i> Your Widgets</h5>
        </div>
        <div class="card-body">
            <div id="widgetList">
                <!-- Widget list will be loaded here -->
            </div>
            <div class="mt-3">
                <button type="button" class="btn btn-primary" id="createNewWidgetBtn">
                    <i class="fas fa-plus"></i> Create New Widget
                </button>
            </div>
        </div>
    </div>

    <!-- Widget Configuration Form -->
    <form id="webwidgetForm" method="POST" action="index.php/webwidget" style="display: none;">
        <input type="hidden" name="action" id="action" value="updateWebwidget">
        <input type="hidden" name="widgetId" id="widgetId" value="">
        
        <!-- Widget Configuration Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-cog"></i> Widget Configuration</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="widgetColor" class="col-sm-2 col-form-label"><strong>Primary Color:</strong></label>
                    <div class="col-sm-4">
                        <input type="color" class="form-control form-control-color" name="widgetColor" id="widgetColor" value="#007bff">
                        <div class="form-text">Color for the chat button</div>
                    </div>
                    <label for="widgetPosition" class="col-sm-2 col-form-label"><strong>Position:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="widgetPosition" id="widgetPosition">
                            <option value="bottom-right">Bottom Right</option>
                            <option value="bottom-left">Bottom Left</option>
                            <option value="bottom-center">Bottom Center</option>
                        </select>
                        <div class="form-text">Position of the chat button</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="autoMessage" class="col-sm-2 col-form-label"><strong>Auto Message:</strong></label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="autoMessage" id="autoMessage" placeholder="Hello! How can I help you today?" value="Hello! How can I help you today?">
                        <div class="form-text">Automated first message shown to visitors</div>
                    </div>
                    <label for="autoOpen" class="col-sm-2 col-form-label"><strong>Auto-open Popup:</strong></label>
                    <div class="col-sm-4 d-flex align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="autoOpen" name="autoOpen">
                            <label class="form-check-label" for="autoOpen">
                                Open automatically after a few seconds
                            </label>
                        </div>
                    </div>
                </div>
                <div class="row mb-3">
                    <label for="widgetPrompt" class="col-sm-2 col-form-label"><strong>AI Prompt:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="widgetPrompt" id="widgetPrompt">
                            <?php
                                $prompts = BasicAI::getAllPrompts();
                                foreach($prompts as $prompt) {
                                    $ownerHint = $prompt['BOWNERID'] != 0 ? "(custom)" : "(default)";
                                    echo "<option value='".$prompt['BTOPIC']."'>".$ownerHint." ".$prompt['BTOPIC']."</option>";
                                }
                            ?>
                        </select>
                        <div class="form-text">Select the AI prompt to use for this widget</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Integration Code Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-code"></i> Integration Code</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="integrationCode" class="col-sm-2 col-form-label"><strong>Embed Code:</strong></label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="integrationCode" id="integrationCode" rows="6" readonly>
<!-- Synaplan Chat Widget -->
<script>
(function() {
    var script = document.createElement('script');
    script.src = '<?php echo $GLOBALS["baseUrl"]; ?>widget.php?uid=${userId}&widgetid=${widgetId}';
    script.async = true;
    document.head.appendChild(script);
})();
</script>
                        </textarea>
                        <div class="form-text">Copy this code to your website's &lt;head&gt; section</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-sm-10 offset-sm-2">
                        <button type="button" class="btn btn-outline-primary" id="copyToClipboardBtn">
                            <i class="fas fa-copy"></i> Copy Code
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="generateNewCodeBtn">
                            <i class="fas fa-refresh"></i> Generate New Code
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="card">
            <div class="card-body text-center">
                <div class="btn-group" role="group" aria-label="Webwidget actions">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save"></i> Save Widget
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" id="cancelEditBtn">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-info btn-lg" id="previewWidgetBtn">
                        <i class="fas fa-eye"></i> Preview Widget
                    </button>
                </div>
                <div class="mt-3">
                    <small class="text-muted">Widget configuration will be applied to all pages where the integration code is installed.</small>
                </div>
            </div>
        </div>
    </form>
</main>

<script>
    try {
        console.log('WebWidget script loading...');
        
        let currentWidgetId = null;
        let widgets = [];

        // Load widgets when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, loading widgets...');
            loadWidgets();

            // Add event listener for create new widget button
            const createBtn = document.getElementById('createNewWidgetBtn');
            if (createBtn) {
                createBtn.addEventListener('click', createNewWidget);
            }

            // Add event listeners for copy and generate new code buttons
            const copyBtn = document.getElementById('copyToClipboardBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', copyToClipboard);
            }
            
            const generateBtn = document.getElementById('generateNewCodeBtn');
            if (generateBtn) {
                generateBtn.addEventListener('click', generateNewCode);
            }

            // Add event listeners for cancel and preview buttons
            const cancelBtn = document.getElementById('cancelEditBtn');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', cancelEdit);
            }
            
            const previewBtn = document.getElementById('previewWidgetBtn');
            if (previewBtn) {
                previewBtn.addEventListener('click', previewWidget);
            }
            
            // Handle form submission
            const webwidgetForm = document.getElementById('webwidgetForm');
            if (webwidgetForm) {
                webwidgetForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    formData.append('action', 'saveWidget');

                    fetch('api.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            showAlert('Error saving widget: ' + data.error, 'danger');
                        } else {
                            showAlert('Widget saved successfully', 'success');
                            loadWidgets();
                            cancelEdit();
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showAlert('An error occurred while saving the widget', 'danger');
                    });
                });
            }

            // Update integration code when widget ID changes
            const widgetIdInput = document.getElementById('widgetId');
            if (widgetIdInput) {
                widgetIdInput.addEventListener('change', updateIntegrationCode);
            }
        });

    // Function to load all widgets for the current user
    function loadWidgets() {
        console.log('Loading widgets...');
        const formData = new FormData();
        formData.append('action', 'getWidgets');

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Error:', data.error);
                showAlert('Error loading widgets: ' + data.error, 'danger');
            } else {
                widgets = data.widgets || [];
                console.log('Widgets loaded:', widgets);
                displayWidgets();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('An error occurred while loading widgets', 'danger');
        });
    }

    // Function to display widgets in the list
    function displayWidgets() {
        const widgetList = document.getElementById('widgetList');
        
        if (widgets.length === 0) {
            widgetList.innerHTML = '<div class="alert alert-info">No widgets created yet. Click "Create New Widget" to get started.</div>';
            return;
        }

        let html = '<div class="row">';
        widgets.forEach((widget, index) => {
            html += `
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">Widget ${widget.widgetId}</h6>
                            <p class="card-text">
                                <small class="text-muted">
                                    <strong>User ID:</strong> ${widget.userId}<br>
                                    <strong>Color:</strong> <span style="color: ${widget.color};">${widget.color}</span><br>
                                    <strong>Position:</strong> ${widget.position}<br>
                                    <strong>Prompt:</strong> ${widget.prompt}<br>
                                    <strong>Auto Message:</strong> ${widget.autoMessage ? 'Yes' : 'No'}<br>
                                    <strong>Auto-open:</strong> ${widget.autoOpen == '1' ? 'Enabled' : 'Disabled'}
                                </small>
                            </p>
                        </div>
                        <div class="card-footer">
                            <div class="btn-group btn-group-sm w-100" role="group">
                                <button type="button" class="btn btn-outline-primary edit-widget-btn" data-widget-id="${widget.widgetId}">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="btn btn-outline-danger delete-widget-btn" data-widget-id="${widget.widgetId}">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        widgetList.innerHTML = html;
        
        // Add event listeners for edit and delete buttons
        document.querySelectorAll('.edit-widget-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const widgetId = parseInt(this.getAttribute('data-widget-id'));
                editWidget(widgetId);
            });
        });
        
        document.querySelectorAll('.delete-widget-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const widgetId = parseInt(this.getAttribute('data-widget-id'));
                deleteWidget(widgetId);
            });
        });
    }

    // Function to create a new widget
    function createNewWidget() {
        console.log('createNewWidget called');
        // Find the next available widget ID
        const usedIds = widgets.map(w => w.widgetId);
        let newId = 1;
        while (usedIds.includes(newId)) {
            newId++;
        }
        
        if (newId > 9) {
            showAlert('Maximum of 9 widgets allowed', 'warning');
            return;
        }

        currentWidgetId = newId;
        document.getElementById('widgetId').value = newId;
        document.getElementById('widgetColor').value = '#007bff';
        document.getElementById('widgetPosition').value = 'bottom-right';
        document.getElementById('autoMessage').value = 'Hello! How can I help you today?';
        document.getElementById('widgetPrompt').value = 'general';
        const autoOpenEl = document.getElementById('autoOpen');
        if (autoOpenEl) autoOpenEl.checked = false; // default off
        
        updateIntegrationCode();
        document.getElementById('webwidgetForm').style.display = 'block';
        
        // Scroll to form
        document.getElementById('webwidgetForm').scrollIntoView({ behavior: 'smooth' });
    }

    // Function to edit an existing widget
    function editWidget(widgetId) {
        const widget = widgets.find(w => w.widgetId === widgetId);
        if (!widget) {
            showAlert('Widget not found', 'danger');
            return;
        }

        currentWidgetId = widgetId;
        document.getElementById('widgetId').value = widgetId;
        document.getElementById('widgetColor').value = widget.color;
        document.getElementById('widgetPosition').value = widget.position;
        document.getElementById('autoMessage').value = widget.autoMessage;
        document.getElementById('widgetPrompt').value = widget.prompt;
        const autoOpenEditEl = document.getElementById('autoOpen');
        if (autoOpenEditEl) autoOpenEditEl.checked = (widget.autoOpen == '1');
        
        updateIntegrationCode();
        document.getElementById('webwidgetForm').style.display = 'block';
        
        // Scroll to form
        document.getElementById('webwidgetForm').scrollIntoView({ behavior: 'smooth' });
    }

    // Function to delete a widget
    function deleteWidget(widgetId) {
        if (!confirm(`Are you sure you want to delete Widget ${widgetId}?`)) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'deleteWidget');
        formData.append('widgetId', widgetId);

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                showAlert('Error deleting widget: ' + data.error, 'danger');
            } else {
                showAlert('Widget deleted successfully', 'success');
                loadWidgets();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('An error occurred while deleting the widget', 'danger');
        });
    }

    // Function to cancel editing
    function cancelEdit() {
        document.getElementById('webwidgetForm').style.display = 'none';
        currentWidgetId = null;
    }

    // Function to update integration code
    function updateIntegrationCode() {
        const widgetId = document.getElementById('widgetId').value;
        let userId = <?php echo $_SESSION["USERPROFILE"]["BID"]; ?>; // Default to current user ID
        
        // If editing an existing widget, use the widget's user ID
        if (currentWidgetId) {
            const widget = widgets.find(w => w.widgetId === parseInt(widgetId));
            if (widget && widget.userId) {
                userId = widget.userId;
            }
        }
        
        const code = '<!-- Synaplan Chat Widget -->\n' +
            '<script>\n' +
            '(function() {\n' +
            '    var script = document.createElement(\'script\');\n' +
            '    script.src = \'<?php echo $GLOBALS["baseUrl"]; ?>widget.php?uid=' + userId + '&widgetid=' + widgetId + '\';\n' +
            '    script.async = true;\n' +
            '    document.head.appendChild(script);\n' +
            '})();\n' +
            '\</script\>';
        document.getElementById('integrationCode').value = code;
    }

    // Function to copy integration code to clipboard
    function copyToClipboard(event) {
        const textArea = document.getElementById('integrationCode');
        textArea.select();
        document.execCommand('copy');
        
        // Show feedback
        const button = event.target.closest('button');
        if (button) {
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-check"></i> Copied!';
            setTimeout(() => {
                button.innerHTML = originalText;
            }, 2000);
        }
    }

    // Function to generate new integration code
    function generateNewCode() {
        updateIntegrationCode();
        showAlert('Integration code updated', 'info');
    }

    // Function to preview widget
    function previewWidget() {
        const widgetId = document.getElementById('widgetId').value;
        if (!widgetId) {
            showAlert('Please save the widget first', 'warning');
            return;
        }
        // Resolve userId like in integration code
        let userId = <?php echo $_SESSION["USERPROFILE"]["BID"]; ?>;
        if (currentWidgetId) {
            const widget = widgets.find(w => w.widgetId === parseInt(widgetId));
            if (widget && widget.userId) {
                userId = widget.userId;
            }
        }
        const previewUrl = `widgettest.php?uid=${userId}&widgetid=${widgetId}`;
        window.open(previewUrl, '_blank');
    }

    // Function to show alerts
    function showAlert(message, type) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        const container = document.getElementById('contentMain');
        container.insertBefore(alertDiv, container.firstChild);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }

    console.log('WebWidget script loaded successfully');
    console.log('createNewWidget function available:', typeof createNewWidget);
    } catch (error) {
        console.error('Error loading WebWidget script:', error);
        // Fallback or error handling if script fails to load
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show';
        alertDiv.innerHTML = `
            <strong>Error:</strong> Failed to load WebWidget script. Please ensure all dependencies are correct.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.getElementById('contentMain').insertBefore(alertDiv, document.getElementById('contentMain').firstChild);
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 10000); // Show for 10 seconds
    }
</script>

<style>
    .card-header h5 {
        color: #495057;
    }
    .btn-group .btn {
        min-width: 120px;
    }
    .text-muted {
        font-size: 0.875rem;
    }
    .form-control-color {
        width: 100%;
        height: 38px;
    }
    .card-footer {
        background-color: #f8f9fa;
        border-top: 1px solid #dee2e6;
    }
</style> 