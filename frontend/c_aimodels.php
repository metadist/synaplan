<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <?php
        // Handle form submission for config updates
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_config'])) {
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'config_') === 0) {
                    $setting = str_replace('config_', '', $key);
                    $modelId = intval($value);
                    
                    // Update or insert config
                    $checkSQL = "SELECT BID FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BSETTING = '" . DB::EscString($setting) . "'";
                    $checkRES = DB::query($checkSQL);
                    
                    if (DB::CountRows($checkRES) > 0) {
                        $checkROW = DB::FetchArr($checkRES);
                        $updateSQL = "UPDATE BCONFIG SET BVALUE = '" . DB::EscString($modelId) . "' WHERE BID = " . intval($checkROW['BID']);
                        DB::query($updateSQL);
                    } else {
                        $insertSQL = "INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (0, 'DEFAULTMODEL', '" . DB::EscString($setting) . "', '" . DB::EscString($modelId) . "')";
                        DB::query($insertSQL);
                    }
                }
            }
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                    <strong><i class="fas fa-check-circle me-2"></i>Success!</strong> Default model configurations have been updated.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                  </div>';
        }
    ?>

    <!-- Config Section -->
    <div class="card mb-4 mt-3">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-cog"></i> Default Model Configuration
            </h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row">
                    <?php
                        // Get all available tasks from BCONFIG
                        $taskSQL = "SELECT DISTINCT BSETTING FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' ORDER BY BSETTING";
                        $taskRES = DB::query($taskSQL);
                        
                        // Get all available models for dropdowns
                        $allModelsSQL = "SELECT BID, BNAME, BTAG, BSERVICE, BSELECTABLE FROM BMODELS ORDER BY BTAG, BNAME";
                        $allModelsRES = DB::query($allModelsSQL);
                        $allModels = [];
                        while ($modelROW = DB::FetchArr($allModelsRES)) {
                            $allModels[] = $modelROW;
                        }
                        
                        // Get current config values
                        $configSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL'";
                        $configRES = DB::query($configSQL);
                        $currentConfig = [];
                        while ($configROW = DB::FetchArr($configRES)) {
                            $currentConfig[$configROW['BSETTING']] = $configROW['BVALUE'];
                        }
                        
                        while ($taskROW = DB::FetchArr($taskRES)) {
                            $task = $taskROW['BSETTING'];
                            $currentValue = isset($currentConfig[$task]) ? $currentConfig[$task] : '';
                            
                            // Check if the current model is non-selectable
                            $currentModelSelectable = true;
                            if (!empty($currentValue)) {
                                $currentModelSQL = "SELECT BSELECTABLE FROM BMODELS WHERE BID = " . intval($currentValue);
                                $currentModelRES = DB::query($currentModelSQL);
                                if ($currentModelROW = DB::FetchArr($currentModelRES)) {
                                    $currentModelSelectable = ($currentModelROW['BSELECTABLE'] == 1);
                                }
                            }
                            
                            echo '<div class="col-md-6 col-lg-4 mb-4">';
                            echo '<div class="card h-100 border-0 shadow-sm">';
                            echo '<div class="card-header bg-light border-bottom">';
                            echo '<h6 class="card-title mb-0 text-primary"><i class="fas fa-robot me-2"></i>' . htmlspecialchars($task) . '</h6>';
                            echo '</div>';
                            echo '<div class="card-body">';
                            echo '<label for="config_' . htmlspecialchars($task) . '" class="form-label visually-hidden">' . htmlspecialchars($task) . ' Model Selection</label>';
                            echo '<select class="form-select form-select-sm" id="config_' . htmlspecialchars($task) . '" name="config_' . htmlspecialchars($task) . '"' . ($currentModelSelectable ? '' : ' disabled') . '>';
                            echo '<option value="">-- Select Model --</option>';
                            
                            // Group models by tag for better organization
                            $modelsByTag = [];
                            foreach ($allModels as $model) {
                                $tag = $model['BTAG'];
                                if (!isset($modelsByTag[$tag])) {
                                    $modelsByTag[$tag] = [];
                                }
                                $modelsByTag[$tag][] = $model;
                            }
                            
                            foreach ($modelsByTag as $tag => $models) {
                                echo '<optgroup label="' . htmlspecialchars(strtoupper($tag)) . '">';
                                foreach ($models as $model) {
                                    $selected = ($currentValue == $model['BID']) ? 'selected' : '';
                                    // Only disable if it's a system model AND not the currently selected value
                                    $disabled = ($model['BSELECTABLE'] == 0 && $currentValue != $model['BID']) ? 'disabled' : '';
                                    $modelLabel = htmlspecialchars($model['BNAME']) . ' (' . htmlspecialchars($model['BSERVICE']) . ')';
                                    if ($model['BSELECTABLE'] == 0) {
                                        $modelLabel .= ' [System Model]';
                                    }
                                    echo '<option value="' . $model['BID'] . '" ' . $selected . ' ' . $disabled . '>';
                                    echo $modelLabel;
                                    echo '</option>';
                                }
                                echo '</optgroup>';
                            }
                            
                            echo '</select>';
                            if (!$currentModelSelectable) {
                                echo '<div class="alert alert-warning alert-sm mt-2 mb-0 py-2">';
                                echo '<i class="fas fa-lock me-1"></i><small>System model</small>';
                                echo '</div>';
                            }
                            echo '</div>';
                            echo '</div>';
                            echo '</div>';
                        }
                    ?>
                </div>
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <div class="btn-group" role="group" aria-label="Configuration actions">
                            <button type="submit" name="update_config" class="btn btn-success btn-lg">
                                <i class="fas fa-save me-2"></i>Save Configuration
                            </button>
                            <button type="button" class="btn btn-secondary btn-lg" onclick="resetForm()">
                                <i class="fas fa-refresh me-2"></i>Reset Form
                            </button>
                        </div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                System models are automatically locked and cannot be changed. These are core models required for specific functionality.
                            </small>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-filter"></i> Models &amp; Purposes
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-12">
                    <?php
                        $tagSQL = "SELECT DISTINCT BTAG FROM BMODELS ORDER BY BTAG";
                        $tagRES = DB::query($tagSQL);
                        while($tagROW = DB::FetchArr($tagRES)) {
                            echo '<a href="index.php/aimodels?tag=' . htmlspecialchars($tagROW["BTAG"]) . '" class="btn btn-outline-primary me-2 mb-2">' . 
                                 '<i class="fas fa-tag me-1"></i>' . htmlspecialchars($tagROW["BTAG"]) . '</a>';
                        }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Models Table Section -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list"></i> Available Models
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm">
                    <thead class="table-light">
                        <tr style="font-size: 0.85rem;">
                            <th style="width: 80px;">ID</th>
                            <th style="width: 120px;">PURPOSE</th>
                            <th style="width: 100px;">SERVICE</th>
                            <th style="width: 200px;">NAME</th>
                            <th>DESCRIPTION</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.9rem;">
                        <?php
                            $whereClause = "";
                            if (isset($_GET['tag']) && !empty($_GET['tag'])) {
                                $whereClause = "WHERE BTAG = '" . db::EscString($_GET['tag']) . "'";
                            }
                            if(isset($_REQUEST["tag"])) {
                                $whereClause = "WHERE BTAG like '".DB::EscString($_REQUEST["tag"])."'";
                            }
                            $modelsSQL = "SELECT * FROM BMODELS $whereClause ORDER BY BTAG,BSERVICE";
                            $modelsRES = db::Query($modelsSQL);
                            
                            if (db::CountRows($modelsRES) > 0) {
                                while($modelROW = db::FetchArr($modelsRES)) {
                                    $detailArr = json_decode($modelROW["BJSON"], true);
                                    echo "<tr>";
                                    echo "<td><span class='badge bg-secondary'>" . htmlspecialchars($modelROW["BID"]) . "</span></td>";
                                    echo "<td><span class='badge bg-primary'>" . htmlspecialchars($modelROW["BTAG"]) . "</span></td>";
                                    echo "<td><span class='badge bg-info'>" . htmlspecialchars($modelROW["BSERVICE"]) . "</span></td>";
                                    echo "<td><strong>" . htmlspecialchars($modelROW["BNAME"]) . "</strong></td>";
                                    echo "<td><small>" . htmlspecialchars($detailArr["description"]) . "</small></td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center text-muted'>No models found</td></tr>";
                            }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
function resetForm() {
    if (confirm('Are you sure you want to reset the form? All changes will be lost.')) {
        window.location.reload();
    }
}
</script>