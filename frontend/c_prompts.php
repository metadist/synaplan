<link rel="stylesheet" href="node_modules/easymde/dist/easymde.min.css">
<link rel="stylesheet" href="assets/statics/fa/css/all.min.css">
<style>
    #mdEditor {
        resize: vertical;
    }
</style>
<script src="node_modules/easymde/dist/easymde.min.js"></script>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <form id="promptForm" method="POST">
        <input type="hidden" name="action" id="action" value="promptUpdate">
        
        <!-- Prompt Management Section -->
        <div class="card mb-4 mt-2">
            <div class="card-header">
                <h5 class="card-title mb-0">Prompt Management</h5>
            </div>
            <div class="card-body">
                <!-- Prompt Selection -->
                <div class="row mb-3">
                    <label for="editKey" class="col-sm-2 col-form-label"><strong>Select Prompt:</strong></label>
                    <div class="col-sm-10">
                        <select class="form-select form-select-lg" aria-label="Select your prompt" name="editKey" id="editKey" onchange="onPromptChange()">
                        <?php
                            $prompts = BasicAI::getAllPrompts();
                            $selectedAIModel = -1;
                            $loopCount = 0;
                            foreach($prompts as $prompt) {
                                $ownerHint = "(default)";
                                if($prompt['BOWNERID'] != 0) {
                                    $ownerHint = "(custom)";
                                }
                                echo "<option value='".$prompt['BTOPIC']."'>".$ownerHint." ".$prompt['BTOPIC']. " - ".substr($prompt['BSHORTDESC'],0,32)."...</option>";
                                if($promptDesc == '') {
                                    $promptDesc = $prompt['BSHORTDESC'];
                                }
                                if($loopCount == 0) {
                                    foreach($prompt['SETTINGS'] as $setting) {
                                        if($setting['BTOKEN'] == 'aiModel') {
                                            $selectedAIModel = $setting['BVALUE'];
                                        }
                                    }
                                }
                                $loopCount++;
                            }
                        ?>
                        </select>
                        <div class="form-text">Choose an existing prompt to edit</div>
                    </div>
                </div>

                <!-- Description -->
                <div class="row mb-3">
                    <label for="promptDescription" class="col-sm-2 col-form-label"><strong>Description:</strong></label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="promptDescription" id="promptDescription" rows="2" placeholder="Enter a description for the preprocessor..."><?php echo $promptDesc; ?></textarea>
                        <div class="form-text">Brief description of what this prompt does</div>
                    </div>
                </div>

                <!-- Save As New -->
                <div class="row mb-4">
                    <label for="newName" class="col-sm-2 col-form-label"><strong>Save As New:</strong></label>
                    <div class="col-sm-10">
                        <div class="input-group">
                            <input type="text" class="form-control" name="newName" id="newName" placeholder="Enter new prompt name">
                            <button type="button" class="btn btn-outline-secondary" onclick="onSavePromptAs();">
                                <i class="fas fa-plus"></i> Save As New
                            </button>
                        </div>
                        <div class="form-text">Create a copy of this prompt with a new name</div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-sm-2"></div>
                    <div class="col-sm-10">
                        <div class="btn-group" role="group" aria-label="Prompt actions">
                            <button type="button" class="btn btn-success" onclick="onSavePrompt()">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="button" class="btn btn-danger" onclick="deletePrompt()">
                                <i class="fas fa-trash"></i> Delete Prompt
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Configuration Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Configuration</h5>
            </div>
            <div class="card-body">
                <!-- AI Model Selection -->
                <div class="row mb-3">
                    <label for="aiModelSelect" class="col-sm-2 col-form-label"><strong>AI Model:</strong></label>
                    <div class="col-sm-10">
                        <?php
                            $AImodels = BasicAI::getAllModels();
                            $modCount = 0;
                            array_unshift($AImodels, [
                                "BID" => "-1",
                                "BTAG" => "AUTOMATED",
                                "BNAME" => "Tries to define the best model for the task",
                                "BSHORTDESC" => "Tries to define the best model for the task",
                                "BSERVICE" => "SYNAPLAN"
                            ]);
                        ?>
                        <select class="form-select" name="aiModelSelect" id="aiModelSelect">
                            <?php 
                            foreach($AImodels as $model): 
                                // Only disable if it's a system model AND not the currently selected value
                                $disabled = ($model['BSELECTABLE'] == 0 && $selectedAIModel != $model['BID']) ? 'disabled' : '';
                                $modelLabel = htmlspecialchars($model["BTAG"]." - ".$model['BNAME']." on ".$model['BSERVICE']);
                                if ($model['BSELECTABLE'] == 0) {
                                    $modelLabel .= ' [System Model]';
                                }
                            ?>
                                <option value="<?= htmlspecialchars($model['BID']) ?>" <?= $disabled ?>><?= $modelLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Select the AI model to use for this prompt</div>
                    </div>
                </div>

                <!-- Tools Section -->
                <div class="row">
                    <label class="col-sm-2 col-form-label"><strong>Available Tools:</strong></label>
                    <div class="col-sm-10">
                        <div class="row">
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="toolInternet" title="Enable Internet Search">
                                    <label class="form-check-label" for="toolInternet">🌐 Internet Search</label>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="toolFiles" title="Enable Files Search (RAG)">
                                    <label class="form-check-label" for="toolFiles">📚 Files Search</label>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="toolScreenshot" title="Enable Screenshot Generator">
                                    <label class="form-check-label" for="toolScreenshot">📸 URL Screenshot</label>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="toolTransfer" title="Enable File Transfer">
                                    <label class="form-check-label" for="toolTransfer">📤 MCP calls...</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-text">Enable tools that will be available to the AI when using this prompt</div>
                    </div>
                </div>
                <div class="row">
                    <!-- Tool Settings Expansion Section -->
                    <div id="toolSettingsExpansion" class="mb-4">
                        <!-- Internet Search Info -->
                        <div id="toolInternetSettings" class="tool-settings-box alert alert-info" style="display:none;">
                            <strong>🌐 Internet Search:</strong> Result is enhanced with live search on Brave.
                        </div>
                        <!-- Files Search Filter -->
                        <div id="toolFilesSettings" class="tool-settings-box" style="display:none;">
                            <label for="toolFilesKeyword" class="form-label"><strong>Filter by File Group:</strong></label>
                            <select class="form-select" id="toolFilesKeyword" name="toolFilesKeyword">
                                <option value="">All file groups...</option>
                                <?php
                                    // Reuse the groupKeys logic from c_filemanager.php
                                    $groupKeys = [];
                                    $sql = "SELECT DISTINCT BRAG.BGROUPKEY
                                            FROM BMESSAGES
                                            INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                                            WHERE BMESSAGES.BUSERID = " . $_SESSION["USERPROFILE"]["BID"] . "
                                            AND BMESSAGES.BDIRECT = 'IN'
                                            AND BMESSAGES.BFILE > 0
                                            AND BMESSAGES.BFILEPATH != ''";
                                    $res = db::Query($sql);
                                    while ($row = db::FetchArr($res)) {
                                        if (!empty($row['BGROUPKEY'])) {
                                            $groupKeys[] = $row['BGROUPKEY'];
                                        }
                                    }
                                    foreach ($groupKeys as $groupKey) {
                                        echo "<option value='" . htmlspecialchars($groupKey) . "'>" . htmlspecialchars($groupKey) . "</option>";
                                    }
                                ?>
                            </select>
                            <div class="form-text">Choose a file group to restrict RAG search.</div>
                        </div>
                        <!-- Screenshot Settings -->
                        <div id="toolScreenshotSettings" class="tool-settings-box" style="display:none;">
                            <label class="form-label"><strong>Screenshot Dimensions (px):</strong></label>
                            <div class="row g-2">
                                <div class="col">
                                    <input type="number" class="form-control" id="toolScreenshotX" name="toolScreenshotX" min="100" max="5000" value="1200" placeholder="Width (X)">
                                </div>
                                <div class="col">
                                    <input type="number" class="form-control" id="toolScreenshotY" name="toolScreenshotY" min="100" max="5000" value="2000" placeholder="Height (Y)">
                                </div>
                            </div>
                            <div class="form-text">Set the screenshot size in pixels (default: 1200x2000).</div>
                        </div>
                        <!-- Transfer Info -->
                        <div id="toolTransferSettings" class="tool-settings-box alert alert-info" style="display:none;">
                            <strong>📤 MCP calls...</strong> Configure your MCP calls before and after prompt execution.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Editor Section -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Prompt Content</h5>
            </div>
            <div class="card-body p-0">
                <?php
                    if($selectedAIModel == -1) {
                        $promptText = BasicAI::getAprompt("general", $_SESSION["LANG"], [], false)["BPROMPT"];
                    } else {
                        $promptText = BasicAI::getApromptById($selectedAIModel)["BPROMPT"];
                    }
                ?>
                <textarea id="mdEditor" class="form-control border-0" name="promptContent"
                    style="min-height: 500px; font-family: 'Courier New', monospace; font-size: 14px; line-height: 1.5;"><?php 
                    echo $promptText; 
                ?></textarea>
            </div>
        </div>

    </form>
