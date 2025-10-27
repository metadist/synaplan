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
                <button type="button" class="btn btn-success ms-2" id="launchWizardBtn">
                    <i class="fas fa-magic"></i> Setup Wizard
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
                    <label for="integrationType" class="col-sm-2 col-form-label"><strong>Integration Type:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="integrationType" id="integrationType">
                            <option value="floating-button">Floating Button (current)</option>
                            <option value="inline-box">Inline Box (new)</option>
                        </select>
                        <div class="form-text">Choose how the widget is embedded on your site</div>
                    </div>
                </div>
                <div class="row mb-3">
                    <label for="widgetColor" class="col-sm-2 col-form-label"><strong>Primary Color:</strong></label>
                    <div class="col-sm-4">
                        <input type="color" class="form-control form-control-color" name="widgetColor" id="widgetColor" value="#007bff">
                        <div class="form-text">Color for the chat button</div>
                    </div>
                    <label for="widgetIconColor" class="col-sm-2 col-form-label"><strong>Icon Color:</strong></label>
                    <div class="col-sm-4">
                        <input type="color" class="form-control form-control-color" name="widgetIconColor" id="widgetIconColor" value="#ffffff">
                        <div class="form-text">Color for the icon inside the button</div>
                    </div>
                </div>
                <div class="row mb-3">
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
                            foreach ($prompts as $prompt) {
                                $ownerHint = $prompt['BOWNERID'] != 0 ? '(custom)' : '(default)';
                                echo "<option value='".$prompt['BTOPIC']."'>".$ownerHint.' '.$prompt['BTOPIC'].'</option>';
                            }
                            ?>
                        </select>
                        <div class="form-text">Select the AI prompt to use for this widget</div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <label for="widgetLogo" class="col-sm-2 col-form-label"><strong>Widget Logo:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="widgetLogo" id="widgetLogo">
                            <option value="">No Logo (Default Chat Icon)</option>
                            <?php
                                // Get all files from WIDGET_LOGO group for this user
                                $logoSQL = 'SELECT BMESSAGES.BID, BMESSAGES.BFILEPATH, BMESSAGES.BTEXT 
                                           FROM BMESSAGES 
                                           INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID 
                                           WHERE BMESSAGES.BUSERID = ' . $_SESSION['USERPROFILE']['BID'] . " 
                                           AND BRAG.BGROUPKEY = 'WIDGET_LOGO' 
                                           AND BMESSAGES.BFILE > 0 
                                           AND BMESSAGES.BFILEPATH != '' 
                                           AND (BMESSAGES.BFILETYPE IN ('svg', 'png', 'jpg', 'jpeg'))
                                           ORDER BY BMESSAGES.BID DESC";
                            $logoRes = db::Query($logoSQL);
                            while ($logoRow = db::FetchArr($logoRes)) {
                                $logoName = basename($logoRow['BFILEPATH']);
                                echo "<option value='" . htmlspecialchars($logoRow['BFILEPATH']) . "'>" . htmlspecialchars($logoName) . '</option>';
                            }
                            ?>
                        </select>
                        <div class="form-text">
                            Upload logo files (SVG, PNG, JPG) via <a href="index.php/filemanager" target="_blank">File Manager</a> with group "WIDGET_LOGO"
                        </div>
                        <div id="logoPreview" class="mt-2" style="display: none;">
                            <img id="logoPreviewImg" src="" alt="Logo Preview" style="max-width: 60px; max-height: 60px; border: 1px solid #dee2e6; border-radius: 8px; padding: 8px; background: #f8f9fa;">
                        </div>
                    </div>
                </div>

                <!-- Inline Box Styling (shown for Inline Box) -->
                <div id="inlineBoxConfig" style="display:none;">
                    <hr>
                    <div class="row mb-3">
                        <label for="inlinePlaceholder" class="col-sm-2 col-form-label"><strong>Inline Placeholder:</strong></label>
                        <div class="col-sm-4">
                            <input type="text" class="form-control" name="inlinePlaceholder" id="inlinePlaceholder" placeholder="Ask me anything..." value="Ask me anything...">
                            <div class="form-text">Placeholder text shown inside the input</div>
                        </div>
                        <label for="inlineButtonText" class="col-sm-2 col-form-label"><strong>Button Text:</strong></label>
                        <div class="col-sm-4">
                            <input type="text" class="form-control" name="inlineButtonText" id="inlineButtonText" placeholder="Ask" value="Ask">
                            <div class="form-text">Text for the small submit button</div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label for="inlineFontSize" class="col-sm-2 col-form-label"><strong>Font Size:</strong></label>
                        <div class="col-sm-4">
                            <input type="number" class="form-control" name="inlineFontSize" id="inlineFontSize" min="12" max="28" step="1" value="18">
                            <div class="form-text">Font size in px (12–28)</div>
                        </div>
                        <label for="inlineTextColor" class="col-sm-2 col-form-label"><strong>Text Color:</strong></label>
                        <div class="col-sm-4">
                            <input type="color" class="form-control form-control-color" name="inlineTextColor" id="inlineTextColor" value="#212529">
                            <div class="form-text">Text color of the inline input</div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label for="inlineBorderRadius" class="col-sm-2 col-form-label"><strong>Border Radius:</strong></label>
                        <div class="col-sm-4">
                            <input type="number" class="form-control" name="inlineBorderRadius" id="inlineBorderRadius" min="0" max="24" step="1" value="8">
                            <div class="form-text">Rounded corners in px (0–24)</div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        Inline Box is optimized for anonymous visitors and embeds inline on your page. Clicking it opens the full chat overlay.
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
    script.src = '<?php echo $GLOBALS['baseUrl']; ?>widget.php?uid=${userId}&widgetid=${widgetId}';
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

