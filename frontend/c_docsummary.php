<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Document Summary Tool</h1>
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

    <form id="docSummaryForm" method="POST" action="index.php/docsummary">
        <input type="hidden" name="action" id="action" value="docsum">
        
        <!-- Input Configuration Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-cog"></i> Summary Configuration</h5>
                <small class="text-muted">This builds a prompt for the configured AI model to summarize the submitted text.</small>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="summaryType" class="col-sm-2 col-form-label"><strong>Summary Type:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="summaryType" id="summaryType">
                            <option value="abstractive">Abstractive Summary</option>
                            <option value="bullet_points">Bullet Points</option>
                            <option value="key_points">Key Points</option>
                            <option value="executive">Executive Summary</option>
                        </select>
                        <div class="form-text">Type of summary to generate</div>
                    </div>
                    <label for="summaryLength" class="col-sm-2 col-form-label"><strong>Length:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="summaryLength" id="summaryLength">
                            <option value="short">Short (100-200 words)</option>
                            <option value="medium" selected>Medium (200-500 words)</option>
                            <option value="long">Long (500-1000 words)</option>
                            <option value="custom">Custom Length</option>
                        </select>
                        <div class="form-text">Desired summary length</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="customLength" class="col-sm-2 col-form-label"><strong>Custom Length:</strong></label>
                    <div class="col-sm-4">
                        <input type="number" class="form-control" name="customLength" id="customLength" placeholder="300" min="50" max="2000" disabled>
                        <div class="form-text">Number of words (50-2000)</div>
                    </div>
                    <label for="language" class="col-sm-2 col-form-label"><strong>Output Language:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="language" id="language">
                            <option value="en">English</option>
                            <option value="de">Deutsch</option>
                            <option value="fr">Français</option>
                            <option value="es">Español</option>
                            <option value="it">Italiano</option>
                        </select>
                        <div class="form-text">Language for the summary output</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-sm-2">
                        <label class="col-form-label"><strong>Focus Areas:</strong></label>
                    </div>
                    <div class="col-sm-10">
                        <fieldset>
                            <legend class="visually-hidden">Focus Areas</legend>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="focusAreas[]" id="focusMain" value="main_ideas" checked>
                                <label class="form-check-label" for="focusMain">Main Ideas</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="focusAreas[]" id="focusKey" value="key_facts" checked>
                                <label class="form-check-label" for="focusKey">Key Facts</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="focusAreas[]" id="focusConclusions" value="conclusions">
                                <label class="form-check-label" for="focusConclusions">Conclusions</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="focusAreas[]" id="focusActions" value="action_items">
                                <label class="form-check-label" for="focusActions">Action Items</label>
                            </div>
                        </fieldset>
                        <div class="form-text">Select what to focus on in the summary</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Input Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-file-alt"></i> Document Input</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-sm-2">
                        <label class="col-form-label"><strong>Input Method:</strong></label>
                    </div>
                    <div class="col-sm-10">
                        <fieldset>
                            <legend class="visually-hidden">Input Method</legend>
                            <div class="btn-group" role="group" aria-label="Input method">
                                <input type="radio" class="btn-check" name="inputMethod" id="textInput" value="text" checked>
                                <label class="btn btn-outline-primary" for="textInput">
                                    <i class="fas fa-keyboard"></i> Text Input
                                </label>
                                <!--
                                <input type="radio" class="btn-check" name="inputMethod" id="fileUpload" value="file">
                                <label class="btn btn-outline-primary" for="fileUpload">
                                    <i class="fas fa-upload"></i> File Upload
                                </label>
                                
                                <input type="radio" class="btn-check" name="inputMethod" id="urlInput" value="url">
                                <label class="btn btn-outline-primary" for="urlInput">
                                    <i class="fas fa-link"></i> URL
                                </label>
                                -->
                            </div>
                        </fieldset>
                    </div>
                </div>

                <!-- Text Input -->
                <div id="textInputSection" class="input-section">
                    <div class="row mb-3">
                        <label for="BFILETEXT" class="col-sm-2 col-form-label"><strong>Document Text:</strong></label>
                        <div class="col-sm-10">
                            <textarea class="form-control" name="BFILETEXT" id="BFILETEXT" rows="15" placeholder="Paste your document text here..." oninput="updateCounts()"></textarea>
                            <div class="form-text">
                                <span id="charCount">0</span> characters | 
                                <span id="wordCount">0</span> words | 
                                <span id="estimatedTokens">0</span> estimated tokens
                            </div>
                        </div>
                    </div>
                </div>

                <!-- File Upload -->
                <div id="fileUploadSection" class="input-section" style="display: none;">
                    <div class="row mb-3">
                        <label for="documentFile" class="col-sm-2 col-form-label"><strong>Upload File:</strong></label>
                        <div class="col-sm-10">
                            <input type="file" class="form-control" name="documentFile" id="documentFile" accept=".txt,.doc,.docx,.pdf,.rtf">
                            <div class="form-text">Supported formats: TXT, DOC, DOCX, PDF, RTF (max 10MB)</div>
                        </div>
                    </div>
                </div>

                <!-- URL Input -->
                <div id="urlInputSection" class="input-section" style="display: none;">
                    <div class="row mb-3">
                        <label for="documentUrl" class="col-sm-2 col-form-label"><strong>Document URL:</strong></label>
                        <div class="col-sm-10">
                            <input type="url" class="form-control" name="documentUrl" id="documentUrl" placeholder="https://example.com/document">
                            <div class="form-text">Enter the URL of the document to summarize</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="card">
            <div class="card-body text-center">
                <div class="btn-group" role="group" aria-label="Document summary actions">
                    <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                        <i class="fas fa-magic"></i> Generate Summary
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="clearForm()">
                        <i class="fas fa-eraser"></i> Clear Form
                    </button>
                </div>
                <div class="mt-3">
                    <small class="text-muted">Processing large documents may take several minutes. Please be patient.</small>
                </div>
            </div>
        </div>

    </form>

    <!-- Results Section (Hidden by default) -->
    <div id="resultsSection" class="card mt-4" style="display: none;">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-chart-line text-success"></i> Summary Results
            </h5>
        </div>
        <div class="card-body">
            <div id="summaryContent">
                <!-- Summary content will be inserted here -->
            </div>
            <div class="mt-3">
                <button type="button" class="btn btn-outline-primary" onclick="downloadSummary()">
                    <i class="fas fa-download"></i> Download Summary
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="copySummary()">
                    <i class="fas fa-copy"></i> Copy to Clipboard
                </button>
            </div>
        </div>
    </div>
</main>
<script src="assets/statics/js/docsum.js"></script>
<style>
    .card-header h5 {
        color: #495057;
    }
    .btn-group .btn {
        min-width: 150px;
    }
    .text-muted {
        font-size: 0.875rem;
    }
    .form-range {
        height: 6px;
    }
    .input-section {
        transition: all 0.3s ease;
    }
</style> 