</main>

<script>
    const easyMDE = new EasyMDE(
        {element: document.getElementById('mdEditor'),
            spellChecker: false,
            autosave: {
                enabled: false,
                uniqueId: "mdEditor"
            }
        });

    // Called when AI model dropdown changes
    function onAIModelChange() {
        let selectedModel = document.getElementById('aiModelSelect').value;
        console.log("Selected AI Model:", selectedModel);
        // You can add more logic here if needed
    }

    // Called when dropdown changes
    function onPromptChange() {
        const selected = document.getElementById('editKey').value;
        console.log("Selected prompt:", selected);
        
        // Fetch prompt details including description
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
                alert('Error loading prompt details: ' + data.error);
            } else {
                // Update description field
                document.getElementById('promptDescription').value = data.BSHORTDESC || '';
                
                // Update AI model selection
                const aiModelSelect = document.getElementById('aiModelSelect');

                const modelSetting = data.SETTINGS.find(setting => setting.BTOKEN === 'aiModel');

                if (modelSetting) {
                    aiModelSelect.value = modelSetting.BVALUE;
                } else {
                    aiModelSelect.value = -1;
                }
                
                // Update tool settings
                document.getElementById('toolInternet').checked = false;
                document.getElementById('toolFiles').checked = false;
                document.getElementById('toolScreenshot').checked = false;
                document.getElementById('toolTransfer').checked = false;
                // Set the correct tool settings
                const toolSettings = {
                    'tool_internet': document.getElementById('toolInternet'),
                    'tool_files': document.getElementById('toolFiles'),
                    'tool_screenshot': document.getElementById('toolScreenshot'),
                    'tool_transfer': document.getElementById('toolTransfer')
                };
                
                data.SETTINGS.forEach(setting => {
                    if (toolSettings[setting.BTOKEN]) {
                        toolSettings[setting.BTOKEN].checked = setting.BVALUE === '1';
                    }
                });
                
                // Update prompt content in the editor
                if (data.BPROMPT) {
                    easyMDE.value(data.BPROMPT);
                }

                // Set expansion values if present
                if (data.SETTINGS) {
                    data.SETTINGS.forEach(setting => {
                        if (setting.BTOKEN === 'tool_files_keyword') {
                            document.getElementById('toolFilesKeyword').value = setting.BVALUE || '';
                        }
                        if (setting.BTOKEN === 'tool_screenshot_x') {
                            document.getElementById('toolScreenshotX').value = setting.BVALUE || '1200';
                        }
                        if (setting.BTOKEN === 'tool_screenshot_y') {
                            document.getElementById('toolScreenshotY').value = setting.BVALUE || '2000';
                        }
                    });
                }
                updateToolSettingsVisibility();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while loading the prompt details');
        });
    }
    // save the current prompt as a new one
    function onSavePromptAs() {
        let newName = document.getElementById('newName').value;
        if (!newName) {
            alert('Please enter a name for the new prompt');
            return;
        } else {
            newName = newName
                .toLowerCase()
                .replace(/[^a-z0-9\u00C0-\u00FF\u0100-\u017F\u0180-\u024F\u0400-\u04FF\u0590-\u05FF\u0600-\u06FF\u0700-\u074F\u0750-\u077F\u0780-\u07BF\u07C0-\u07FF\u0900-\u097F\u0980-\u09FF\u0A00-\u0A7F\u0A80-\u0AFF\u0B00-\u0B7F\u0B80-\u0BFF\u0C00-\u0C7F\u0C80-\u0CFF\u0D00-\u0D7F\u0D80-\u0DFF\u0E00-\u0E7F\u0E80-\u0EFF\u0F00-\u0FFF\u1000-\u109F\u10A0-\u10FF\u1100-\u11FF\u1200-\u137F\u1380-\u139F\u13A0-\u13FF\u1400-\u167F\u1680-\u169F\u16A0-\u16FF\u1700-\u171F\u1720-\u173F\u1740-\u175F\u1760-\u177F\u1780-\u17FF\u1800-\u18AF\u1900-\u194F\u1950-\u197F\u1980-\u19DF\u19E0-\u19FF\u1A00-\u1A1F\u1A20-\u1AAF\u1AB0-\u1AFF\u1B00-\u1B7F\u1B80-\u1BBF\u1BC0-\u1BFF\u1C00-\u1C4F\u1C50-\u1C7F\u1C80-\u1C8F\u1C90-\u1CBF\u1CC0-\u1CCF\u1CD0-\u1CFF\u1D00-\u1D7F\u1D80-\u1DBF\u1DC0-\u1DFF\u1E00-\u1EFF\u1F00-\u1FFF\u2000-\u206F\u2070-\u209F\u20A0-\u20CF\u20D0-\u20FF\u2100-\u214F\u2150-\u218F\u2190-\u21FF\u2200-\u22FF\u2300-\u23FF\u2400-\u243F\u2440-\u245F\u2460-\u24FF\u2500-\u257F\u2580-\u25FF\u2600-\u26FF\u2700-\u27BF\u27C0-\u27EF\u27F0-\u27FF\u2800-\u28FF\u2900-\u297F\u2980-\u29FF\u2A00-\u2AFF\u2B00-\u2BFF\u2C00-\u2C5F\u2C60-\u2C7F\u2C80-\u2CFF\u2D00-\u2D2F\u2D30-\u2D7F\u2D80-\u2DDF\u2DE0-\u2DFF\u2E00-\u2E7F\u2E80-\u2EFF\u2F00-\u2FDF\u2FF0-\u2FFF\u3000-\u303F\u3040-\u309F\u30A0-\u30FF\u3100-\u312F\u3130-\u318F\u3190-\u319F\u31A0-\u31BF\u31C0-\u31EF\u31F0-\u31FF\u3200-\u32FF\u3300-\u33FF\u3400-\u4DBF\u4DC0-\u4DFF\u4E00-\u9FFF\uA000-\uA48F\uA490-\uA4CF\uA4D0-\uA4FF\uA500-\uA63F\uA640-\uA69F\uA6A0-\uA6FF\uA700-\uA71F\uA720-\uA7FF\uA800-\uA82F\uA830-\uA83F\uA840-\uA87F\uA880-\uA8DF\uA8E0-\uA8FF\uA900-\uA92F\uA930-\uA95F\uA960-\uA97F\uA980-\uA9DF\uA9E0-\uA9FF\uAA00-\uAA5F\uAA60-\uAA7F\uAA80-\uAADF\uAAE0-\uAAFF\uAB00-\uAB2F\uAB30-\uAB6F\uAB70-\uABBF\uABC0-\uABFF\uAC00-\uD7AF\uD7B0-\uD7FF\uD800-\uDB7F\uDB80-\uDBFF\uDC00-\uDFFF\uE000-\uF8FF\uF900-\uFAFF\uFB00-\uFB4F\uFB50-\uFDFF\uFE00-\uFE0F\uFE10-\uFE1F\uFE20-\uFE2F\uFE30-\uFE4F\uFE50-\uFE6F\uFE70-\uFEFF\uFF00-\uFFEF\uFFF0-\uFFFF]/g, '') // Remove special characters but keep international letters and numbers
                .replace(/\s+/g, ''); // Remove all spaces completely
            document.getElementById('editKey').value = newName;
            submitPromptData('saveAs', newName);
            return false;
        }
    }
    // save the current prompt
    function onSavePrompt() {
        const selectedPrompt = document.getElementById('editKey').value;
        submitPromptData('save', selectedPrompt);
        return false;
    }

    // Function to submit prompt data via AJAX
    function submitPromptData(saveFlag, promptName) {
        let formData = new FormData;
        formData.append('action', 'promptUpdate');
        formData.append('saveFlag', saveFlag);
        formData.append('promptKey', promptName);
        formData.append('promptContent', easyMDE.value());
        formData.append('aiModel', document.getElementById('aiModelSelect').value);
        formData.append('promptDescription', document.getElementById('promptDescription').value);
        formData.append('tool_internet', document.getElementById('toolInternet').checked ? '1' : '0');
        formData.append('tool_files', document.getElementById('toolFiles').checked ? '1' : '0');
        formData.append('tool_screenshot', document.getElementById('toolScreenshot').checked ? '1' : '0');
        formData.append('tool_transfer', document.getElementById('toolTransfer').checked ? '1' : '0');

        // Add expansion fields if tools are enabled
        if (document.getElementById('toolFiles').checked) {
            formData.append('tool_files_keyword', document.getElementById('toolFilesKeyword').value);
        }
        if (document.getElementById('toolScreenshot').checked) {
            formData.append('tool_screenshot_x', document.getElementById('toolScreenshotX').value);
            formData.append('tool_screenshot_y', document.getElementById('toolScreenshotY').value);
        }

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
            } else {
                alert('Prompt saved successfully!');
                // Store the prompt name in sessionStorage before reloading
                sessionStorage.setItem('selectedPrompt', promptName);
                // Reload the page
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while saving the prompt');
        });
    }

    // Called when trash button is clicked
    function deletePrompt() {
        if (confirm("Are you sure you want to delete this prompt: " + document.getElementById('editKey').value + "?")) {
            const formData = new FormData();
            formData.append('action', 'deletePrompt');
            formData.append('promptKey', document.getElementById('editKey').value);

            fetch('api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Error: ' + data.error);
                } else {
                    alert('Prompt deleted successfully!');
                    // Reload the page to refresh the prompt list
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the prompt');
            });
        }
    }

    // Tool toggle event handlers
    function onToolInternetToggle(checked) {
        console.log("Internet Search toggled:", checked);
        // Add your logic here
    }
    function onToolFilesToggle(checked) {
        console.log("Files Search toggled:", checked);
        // Add your logic here
    }
    function onToolScreenshotToggle(checked) {
        console.log("Screenshot toggled:", checked);
        // Add your logic here
    }
    function onToolTransferToggle(checked) {
        console.log("Transfer toggled:", checked);
        // Add your logic here
    }

    // Show/hide tool settings expansion based on toggles
    function updateToolSettingsVisibility() {
        document.getElementById('toolInternetSettings').style.display = document.getElementById('toolInternet').checked ? '' : 'none';
        document.getElementById('toolFilesSettings').style.display = document.getElementById('toolFiles').checked ? '' : 'none';
        document.getElementById('toolScreenshotSettings').style.display = document.getElementById('toolScreenshot').checked ? '' : 'none';
        document.getElementById('toolTransferSettings').style.display = document.getElementById('toolTransfer').checked ? '' : 'none';
    }

    // Attach to tool toggles
    ['toolInternet', 'toolFiles', 'toolScreenshot', 'toolTransfer'].forEach(function(id) {
        document.getElementById(id).addEventListener('change', updateToolSettingsVisibility);
    });

    // Attach event listeners after DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('toolInternet').addEventListener('change', function(e) {
            onToolInternetToggle(e.target.checked);
        });
        document.getElementById('toolFiles').addEventListener('change', function(e) {
            onToolFilesToggle(e.target.checked);
        });
        document.getElementById('toolScreenshot').addEventListener('change', function(e) {
            onToolScreenshotToggle(e.target.checked);
        });
        document.getElementById('toolTransfer').addEventListener('change', function(e) {
            onToolTransferToggle(e.target.checked);
        });

        // Attach AI model dropdown change event
        document.getElementById('aiModelSelect').addEventListener('change', onAIModelChange);

        // Check if there's a stored prompt name and select it
        const storedPrompt = sessionStorage.getItem('selectedPrompt');
        if (storedPrompt) {
            const select = document.getElementById('editKey');
            const option = Array.from(select.options).find(opt => opt.value === storedPrompt);
            if (option) {
                select.value = storedPrompt;
                onPromptChange(); // Trigger the change event to load the prompt details
            }
            // Clear the stored prompt name
            sessionStorage.removeItem('selectedPrompt');
        }
        // set the selected AI model
        let aiModelSelect = document.getElementById('aiModelSelect');
        aiModelSelect.value = <?php echo $selectedAIModel; ?>;

        // update the page settings
        updateToolSettingsVisibility();
        onPromptChange();
    });
</script>