<!-- Widget Setup Wizard Modal -->
<div class="modal fade" id="widgetWizardModal" tabindex="-1" aria-labelledby="widgetWizardModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="widgetWizardModalLabel">
                    <i class="fas fa-magic"></i> Widget Setup Wizard
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Progress Bar -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="wizard-step-label" data-step="1"><i class="fas fa-cog"></i> Configure</span>
                        <span class="wizard-step-label" data-step="2"><i class="fas fa-upload"></i> Upload Files</span>
                        <span class="wizard-step-label" data-step="3"><i class="fas fa-code"></i> Integration</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" id="wizardProgressBar" role="progressbar" style="width: 33%;" aria-valuenow="33" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>

                <!-- Wizard Steps Container -->
                <div id="wizardStepsContainer">
                    <!-- Step 1: Widget Configuration -->
                    <div class="wizard-step" id="wizardStep1" style="display: block;">
                        <h4 class="mb-3"><i class="fas fa-palette"></i> Step 1: Configure Your Widget</h4>
                        <p class="text-muted mb-4">Customize the appearance and behavior of your chat widget</p>
                        
                        <div class="row mb-3">
                            <label class="col-sm-3 col-form-label"><strong>Integration Type:</strong></label>
                            <div class="col-sm-9">
                                <select class="form-select" id="wizard_integrationType">
                                    <option value="floating-button">Floating Button</option>
                                    <option value="inline-box">Inline Box</option>
                                </select>
                                <div class="form-text">Choose how the widget appears on your website</div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <label class="col-sm-3 col-form-label"><strong>Primary Color:</strong></label>
                            <div class="col-sm-4">
                                <input type="color" class="form-control form-control-color" id="wizard_widgetColor" value="#007bff">
                            </div>
                            <label class="col-sm-2 col-form-label"><strong>Icon Color:</strong></label>
                            <div class="col-sm-3">
                                <input type="color" class="form-control form-control-color" id="wizard_widgetIconColor" value="#ffffff">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <label class="col-sm-3 col-form-label"><strong>Position:</strong></label>
                            <div class="col-sm-9">
                                <select class="form-select" id="wizard_widgetPosition">
                                    <option value="bottom-right">Bottom Right</option>
                                    <option value="bottom-left">Bottom Left</option>
                                    <option value="bottom-center">Bottom Center</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <label class="col-sm-3 col-form-label"><strong>Welcome Message:</strong></label>
                            <div class="col-sm-9">
                                <input type="text" class="form-control" id="wizard_autoMessage" placeholder="Hello! How can I help you today?" value="Hello! How can I help you today?">
                                <div class="form-text">First message visitors will see</div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <label class="col-sm-3 col-form-label"><strong>AI Prompt:</strong></label>
                            <div class="col-sm-9">
                                <select class="form-select" id="wizard_widgetPrompt">
                                    <?php
                                        $prompts = BasicAI::getAllPrompts();
                            foreach ($prompts as $prompt) {
                                $ownerHint = $prompt['BOWNERID'] != 0 ? '(custom)' : '(default)';
                                echo "<option value='".$prompt['BTOPIC']."'>".$ownerHint.' '.$prompt['BTOPIC'].'</option>';
                            }
                            ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-sm-9 offset-sm-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="wizard_autoOpen">
                                    <label class="form-check-label" for="wizard_autoOpen">
                                        Auto-open widget after a few seconds
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Inline Box Options (conditional) -->
                        <div id="wizard_inlineBoxConfig" style="display:none;">
                            <hr class="my-4">
                            <h5 class="mb-3">Inline Box Settings</h5>
                            <div class="row mb-3">
                                <label class="col-sm-3 col-form-label"><strong>Placeholder Text:</strong></label>
                                <div class="col-sm-9">
                                    <input type="text" class="form-control" id="wizard_inlinePlaceholder" value="Ask me anything...">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-3 col-form-label"><strong>Button Text:</strong></label>
                                <div class="col-sm-9">
                                    <input type="text" class="form-control" id="wizard_inlineButtonText" value="Ask">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: RAG File Upload -->
                    <div class="wizard-step" id="wizardStep2" style="display: none;">
                        <h4 class="mb-3"><i class="fas fa-upload"></i> Step 2: Upload Knowledge Base Files (Optional)</h4>
                        <p class="text-muted mb-4">Upload documents to enhance your AI assistant's knowledge</p>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Upload PDF, DOCX, TXT, or image files that contain information your chatbot should know about. 
                            These files will be processed and made available to your AI assistant.
                        </div>

                        <div class="mb-3">
                            <label for="wizard_groupKey" class="form-label"><strong>Group Name:</strong></label>
                            <input type="text" class="form-control" id="wizard_groupKey" placeholder="e.g., PRODUCT_DOCS, FAQ, SUPPORT" value="WIDGET_KB">
                            <div class="form-text">A keyword to organize your files (min 3 characters)</div>
                        </div>

                        <div class="mb-3">
                            <label for="wizard_ragFiles" class="form-label"><strong>Select Files:</strong></label>
                            <input type="file" class="form-control" id="wizard_ragFiles" multiple accept=".pdf,.docx,.txt,.jpg,.jpeg,.png">
                            <div class="form-text">Maximum 5 files, 10MB each</div>
                        </div>

                        <!-- File Preview -->
                        <div id="wizard_filePreview" style="display: none;">
                            <h6 class="mt-3">Selected Files:</h6>
                            <div id="wizard_fileList" class="border rounded p-2" style="background-color: #f8f9fa; max-height: 200px; overflow-y: auto;"></div>
                        </div>

                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> You can skip this step and upload files later via the File Manager.
                        </div>
                    </div>

                    <!-- Step 3: Integration Instructions -->
                    <div class="wizard-step" id="wizardStep3" style="display: none;">
                        <h4 class="mb-3"><i class="fas fa-check-circle text-success"></i> Step 3: Integration Complete!</h4>
                        <p class="text-muted mb-4">Your widget has been created. Follow these steps to add it to your website:</p>

                        <div class="alert alert-success">
                            <h5><i class="fas fa-clipboard-check"></i> Widget Created Successfully!</h5>
                            <p class="mb-0">Widget ID: <strong><span id="wizard_createdWidgetId"></span></strong></p>
                            <p class="mb-0" id="wizard_filesProcessedInfo" style="display: none;">Files Processed: <strong><span id="wizard_filesProcessedCount"></span></strong></p>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-code"></i> Integration Code</h5>
                            </div>
                            <div class="card-body">
                                <p><strong>Copy this code and paste it into your website's HTML, preferably before the closing &lt;/body&gt; tag:</strong></p>
                                <div class="position-relative">
                                    <textarea class="form-control font-monospace" id="wizard_integrationCode" rows="8" readonly></textarea>
                                    <button type="button" class="btn btn-sm btn-primary position-absolute top-0 end-0 m-2" id="wizard_copyCodeBtn">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-list-ol"></i> Installation Steps</h5>
                            </div>
                            <div class="card-body">
                                <ol class="mb-0">
                                    <li class="mb-2"><strong>Copy the integration code</strong> above using the copy button</li>
                                    <li class="mb-2"><strong>Access your website's HTML</strong> (via your CMS, theme editor, or HTML files)</li>
                                    <li class="mb-2"><strong>Paste the code before the closing &lt;/body&gt; tag</strong> or in your footer section</li>
                                    <li class="mb-2"><strong>Save and publish</strong> your changes</li>
                                    <li class="mb-2"><strong>Visit your website</strong> to see the chat widget in action!</li>
                                </ol>
                            </div>
                        </div>

                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i> <strong>Need help?</strong> You can always access this code and modify your widget settings from the main Widget Configuration page.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="wizardPrevBtn" style="display: none;">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <button type="button" class="btn btn-success" id="wizardNextBtn">
                    Next <i class="fas fa-arrow-right"></i>
                </button>
                <button type="button" class="btn btn-primary" id="wizardFinishBtn" style="display: none;">
                    <i class="fas fa-check"></i> Finish
                </button>
                <div id="wizardSavingIndicator" style="display: none;">
                    <span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Creating widget...</span>
                </div>
            </div>
        </div>
    </div>
