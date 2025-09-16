<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <?php
        $isAdmin = (isset($_SESSION['USERPROFILE']['BINTYPE']) && $_SESSION['USERPROFILE']['BINTYPE'] === 'ADM');
        // Handle form submission for per-user config updates (user is always logged in)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_config'])) {
            $ownerId = intval($_SESSION['USERPROFILE']['BID']);

            foreach ($_POST as $key => $value) {
                if (strpos($key, 'config_') === 0) {
                    $setting = str_replace('config_', '', $key);
                    $modelId = intval($value);

                    // Remove existing override for this user+setting
                    $deleteSQL = "DELETE FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BSETTING = '" . DB::EscString($setting) . "' AND BOWNERID = " . $ownerId;
                    DB::query($deleteSQL);

                    // Insert new override when a model is selected; empty resets to global default
                    if ($modelId > 0) {
                        $insertSQL = "INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (" . $ownerId . ", 'DEFAULTMODEL', '" . DB::EscString($setting) . "', '" . DB::EscString($modelId) . "')";
                        DB::query($insertSQL);
                    }
                }
            }
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                    <strong><i class="fas fa-check-circle me-2"></i>Success!</strong> Your model preferences have been updated.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                  </div>';
        }

        // Admin-only: Handle updates to BMODELS pricing/units/quality
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_models_pricing']) && $isAdmin) {
            $updated = 0;
            $errors = 0;
            // Allowed unit options
            $allowedUnits = ['per1M','perpic','perhour','per1000chars','persec','-'];

            if (isset($_POST['model']) && is_array($_POST['model'])) {
                foreach ($_POST['model'] as $modelId => $data) {
                    $id = intval($modelId);
                    if ($id <= 0) { $errors++; continue; }

                    $priceIn = isset($data['price_in']) ? floatval($data['price_in']) : 0.0;
                    $inUnit = isset($data['in_unit']) && in_array($data['in_unit'], $allowedUnits, true) ? $data['in_unit'] : 'per1M';
                    $priceOut = isset($data['price_out']) ? floatval($data['price_out']) : 0.0;
                    $outUnit = isset($data['out_unit']) && in_array($data['out_unit'], $allowedUnits, true) ? $data['out_unit'] : 'per1M';
                    $quality = isset($data['quality']) ? floatval($data['quality']) : 0.0;

                    $sql = "UPDATE BMODELS SET "
                         . "BPRICEIN = " . ($priceIn) . ", "
                         . "BINUNIT = '" . DB::EscString($inUnit) . "', "
                         . "BPRICEOUT = " . ($priceOut) . ", "
                         . "BOUTUNIT = '" . DB::EscString($outUnit) . "', "
                         . "BQUALITY = " . ($quality) . " "
                         . "WHERE BID = " . $id;
                    try {
                        DB::query($sql);
                        $updated++;
                    } catch (\Throwable $e) {
                        $errors++;
                    }
                }
            }

            if ($updated > 0) {
                echo '<div class="alert alert-success alert-dismissible fade show" role="alert">'
                   . '<strong><i class="fas fa-check-circle me-2"></i>Saved!</strong> Updated ' . intval($updated) . ' model(s).'
                   . ($errors > 0 ? ' <small class="text-muted">(' . intval($errors) . ' failed)</small>' : '')
                   . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
                   . '</div>';
            } else {
                echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">'
                   . '<strong><i class="fas fa-exclamation-triangle me-2"></i>No changes saved.</strong>'
                   . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
                   . '</div>';
            }
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
                        // Get tasks as union of global defaults and current user's overrides
                        $currentUserId = intval($_SESSION['USERPROFILE']['BID']);
                        $taskSQL = "SELECT DISTINCT BSETTING FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND (BOWNERID = 0 OR BOWNERID = " . $currentUserId . ") ORDER BY BSETTING";
                        $taskRES = DB::query($taskSQL);
                        
                        // Get all available models for dropdowns
                        $allModelsSQL = "SELECT BID, BNAME, BTAG, BSERVICE, BSELECTABLE FROM BMODELS ORDER BY BTAG, BNAME";
                        $allModelsRES = DB::query($allModelsSQL);
                        $allModels = [];
                        while ($modelROW = DB::FetchArr($allModelsRES)) {
                            $allModels[] = $modelROW;
                        }
                        
                        // Get current config values with fallback: global baseline + user overrides
                        $currentConfig = [];
                        // Global defaults first
                        $globalConfigSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BOWNERID = 0";
                        $globalConfigRES = DB::query($globalConfigSQL);
                        while ($configROW = DB::FetchArr($globalConfigRES)) {
                            $currentConfig[$configROW['BSETTING']] = $configROW['BVALUE'];
                        }
                        // Overlay user-specific overrides
                        $userConfigSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BOWNERID = " . $currentUserId;
                        $userConfigRES = DB::query($userConfigSQL);
                        while ($configROW = DB::FetchArr($userConfigRES)) {
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
                <?php if ($isAdmin) { echo '<form method="POST">'; } ?>
                <table class="table table-striped table-hover table-sm">
                    <thead class="table-light">
                        <tr style="font-size: 0.85rem;">
                            <th style="width: 80px;">ID</th>
                            <th style="width: 120px;">PURPOSE</th>
                            <th style="width: 100px;">SERVICE</th>
                            <th style="width: 200px;">NAME</th>
                            <?php if ($isAdmin) { ?>
                                <th style="width: 140px;">IN PRICE</th>
                                <th style="width: 120px;">IN UNIT</th>
                                <th style="width: 140px;">OUT PRICE</th>
                                <th style="width: 120px;">OUT UNIT</th>
                                <th style="width: 120px;">QUALITY</th>
                            <?php } ?>
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
                            // Admin: unit options
                            $unitOptions = ['per1M','perpic','perhour','per1000chars','persec','-'];
                            
                            if (db::CountRows($modelsRES) > 0) {
                                while($modelROW = db::FetchArr($modelsRES)) {
                                    $detailArr = json_decode($modelROW["BJSON"], true);
                                    echo "<tr>";
                                    echo "<td><span class='badge bg-secondary'>" . htmlspecialchars($modelROW["BID"]) . "</span></td>";
                                    echo "<td><span class='badge bg-primary'>" . htmlspecialchars($modelROW["BTAG"]) . "</span></td>";
                                    echo "<td><span class='badge bg-info'>" . htmlspecialchars($modelROW["BSERVICE"]) . "</span></td>";
                                    echo "<td><strong>" . htmlspecialchars($modelROW["BNAME"]) . "</strong></td>";
                                    if ($isAdmin) {
                                        $mid = intval($modelROW['BID']);
                                        // IN price
                                        echo '<td>'
                                            . '<input type="number" step="0.001" min="0" class="form-control form-control-sm" name="model['.$mid.'][price_in]" value="'.htmlspecialchars((string)$modelROW['BPRICEIN']).'" />'
                                            . '</td>';
                                        // IN unit
                                        echo '<td>'
                                            . '<select class="form-select form-select-sm" name="model['.$mid.'][in_unit]">';
                                        foreach ($unitOptions as $u) {
                                            $sel = ($modelROW['BINUNIT'] === $u) ? ' selected' : '';
                                            echo '<option value="'.htmlspecialchars($u).'"'.$sel.'>'.htmlspecialchars($u).'</option>';
                                        }
                                        echo '</select>'
                                            . '</td>';
                                        // OUT price
                                        echo '<td>'
                                            . '<input type="number" step="0.001" min="0" class="form-control form-control-sm" name="model['.$mid.'][price_out]" value="'.htmlspecialchars((string)$modelROW['BPRICEOUT']).'" />'
                                            . '</td>';
                                        // OUT unit
                                        echo '<td>'
                                            . '<select class="form-select form-select-sm" name="model['.$mid.'][out_unit]">';
                                        foreach ($unitOptions as $u) {
                                            $sel = ($modelROW['BOUTUNIT'] === $u) ? ' selected' : '';
                                            echo '<option value="'.htmlspecialchars($u).'"'.$sel.'>'.htmlspecialchars($u).'</option>';
                                        }
                                        echo '</select>'
                                            . '</td>';
                                        // QUALITY
                                        echo '<td>'
                                            . '<input type="number" step="0.1" min="0" max="10" class="form-control form-control-sm" name="model['.$mid.'][quality]" value="'.htmlspecialchars((string)$modelROW['BQUALITY']).'" />'
                                            . '</td>';
                                    }
                                    echo "<td><small>" . htmlspecialchars(isset($detailArr["description"]) ? $detailArr["description"] : '') . "</small></td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center text-muted'>No models found</td></tr>";
                            }
                        ?>
                    </tbody>
                </table>
                <?php if ($isAdmin) { ?>
                    <div class="mt-3">
                        <button type="submit" name="update_models_pricing" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </div>
                <?php echo '</form>'; } ?>
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