</div>

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

            // Integration type toggle
            const integrationTypeEl = document.getElementById('integrationType');
            if (integrationTypeEl) {
                integrationTypeEl.addEventListener('change', function() {
                    toggleIntegrationFields();
                    updateIntegrationCode();
                });
                // Initialize on load
                toggleIntegrationFields();
            }

            // Widget logo preview
            const widgetLogoSelect = document.getElementById('widgetLogo');
            const logoPreview = document.getElementById('logoPreview');
            const logoPreviewImg = document.getElementById('logoPreviewImg');
            
            if (widgetLogoSelect) {
                widgetLogoSelect.addEventListener('change', function() {
                    const logoPath = this.value;
                    if (logoPath) {
                        logoPreviewImg.src = '<?php echo $GLOBALS['baseUrl']; ?>up/' + logoPath;
                        logoPreview.style.display = 'block';
                    } else {
                        logoPreview.style.display = 'none';
                    }
                });
            }

            // Setup Wizard functionality
            initializeWizard();
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

    // Show/hide fields based on integration type
    function toggleIntegrationFields() {
        const type = document.getElementById('integrationType') ? document.getElementById('integrationType').value : 'floating-button';
        const inlineCfg = document.getElementById('inlineBoxConfig');
        if (inlineCfg) {
            inlineCfg.style.display = (type === 'inline-box') ? 'block' : 'none';
        }
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
                                    <strong>Integration:</strong> ${widget.integrationType === 'inline-box' ? 'Inline Box' : 'Floating Button'}<br>
                                    <strong>Color:</strong> <span style="color: ${widget.color};">${widget.color}</span><br>
                                    <strong>Icon Color:</strong> <span style="color: ${widget.iconColor || '#ffffff'};">${widget.iconColor || '#ffffff'}</span><br>
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
        document.getElementById('integrationType').value = 'floating-button';
        document.getElementById('widgetColor').value = '#007bff';
        document.getElementById('widgetPosition').value = 'bottom-right';
        document.getElementById('autoMessage').value = 'Hello! How can I help you today?';
        document.getElementById('widgetPrompt').value = 'general';
        const autoOpenEl = document.getElementById('autoOpen');
        if (autoOpenEl) autoOpenEl.checked = false; // default off
        const iconColorEl = document.getElementById('widgetIconColor');
        if (iconColorEl) iconColorEl.value = '#ffffff';
        const logoEl = document.getElementById('widgetLogo');
        if (logoEl) logoEl.value = '';
        document.getElementById('logoPreview').style.display = 'none';
        // Defaults for inline box
        const inlinePh = document.getElementById('inlinePlaceholder');
        const inlineBtn = document.getElementById('inlineButtonText');
        const inlineFs = document.getElementById('inlineFontSize');
        const inlineTc = document.getElementById('inlineTextColor');
        const inlineBr = document.getElementById('inlineBorderRadius');
        if (inlinePh) inlinePh.value = 'Ask me anything...';
        if (inlineBtn) inlineBtn.value = 'Ask';
        if (inlineFs) inlineFs.value = 18;
        if (inlineTc) inlineTc.value = '#212529';
        if (inlineBr) inlineBr.value = 8;
        
        updateIntegrationCode();
        document.getElementById('webwidgetForm').style.display = 'block';
        
        // Scroll to form
        document.getElementById('webwidgetForm').scrollIntoView({ behavior: 'smooth' });
        toggleIntegrationFields();
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
        document.getElementById('integrationType').value = (widget.integrationType === 'inline-box') ? 'inline-box' : 'floating-button';
        document.getElementById('widgetColor').value = widget.color;
        document.getElementById('widgetPosition').value = widget.position;
        document.getElementById('autoMessage').value = widget.autoMessage;
        document.getElementById('widgetPrompt').value = widget.prompt;
        const autoOpenEditEl = document.getElementById('autoOpen');
        if (autoOpenEditEl) autoOpenEditEl.checked = (widget.autoOpen == '1');
        const iconColorEditEl = document.getElementById('widgetIconColor');
        if (iconColorEditEl) iconColorEditEl.value = widget.iconColor || '#ffffff';
        const logoEditEl = document.getElementById('widgetLogo');
        if (logoEditEl) {
            logoEditEl.value = widget.widgetLogo || '';
            if (widget.widgetLogo) {
                document.getElementById('logoPreviewImg').src = '<?php echo $GLOBALS['baseUrl']; ?>up/' + widget.widgetLogo;
                document.getElementById('logoPreview').style.display = 'block';
            } else {
                document.getElementById('logoPreview').style.display = 'none';
            }
        }
        // Inline settings
        const inlinePh = document.getElementById('inlinePlaceholder');
        const inlineBtn = document.getElementById('inlineButtonText');
        const inlineFs = document.getElementById('inlineFontSize');
        const inlineTc = document.getElementById('inlineTextColor');
        const inlineBr = document.getElementById('inlineBorderRadius');
        if (inlinePh) inlinePh.value = widget.inlinePlaceholder || 'Ask me anything...';
        if (inlineBtn) inlineBtn.value = widget.inlineButtonText || 'Ask';
        if (inlineFs) inlineFs.value = widget.inlineFontSize || 18;
        if (inlineTc) inlineTc.value = widget.inlineTextColor || '#212529';
        if (inlineBr) inlineBr.value = widget.inlineBorderRadius || 8;
        
        updateIntegrationCode();
        document.getElementById('webwidgetForm').style.display = 'block';
        
        // Scroll to form
        document.getElementById('webwidgetForm').scrollIntoView({ behavior: 'smooth' });
        toggleIntegrationFields();
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
        let userId = <?php echo $_SESSION['USERPROFILE']['BID']; ?>; // Default to current user ID
        
        // If editing an existing widget, use the widget's user ID
        if (currentWidgetId) {
            const widget = widgets.find(w => w.widgetId === parseInt(widgetId));
            if (widget && widget.userId) {
                userId = widget.userId;
            }
        }
        const integrationType = document.getElementById('integrationType').value;
        let code;
        if (integrationType === 'inline-box') {
            code = '<!-- Synaplan Chat Inline Box -->\n' +
                   '<script src="<?php echo $GLOBALS['baseUrl']; ?>widget.php?uid=' + userId + '&widgetid=' + widgetId + '&mode=inline-box"><\/script>';
        } else {
            code = '<!-- Synaplan Chat Widget -->\n' +
                   '<script>\n' +
                   '(function() {\n' +
                   '    var script = document.createElement(\'script\');\n' +
                   '    script.src = \'<?php echo $GLOBALS['baseUrl']; ?>widget.php?uid=' + userId + '&widgetid=' + widgetId + '\';\n' +
                   '    script.async = true;\n' +
                   '    document.head.appendChild(script);\n' +
                   '})();\n' +
                   '<\/script>';
        }
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
        let userId = <?php echo $_SESSION['USERPROFILE']['BID']; ?>;
        if (currentWidgetId) {
            const widget = widgets.find(w => w.widgetId === parseInt(widgetId));
            if (widget && widget.userId) {
                userId = widget.userId;
            }
        }
        const integrationType = document.getElementById('integrationType').value;
        const previewUrl = `widgettest.php?uid=${userId}&widgetid=${widgetId}${integrationType === 'inline-box' ? '&mode=inline-box' : ''}`;
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

    // ===========================================
    // WIZARD FUNCTIONALITY
    // ===========================================
    let wizardCurrentStep = 1;
    let wizardSelectedFiles = [];
    let wizardCreatedWidgetId = null;

    function initializeWizard() {
        // Launch wizard button
        const launchBtn = document.getElementById('launchWizardBtn');
        if (launchBtn) {
            launchBtn.addEventListener('click', launchWizard);
        }

        // Wizard navigation buttons
        const nextBtn = document.getElementById('wizardNextBtn');
        const prevBtn = document.getElementById('wizardPrevBtn');
        const finishBtn = document.getElementById('wizardFinishBtn');

        if (nextBtn) nextBtn.addEventListener('click', wizardNext);
        if (prevBtn) prevBtn.addEventListener('click', wizardPrev);
        if (finishBtn) finishBtn.addEventListener('click', wizardFinish);

        // Integration type toggle in wizard
        const wizardIntegrationType = document.getElementById('wizard_integrationType');
        if (wizardIntegrationType) {
            wizardIntegrationType.addEventListener('change', function() {
                const inlineConfig = document.getElementById('wizard_inlineBoxConfig');
                if (inlineConfig) {
                    inlineConfig.style.display = this.value === 'inline-box' ? 'block' : 'none';
                }
            });
        }

        // File selection in wizard
        const wizardFiles = document.getElementById('wizard_ragFiles');
        if (wizardFiles) {
            wizardFiles.addEventListener('change', function() {
                wizardSelectedFiles = Array.from(this.files);
                updateWizardFilePreview();
            });
        }

        // Copy code button in wizard
        const copyCodeBtn = document.getElementById('wizard_copyCodeBtn');
        if (copyCodeBtn) {
            copyCodeBtn.addEventListener('click', copyWizardIntegrationCode);
        }
    }

    function launchWizard() {
        // Reset wizard state
        wizardCurrentStep = 1;
        wizardSelectedFiles = [];
        wizardCreatedWidgetId = null;

        // Reset form values
        document.getElementById('wizard_integrationType').value = 'floating-button';
        document.getElementById('wizard_widgetColor').value = '#007bff';
        document.getElementById('wizard_widgetIconColor').value = '#ffffff';
        document.getElementById('wizard_widgetPosition').value = 'bottom-right';
        document.getElementById('wizard_autoMessage').value = 'Hello! How can I help you today?';
        document.getElementById('wizard_widgetPrompt').value = 'general';
        document.getElementById('wizard_autoOpen').checked = false;
        document.getElementById('wizard_inlinePlaceholder').value = 'Ask me anything...';
        document.getElementById('wizard_inlineButtonText').value = 'Ask';
        document.getElementById('wizard_groupKey').value = 'WIDGET_KB';
        document.getElementById('wizard_ragFiles').value = '';
        document.getElementById('wizard_filePreview').style.display = 'none';
        document.getElementById('wizard_inlineBoxConfig').style.display = 'none';

        // Show wizard modal
        const wizardModal = new bootstrap.Modal(document.getElementById('widgetWizardModal'));
        wizardModal.show();

        // Show first step
        showWizardStep(1);
    }

    function showWizardStep(step) {
        wizardCurrentStep = step;

        // Hide all steps
        document.querySelectorAll('.wizard-step').forEach(el => el.style.display = 'none');

        // Show current step
        const currentStepEl = document.getElementById('wizardStep' + step);
        if (currentStepEl) currentStepEl.style.display = 'block';

        // Update progress bar
        const progress = (step / 3) * 100;
        const progressBar = document.getElementById('wizardProgressBar');
        if (progressBar) {
            progressBar.style.width = progress + '%';
            progressBar.setAttribute('aria-valuenow', progress);
        }

        // Update step labels
        document.querySelectorAll('.wizard-step-label').forEach(label => {
            const labelStep = parseInt(label.getAttribute('data-step'));
            if (labelStep === step) {
                label.style.fontWeight = 'bold';
                label.style.color = '#198754';
            } else if (labelStep < step) {
                label.style.fontWeight = 'normal';
                label.style.color = '#6c757d';
            } else {
                label.style.fontWeight = 'normal';
                label.style.color = '#adb5bd';
            }
        });

        // Update navigation buttons
        const prevBtn = document.getElementById('wizardPrevBtn');
        const nextBtn = document.getElementById('wizardNextBtn');
        const finishBtn = document.getElementById('wizardFinishBtn');

        if (prevBtn) prevBtn.style.display = step === 1 ? 'none' : 'inline-block';
        if (nextBtn) nextBtn.style.display = step === 3 ? 'none' : 'inline-block';
        if (finishBtn) finishBtn.style.display = step === 3 ? 'inline-block' : 'none';
    }

    function wizardNext() {
        if (wizardCurrentStep === 1) {
            // Moving from step 1 to step 2
            showWizardStep(2);
        } else if (wizardCurrentStep === 2) {
            // Moving from step 2 to step 3 - need to save widget and upload files
            saveWizardWidget();
        }
    }

    function wizardPrev() {
        if (wizardCurrentStep > 1) {
            showWizardStep(wizardCurrentStep - 1);
        }
    }

    function saveWizardWidget() {
        // Show saving indicator
        document.getElementById('wizardSavingIndicator').style.display = 'block';
        document.getElementById('wizardNextBtn').disabled = true;

        // Find next available widget ID
        const usedIds = widgets.map(w => w.widgetId);
        let newId = 1;
        while (usedIds.includes(newId)) {
            newId++;
        }

        if (newId > 9) {
            showAlert('Maximum of 9 widgets allowed', 'warning');
            document.getElementById('wizardSavingIndicator').style.display = 'none';
            document.getElementById('wizardNextBtn').disabled = false;
            return;
        }

        wizardCreatedWidgetId = newId;

        // Prepare widget data
        const formData = new FormData();
        formData.append('action', 'saveWidget');
        formData.append('widgetId', newId);
        formData.append('integrationType', document.getElementById('wizard_integrationType').value);
        formData.append('widgetColor', document.getElementById('wizard_widgetColor').value);
        formData.append('widgetIconColor', document.getElementById('wizard_widgetIconColor').value);
        formData.append('widgetPosition', document.getElementById('wizard_widgetPosition').value);
        formData.append('autoMessage', document.getElementById('wizard_autoMessage').value);
        formData.append('widgetPrompt', document.getElementById('wizard_widgetPrompt').value);
        formData.append('autoOpen', document.getElementById('wizard_autoOpen').checked ? '1' : '0');
        formData.append('inlinePlaceholder', document.getElementById('wizard_inlinePlaceholder').value);
        formData.append('inlineButtonText', document.getElementById('wizard_inlineButtonText').value);
        formData.append('inlineFontSize', '18');
        formData.append('inlineTextColor', '#212529');
        formData.append('inlineBorderRadius', '8');
        formData.append('widgetLogo', '');

        // Save widget configuration first
        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                showAlert('Error saving widget: ' + data.error, 'danger');
                document.getElementById('wizardSavingIndicator').style.display = 'none';
                document.getElementById('wizardNextBtn').disabled = false;
            } else {
                // Widget saved, now check if we need to enable file search on prompt
                if (wizardSelectedFiles.length > 0) {
                    // Files are being uploaded, check if prompt has file search enabled
                    const selectedPrompt = document.getElementById('wizard_widgetPrompt').value;
                    checkAndEnableFileSearch(selectedPrompt, newId);
                } else {
                    // No files to upload, go to final step
                    completWizardSetup(newId, 0);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('An error occurred while saving the widget', 'danger');
            document.getElementById('wizardSavingIndicator').style.display = 'none';
            document.getElementById('wizardNextBtn').disabled = false;
        });
    }

    function checkAndEnableFileSearch(promptKey, widgetId) {
        console.log('[WIZARD] checkAndEnableFileSearch called: promptKey=' + promptKey + ', widgetId=' + widgetId);
        
        // Fetch prompt details to check if file search is enabled
        const formData = new FormData();
        formData.append('action', 'getPromptDetails');
        formData.append('promptKey', promptKey);

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('[WIZARD] getPromptDetails response:', data);
            
            if (data.error) {
                console.error('[WIZARD] Error fetching prompt details:', data.error);
                // Continue with file upload anyway
                uploadWizardFiles(widgetId);
                return;
            }

            // Check if tool_files is enabled
            const fileSearchSetting = data.SETTINGS.find(s => s.BTOKEN === 'tool_files');
            const fileSearchEnabled = fileSearchSetting && fileSearchSetting.BVALUE === '1';
            
            console.log('[WIZARD] File search enabled:', fileSearchEnabled, 'Settings:', data.SETTINGS);

            if (!fileSearchEnabled) {
                // File search is not enabled, ask user
                if (confirm('The selected AI prompt "' + promptKey + '" does not have File Search enabled.\n\n' +
                           'Would you like to enable File Search on this prompt so it can use the uploaded files?\n\n' +
                           'This will create a custom copy of the prompt for your account.')) {
                    // User wants to enable file search
                    enableFileSearchOnPrompt(promptKey, widgetId);
                } else {
                    // User declined, just upload files without modifying prompt
                    uploadWizardFiles(widgetId);
                }
            } else {
                // File search already enabled, check if we should update the group filter
                const groupKey = document.getElementById('wizard_groupKey').value.trim();
                const currentFilter = data.SETTINGS.find(s => s.BTOKEN === 'tool_files_keyword');
                
                if (currentFilter && currentFilter.BVALUE && currentFilter.BVALUE !== groupKey) {
                    // There's already a different filter set
                    if (confirm('The prompt "' + promptKey + '" is currently filtered to group "' + currentFilter.BVALUE + '".\n\n' +
                               'Would you like to change it to "' + groupKey + '" to use your newly uploaded files?')) {
                        // Update the filter
                        updatePromptFileSearchFilter(promptKey, groupKey, widgetId);
                    } else {
                        // Keep existing filter, just upload files
                        uploadWizardFiles(widgetId);
                    }
                } else {
                    // No filter or same filter, proceed normally
                    uploadWizardFiles(widgetId);
                }
            }
        })
        .catch(error => {
            console.error('Error checking prompt:', error);
            // Continue anyway
            uploadWizardFiles(widgetId);
        });
    }

    function enableFileSearchOnPrompt(promptKey, widgetId) {
        const groupKey = document.getElementById('wizard_groupKey').value.trim();
        
        console.log('[WIZARD] enableFileSearchOnPrompt called: promptKey=' + promptKey + ', groupKey=' + groupKey);
        
        const formData = new FormData();
        formData.append('action', 'enablePromptFileSearch');
        formData.append('promptKey', promptKey);
        formData.append('groupKey', groupKey);

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('[WIZARD] enablePromptFileSearch response:', data);
            
            if (data.success) {
                console.log('[WIZARD] SUCCESS: File search enabled on prompt:', promptKey);
                showAlert('File search has been enabled on prompt "' + promptKey + '"', 'success');
            } else {
                console.error('[WIZARD] FAILED to enable file search:', data.error);
                showAlert('Warning: Could not enable file search on prompt: ' + (data.error || 'Unknown error'), 'warning');
            }
            // Continue with file upload regardless
            uploadWizardFiles(widgetId);
        })
        .catch(error => {
            console.error('[WIZARD] ERROR enabling file search:', error);
            // Continue anyway
            uploadWizardFiles(widgetId);
        });
    }

    function updatePromptFileSearchFilter(promptKey, groupKey, widgetId) {
        const formData = new FormData();
        formData.append('action', 'updatePromptFileSearchFilter');
        formData.append('promptKey', promptKey);
        formData.append('groupKey', groupKey);

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('File search filter updated on prompt:', promptKey);
                showAlert('Prompt "' + promptKey + '" now uses file group "' + groupKey + '"', 'success');
            } else {
                console.error('Failed to update filter:', data.error);
            }
            // Continue with file upload
            uploadWizardFiles(widgetId);
        })
        .catch(error => {
            console.error('Error updating filter:', error);
            uploadWizardFiles(widgetId);
        });
    }

    function uploadWizardFiles(widgetId) {
        const groupKey = document.getElementById('wizard_groupKey').value.trim();

        console.log('[WIZARD] uploadWizardFiles called: groupKey="' + groupKey + '", files=' + wizardSelectedFiles.length);

        if (!groupKey || groupKey.length < 3) {
            console.warn('[WIZARD] Group key invalid or too short, skipping file upload');
            // Skip file upload if group key is invalid
            completWizardSetup(widgetId, 0);
            return;
        }

        const formData = new FormData();
        formData.append('action', 'ragUpload');
        formData.append('groupKey', groupKey);

        wizardSelectedFiles.forEach(file => {
            formData.append('files[]', file);
            console.log('[WIZARD] Adding file to upload:', file.name);
        });

        console.log('[WIZARD] Sending ragUpload request with groupKey:', groupKey);

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('[WIZARD] ragUpload response:', data);
            const filesProcessed = data.success ? (data.processedCount || 0) : 0;
            console.log('[WIZARD] Files processed:', filesProcessed);
            completWizardSetup(widgetId, filesProcessed);
        })
        .catch(error => {
            console.error('[WIZARD] File upload error:', error);
            // Continue anyway
            completWizardSetup(widgetId, 0);
        });
    }

    function completWizardSetup(widgetId, filesProcessed) {
        // Hide saving indicator
        document.getElementById('wizardSavingIndicator').style.display = 'none';
        document.getElementById('wizardNextBtn').disabled = false;

        // Update final step with widget details
        document.getElementById('wizard_createdWidgetId').textContent = widgetId;

        if (filesProcessed > 0) {
            document.getElementById('wizard_filesProcessedCount').textContent = filesProcessed;
            document.getElementById('wizard_filesProcessedInfo').style.display = 'block';
        } else {
            document.getElementById('wizard_filesProcessedInfo').style.display = 'none';
        }

        // Generate integration code
        const userId = <?php echo $_SESSION['USERPROFILE']['BID']; ?>;
        const integrationType = document.getElementById('wizard_integrationType').value;
        let code;

        if (integrationType === 'inline-box') {
            code = '<!-- Synaplan Chat Inline Box -->\n' +
                   '<script src="<?php echo $GLOBALS['baseUrl']; ?>widget.php?uid=' + userId + '&widgetid=' + widgetId + '&mode=inline-box"><\/script>';
        } else {
            code = '<!-- Synaplan Chat Widget -->\n' +
                   '<script>\n' +
                   '(function() {\n' +
                   '    var script = document.createElement(\'script\');\n' +
                   '    script.src = \'<?php echo $GLOBALS['baseUrl']; ?>widget.php?uid=' + userId + '&widgetid=' + widgetId + '\';\n' +
                   '    script.async = true;\n' +
                   '    document.head.appendChild(script);\n' +
                   '})();\n' +
                   '<\/script>';
        }

        document.getElementById('wizard_integrationCode').value = code;

        // Reload widgets list
        loadWidgets();

        // Show final step
        showWizardStep(3);
    }

    function updateWizardFilePreview() {
        const preview = document.getElementById('wizard_filePreview');
        const fileList = document.getElementById('wizard_fileList');

        if (wizardSelectedFiles.length === 0) {
            preview.style.display = 'none';
            return;
        }

        fileList.innerHTML = '';
        wizardSelectedFiles.forEach((file, index) => {
            const fileItem = document.createElement('div');
            fileItem.className = 'd-flex justify-content-between align-items-center mb-1 p-1';
            fileItem.innerHTML = `
                <span><i class="fas fa-file"></i> ${file.name} (${(file.size / 1024).toFixed(1)} KB)</span>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeWizardFile(${index})">
                    <i class="fas fa-times"></i>
                </button>
            `;
            fileList.appendChild(fileItem);
        });

        preview.style.display = 'block';
    }

    window.removeWizardFile = function(index) {
        wizardSelectedFiles.splice(index, 1);
        updateWizardFilePreview();

        // Update file input
        const wizardFilesInput = document.getElementById('wizard_ragFiles');
        const dt = new DataTransfer();
        wizardSelectedFiles.forEach(file => dt.items.add(file));
        wizardFilesInput.files = dt.files;
    };

    function copyWizardIntegrationCode() {
        const codeTextarea = document.getElementById('wizard_integrationCode');
        codeTextarea.select();
        document.execCommand('copy');

        const btn = document.getElementById('wizard_copyCodeBtn');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
        }, 2000);
    }

    function wizardFinish() {
        // Close modal
        const wizardModal = bootstrap.Modal.getInstance(document.getElementById('widgetWizardModal'));
        if (wizardModal) wizardModal.hide();

        // Show success message
        showAlert('Widget created successfully! You can now integrate it into your website.', 'success');
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
    
    /* Wizard Styles */
    .wizard-step-label {
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }
    
    .wizard-step {
        animation: fadeIn 0.3s ease-in;
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    #widgetWizardModal .modal-body {
        min-height: 400px;
    }
    
    #wizard_integrationCode {
        font-size: 0.85rem;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
    }
</style> 