<link rel="stylesheet" href="assets/statics/fa/css/all.min.css">
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-3" id="contentMain">
    <?php
    // --- FILE DELETE HANDLER ---
    $deleteNotification = '';
    if (
        (isset($_REQUEST['action']) && $_REQUEST['action'] === 'delete' && isset($_REQUEST['id'])) ||
        (isset($_REQUEST['action']) && $_REQUEST['action'] === 'bulkdelete' && isset($_POST['selectedFiles']))
    ) {
        // Collect IDs to delete
        $idsToDelete = [];
        if ($_REQUEST['action'] === 'delete' && isset($_REQUEST['id'])) {
            $idsToDelete[] = intval($_REQUEST['id']);
        } elseif ($_REQUEST['action'] === 'bulkdelete' && isset($_POST['selectedFiles'])) {
            foreach ($_POST['selectedFiles'] as $fileId) {
                $idsToDelete[] = intval($fileId);
            }
        }

        $deletedCount = 0;
        $errors = [];
        foreach ($idsToDelete as $fileId) {
            // Get file path before deleting DB entry
            $sql = "SELECT BFILEPATH FROM BMESSAGES WHERE BID = $fileId AND BUSERID = " . intval($_SESSION["USERPROFILE"]["BID"]);
            $res = db::Query($sql);
            $row = db::FetchArr($res);
            if ($row && !empty($row['BFILEPATH'])) {
                $filePath = rtrim(UPLOAD_DIR, '/').'/' . $row['BFILEPATH'];
                if (file_exists($filePath) && is_file($filePath)) {
                    if (!unlink($filePath)) {
                        $errors[] = "Could not delete file: " . htmlspecialchars($row['BFILEPATH']);
                    }
                }
            }
            // Delete from BRAG
            $sqlBrag = "DELETE FROM BRAG WHERE BMID = $fileId";
            db::Query($sqlBrag);
            // Delete from BMESSAGES
            $sqlMsg = "DELETE FROM BMESSAGES WHERE BID = $fileId AND BUSERID = " . intval($_SESSION["USERPROFILE"]["BID"]);
            db::Query($sqlMsg);
            $deletedCount++;
        }
        if ($deletedCount > 0) {
            $deleteNotification = '<div class="alert alert-success my-2">Deleted ' . $deletedCount . ' file(s) successfully.</div>';
        }
        if (!empty($errors)) {
            $deleteNotification .= '<div class="alert alert-warning my-2">' . implode('<br>', $errors) . '</div>';
        }       
    }
    if (!empty($deleteNotification)) {
        echo $deleteNotification;
    }
    ?>
    
    <!-- RAG File Upload Section -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-upload"></i> Upload RAG Files
            </h5>
        </div>
        <div class="card-body">
            <form id="ragUploadForm" enctype="multipart/form-data">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="groupKey" class="form-label"><strong>Group Keyword:</strong></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="groupKey" name="groupKey" 
                                   placeholder="Enter keyword for file grouping" required maxlength="50">
                            <select class="form-select" id="existingGroupSelect" style="max-width: 200px;">
                                <option value="">Or select existing...</option>
                                <?php
                                    // Get existing group keys
                                    $sql = "SELECT DISTINCT BRAG.BGROUPKEY
                                            FROM BMESSAGES
                                            INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                                            WHERE BMESSAGES.BUSERID = " . $_SESSION["USERPROFILE"]["BID"] . "
                                              AND BMESSAGES.BDIRECT = 'IN'
                                              AND BMESSAGES.BFILE > 0
                                              AND BMESSAGES.BFILEPATH != ''
                                              AND BRAG.BGROUPKEY != ''
                                              ORDER BY BRAG.BGROUPKEY";
                                    $res = db::Query($sql);
                                    while ($row = db::FetchArr($res)) {
                                        echo "<option value='" . htmlspecialchars($row['BGROUPKEY']) . "'>" . 
                                             htmlspecialchars($row['BGROUPKEY']) . "</option>";
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="form-text">Enter a keyword to group related files together for RAG search</div>
                    </div>
                    <div class="col-md-6">
                        <label for="ragFiles" class="form-label"><strong>Select Files:</strong></label>
                        <input type="file" class="form-control" id="ragFiles" name="files[]" multiple 
                               accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.mp3,.mp4">
                        <div class="form-text">Select one or more files (PDF, DOCX, TXT, JPG, PNG, MP3, MP4)</div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-12 text-end">
                        <button type="submit" id="ragUploadBtn" class="btn btn-primary" disabled>
                            <i class="fas fa-upload"></i> Upload and Process Files
                        </button>
                        <div id="ragUploadStatus" class="text-muted small mt-2"></div>
                    </div>
                </div>
                
                <!-- File Preview Area -->
                <div id="ragFilePreview" class="mb-3" style="display: none;">
                    <h6>Selected Files:</h6>
                    <div id="ragFileList" class="border rounded p-2" style="background-color: #f8f9fa; max-height: 200px; overflow-y: auto;">
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Files Table Section -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Your Files</h5>
        </div>
        <div class="card-body">
            <!-- Filter Section -->
            <div class="border-bottom pb-3 mb-3">
                <form method="POST" action="index.php/filemanager" id="fileFilterForm">
                    <input type="hidden" name="page" value="1">
                    <div class="row align-items-center">
                        <label for="fileGroupSelect" class="col-sm-2 col-form-label"><strong>Filter by Group:</strong></label>
                        <div class="col-sm-10">
                            <div class="input-group">
                                <select class="form-select" name="fileGroupSelect" id="fileGroupSelect" onchange="$('#fileFilterForm').submit()">
                                    <?php
                                        // Query: Get unique BGROUPKEYs for files with BDIRECT='IN', BFILE>0, BFILEPATH not empty, joined with BRAG
                                        $groupKeys = [];
                                        $selectedGroup = isset($_POST['fileGroupSelect']) ? $_POST['fileGroupSelect'] : '';
                                        
                                        $sql = "SELECT DISTINCT BRAG.BGROUPKEY, COUNT(*) as file_count
                                                FROM BMESSAGES
                                                INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                                                WHERE BMESSAGES.BUSERID = " . $_SESSION["USERPROFILE"]["BID"] . "
                                                  AND BMESSAGES.BDIRECT = 'IN'
                                                  AND BMESSAGES.BFILE > 0
                                                  AND BMESSAGES.BFILEPATH != ''
                                                  AND BRAG.BGROUPKEY != ''
                                                GROUP BY BRAG.BGROUPKEY
                                                ORDER BY BRAG.BGROUPKEY";
                                        $res = db::Query($sql);
                                        
                                        echo "<option value=''>All files...</option>";
                                        while ($row = db::FetchArr($res)) {
                                            $isSelected = ($selectedGroup == $row['BGROUPKEY']) ? 'selected' : '';
                                            echo "<option value='" . htmlspecialchars($row['BGROUPKEY']) . "' $isSelected>" . 
                                                 htmlspecialchars($row['BGROUPKEY']) . " (" . $row['file_count'] . " entries)</option>";
                                        }
                                    ?>
                                </select>
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <?php if (!empty($selectedGroup)): ?>
                                <a href="index.php/filemanager" class="btn btn-outline-secondary">Clear Filter</a>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($selectedGroup)): ?>
                                <div class="form-text text-primary">
                                    <i class="fas fa-filter"></i> Currently filtering by: <strong><?php echo htmlspecialchars($selectedGroup); ?></strong>
                                </div>
                            <?php else: ?>
                                <div class="form-text">Choose a file group to filter the results. Upload new files above to create new groups.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <form method="POST" action="index.php/filemanager">
                <input type="hidden" name="action" value="bulkdelete">
                <?php if (isset($_GET['page']) && $_GET['page'] > 1): ?>
                <input type="hidden" name="page" value="<?php echo intval($_GET['page']); ?>">
                <?php endif; ?>
                <?php if (isset($_GET['fileGroupSelect']) && !empty($_GET['fileGroupSelect'])): ?>
                <input type="hidden" name="fileGroupSelect" value="<?php echo htmlspecialchars($_GET['fileGroupSelect']); ?>">
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm">
                        <thead class="table-light">
                            <tr style="font-size: 0.85rem;">
                                <th style="width: 40px;">
                                    <!-- Optionally, a master checkbox for select all -->
                                    <input type="checkbox" id="selectAll" onclick="toggleAllCheckboxes(this)">
                                </th>
                                <th style="width: 80px;">FILE ID</th>
                                <th style="width: 200px;">NAME</th>
                                <th style="width: 100px;">DIRECTION</th>
                                <th style="width: 120px;">GROUP</th>
                                <th>DETAILS</th>
                                <th style="width: 140px;">UPLOADED</th>
                                <th style="width: 100px;">ACTION</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 0.9rem;">
                            <?php
                            
                                // TODO: Add your PHP loop here to populate the table
                                // Logic to populate the files table based on selected group
                                // Include DB tools if not already included
                                // require_once("../inc/_confdb.php"); // Uncomment if needed

                                $userId = $_SESSION["USERPROFILE"]["BID"];
                                $where = "BMESSAGES.BUSERID = " . intval($userId) . " 
                                    AND BMESSAGES.BFILE = 1 
                                    AND BMESSAGES.BFILEPATH != ''";

                                // Check if a group is selected (from POST or GET)
                                $selectedGroup = '';
                                if (isset($_POST['fileGroupSelect']) && !empty($_POST['fileGroupSelect'])) {
                                    $selectedGroup = db::EscString($_POST['fileGroupSelect']);
                                } elseif (isset($_GET['fileGroupSelect']) && !empty($_GET['fileGroupSelect'])) {
                                    $selectedGroup = db::EscString($_GET['fileGroupSelect']);
                                }

                                // Pagination setup
                                $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
                                $perPage = 30;
                                $offset = ($page - 1) * $perPage;
                                $limit = $perPage + 1; // Get one extra to check if there are more pages

                                if (!empty($selectedGroup)) {
                                    // Only show files in the selected group
                                    $sql = "SELECT BMESSAGES.*, BRAG.BGROUPKEY, 
                                            CASE WHEN BRAG.BMID IS NOT NULL THEN 1 ELSE 0 END as hasRAGContent
                                            FROM BMESSAGES
                                            INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                                            WHERE $where
                                              AND BRAG.BGROUPKEY = '" . $selectedGroup . "'
                                              GROUP by BRAG.BMID
                                            LIMIT $offset, $limit";
                                } else {
                                    // Show all files for the user with group info
                                    $sql = "SELECT BMESSAGES.*, BRAG.BGROUPKEY,
                                            CASE WHEN BRAG.BMID IS NOT NULL THEN 1 ELSE 0 END as hasRAGContent
                                            FROM BMESSAGES
                                            LEFT JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                                            WHERE $where
                                            GROUP BY BMESSAGES.BID
                                            LIMIT $offset, $limit";
                                }

                                $res = db::Query($sql);
                                $hasRows = false;
                                $rowCount = 0;
                                $hasMorePages = false;
                                
                                while ($row = db::FetchArr($res)) {
                                    $rowCount++;
                                    
                                    // Check if we have more than perPage rows (indicating there are more pages)
                                    if ($rowCount > $perPage) {
                                        $hasMorePages = true;
                                        break;
                                    }
                                    
                                    $hasRows = true;
                                    echo "<tr>";
                                    // Checkbox column
                                    echo "<td><input type='checkbox' name='selectedFiles[]' value='" . (int)$row['BID'] . "'></td>";
                                    echo "<td>" . htmlspecialchars($row['BID']) . "</td>";
                                    echo "<td>";
                                    echo htmlspecialchars(substr(basename($row['BFILEPATH']),0,36));
                                    if(strlen(basename($row['BFILEPATH'])) > 36) {
                                        echo "...";
                                    }
                                    echo "</td>";
                                    echo "<td>" . htmlspecialchars($row['BDIRECT']) . "</td>";
                                    // Group column
                                    echo "<td>";
                                    if (isset($row['BGROUPKEY']) && $row['BGROUPKEY'] != '') {
                                        if ($row['hasRAGContent']) {
                                            echo '<span class="badge bg-primary" style="cursor: pointer;" onclick="changeFileGroup(' . (int)$row['BID'] . ', \'' . htmlspecialchars($row['BGROUPKEY']) . '\')" title="Click to change group">' . htmlspecialchars($row['BGROUPKEY']) . '</span>';
                                        } else {
                                            echo '<span class="badge bg-primary" style="cursor: default;" title="File not yet processed for RAG">' . htmlspecialchars($row['BGROUPKEY']) . '</span>';
                                        }
                                    } else {
                                        if ($row['hasRAGContent']) {
                                            echo '<span class="badge bg-secondary" style="cursor: pointer;" onclick="changeFileGroup(' . (int)$row['BID'] . ', \'\')" title="Click to assign group">No Group</span>';
                                        }
                                    }
                                    echo "</td>";
                                    echo "<td>";
                                    echo "<small>";
                                    echo htmlspecialchars(substr($row['BFILETEXT'],0,50));
                                    echo "</small>";
                                    echo "</td>";
                                    echo "<td>" . htmlspecialchars($row['BDATETIME']) . "</td>";
                                    echo "<td>";
                                    // Action buttons container with nowrap
                                    echo "<div class='d-flex gap-1 flex-nowrap'>";
                                    // DELETE BUTTON
                                    echo "<button type='button' class='btn btn-sm btn-danger' onclick='deleteFile(" . (int)$row['BID'] . ")' title='Delete file'>";
                                    echo "<i class='fas fa-trash'></i>";
                                    echo "</button>";
                                    // DOWNLOAD BUTTON
                                    echo "<button type='button' class='btn btn-sm btn-secondary' onclick='downloadFile(\"" . htmlspecialchars($row['BFILEPATH']) . "\")' title='Download file'>";
                                    echo "<i class='fas fa-download'></i>";
                                    echo "</button>";
                                    echo "</div>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                                if (!$hasRows) {
                                    echo "<tr><td colspan='8' class='text-center'>No files found</td></tr>";
                                }
                            ?>
                        </tbody>
                    </table>
                </div>
                <!-- Bulk action buttons -->
                <div class="mt-2">
                    <button type="submit" name="bulkDelete" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete the selected files?');">
                        Delete Selected</button>
                    <!-- Add more bulk action buttons as needed -->
                </div>
                
                <!-- Pagination Controls -->
                <?php if ($hasRows || $page > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small">
                        Page <?php echo $page; ?> 
                        <?php if ($hasRows): ?>
                            (Showing <?php echo $rowCount; ?> files)
                        <?php endif; ?>
                    </div>
                    <div class="btn-group" role="group">
                        <?php if ($page > 1): ?>
                            <a href="index.php/filemanager?page=<?php echo ($page - 1); ?><?php echo !empty($selectedGroup) ? '&fileGroupSelect=' . urlencode($selectedGroup) : ''; ?>" 
                               class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($hasMorePages): ?>
                            <a href="index.php/filemanager?page=<?php echo ($page + 1); ?><?php echo !empty($selectedGroup) ? '&fileGroupSelect=' . urlencode($selectedGroup) : ''; ?>" 
                               class="btn btn-outline-primary btn-sm">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</main>

<script>
// RAG Upload functionality
let selectedRAGFiles = [];

document.addEventListener('DOMContentLoaded', function() {
    const ragFilesInput = document.getElementById('ragFiles');
    const groupKey = document.getElementById('groupKey');
    const existingGroupSelect = document.getElementById('existingGroupSelect');
    const ragUploadBtn = document.getElementById('ragUploadBtn');
    const ragFilePreview = document.getElementById('ragFilePreview');
    const ragFileList = document.getElementById('ragFileList');
    const ragUploadForm = document.getElementById('ragUploadForm');
    const ragUploadStatus = document.getElementById('ragUploadStatus');

    // Handle existing group selection
    existingGroupSelect.addEventListener('change', function() {
        if (this.value) {
            groupKey.value = this.value;
            validateRAGForm();
        }
    });

    // Handle file selection
    ragFilesInput.addEventListener('change', function() {
        selectedRAGFiles = Array.from(this.files);
        updateRAGFilePreview();
        validateRAGForm();
    });

    // Handle group key input
    groupKey.addEventListener('input', validateRAGForm);

    // Handle form submission
    ragUploadForm.addEventListener('submit', function(e) {
        e.preventDefault();
        uploadRAGFiles();
    });

    function updateRAGFilePreview() {
        if (selectedRAGFiles.length === 0) {
            ragFilePreview.style.display = 'none';
            return;
        }

        ragFileList.innerHTML = '';
        selectedRAGFiles.forEach((file, index) => {
            const fileItem = document.createElement('div');
            fileItem.className = 'd-flex justify-content-between align-items-center mb-1 p-1';
            fileItem.innerHTML = `
                <span><i class="fas fa-file"></i> ${file.name} (${(file.size / 1024).toFixed(1)} KB)</span>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRAGFile(${index})">
                    <i class="fas fa-times"></i>
                </button>
            `;
            ragFileList.appendChild(fileItem);
        });
        ragFilePreview.style.display = 'block';
    }

    function validateRAGForm() {
        const hasFiles = selectedRAGFiles.length >= 1;
        const hasGroupKey = groupKey.value.trim().length >= 3;
        
        ragUploadBtn.disabled = !(hasFiles && hasGroupKey);
        
        if (!hasFiles && selectedRAGFiles.length > 0) {
            ragUploadStatus.textContent = 'Please select at least 1 file';
            ragUploadStatus.className = 'text-warning';
        } else if (!hasGroupKey && groupKey.value.trim().length > 0) {
            ragUploadStatus.textContent = 'Group keyword must be at least 3 characters';
            ragUploadStatus.className = 'text-warning';
        } else if (hasFiles && hasGroupKey) {
            ragUploadStatus.textContent = 'Ready to upload';
            ragUploadStatus.className = 'text-success';
        } else {
            ragUploadStatus.textContent = '';
        }
    }

    function uploadRAGFiles() {
        const formData = new FormData();
        formData.append('action', 'ragUpload');
        formData.append('groupKey', $('#groupKey').val().trim());
        
        selectedRAGFiles.forEach(file => {
            formData.append('files[]', file);
        });

        ragUploadBtn.disabled = true;
        ragUploadStatus.textContent = 'Uploading and processing files...';
        ragUploadStatus.className = 'text-info';

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                ragUploadStatus.textContent = data.message;
                ragUploadStatus.className = 'text-success';
                
                // Reset form
                setTimeout(() => {
                    ragUploadForm.reset();
                    selectedRAGFiles = [];
                    ragFilePreview.style.display = 'none';
                    ragUploadStatus.textContent = '';
                    validateRAGForm();
                    
                    // Refresh page to show new files
                    window.location.reload();
                }, 2000);
            } else {
                ragUploadStatus.textContent = 'Error: ' + (data.error || 'Upload failed');
                ragUploadStatus.className = 'text-danger';
                ragUploadBtn.disabled = false;
            }
        })
        .catch(error => {
            ragUploadStatus.textContent = 'Error: ' + error.message;
            ragUploadStatus.className = 'text-danger';
            ragUploadBtn.disabled = false;
        });
    }

    // Make functions globally available
    window.removeRAGFile = function(index) {
        selectedRAGFiles.splice(index, 1);
        updateRAGFilePreview();
        validateRAGForm();
        
        // Update file input
        const dt = new DataTransfer();
        selectedRAGFiles.forEach(file => dt.items.add(file));
        ragFilesInput.files = dt.files;
    };
});

// Existing file management functions
function deleteFile(ID) {
    if(confirm("Delete file with ID: " + ID +"?")) {
        // Preserve current page and filter when deleting
        const urlParams = new URLSearchParams(window.location.search);
        const page = urlParams.get('page') || '1';
        const fileGroupSelect = urlParams.get('fileGroupSelect') || '';
        
        let deleteUrl = "<?php echo $GLOBALS['baseUrl']; ?>index.php/filemanager?action=delete&id=" + ID;

        if (page && page !== '1') deleteUrl += "&page=" + page;
        if (fileGroupSelect) deleteUrl += "&fileGroupSelect=" + encodeURIComponent(fileGroupSelect);
        //alert(deleteUrl);
        window.location.replace(deleteUrl);
    }
}

function downloadFile(filePath) {
    window.open('up/' + filePath, '_blank');
}

function toggleAllCheckboxes(source) {
    const checkboxes = document.querySelectorAll('input[name="selectedFiles[]"]');
    for (const cb of checkboxes) {
        cb.checked = source.checked;
    }
}

// File group management functions - moved outside DOMContentLoaded to be globally accessible
function changeFileGroup(fileId, currentGroup) {
    console.log('changeFileGroup called with fileId:', fileId, 'currentGroup:', currentGroup);
    
    // Show loading state
    const modal = new bootstrap.Modal(document.getElementById('genericModal'));
    document.getElementById('genericModalLabel').textContent = 'Change File Group';
    document.getElementById('genericModalBody').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading groups...</div>';
    document.getElementById('genericModalFooter').innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
    modal.show();
    
    // Fetch available groups
    fetch('api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=getFileGroups'
    })
    .then(response => response.json())
    .then(data => {
        console.log('getFileGroups response:', data);
        if (data.success) {
            showGroupSelectionModal(fileId, currentGroup, data.groups);
        } else {
            document.getElementById('genericModalBody').innerHTML = '<div class="alert alert-danger">Error loading groups: ' + (data.error || 'Unknown error') + '</div>';
        }
    })
    .catch(error => {
        console.error('Error fetching groups:', error);
        document.getElementById('genericModalBody').innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
    });
}

function showGroupSelectionModal(fileId, currentGroup, groups) {
    console.log('showGroupSelectionModal called with fileId:', fileId, 'currentGroup:', currentGroup, 'groups:', groups);
    
    let modalContent = '<div class="mb-3">';
    modalContent += '<label for="newGroupSelect" class="form-label"><strong>Select New Group:</strong></label>';
    modalContent += '<select class="form-select" id="newGroupSelect">';
    modalContent += '<option value="">No Group</option>';
    
    groups.forEach(group => {
        const selected = (group === currentGroup) ? 'selected' : '';
        modalContent += '<option value="' + group + '" ' + selected + '>' + group + '</option>';
    });
    
    modalContent += '</select>';
    modalContent += '<div class="form-text">Choose a group for this file. Each file can only be in one group.</div>';
    modalContent += '</div>';
    
    document.getElementById('genericModalBody').innerHTML = modalContent;
    document.getElementById('genericModalFooter').innerHTML = 
        '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
        '<button type="button" class="btn btn-primary" onclick="saveFileGroup(' + fileId + ')">Save Changes</button>';
}

function saveFileGroup(fileId) {
    const newGroup = document.getElementById('newGroupSelect').value;
    console.log('saveFileGroup called with fileId:', fileId, 'newGroup:', newGroup);
    
    // Show loading state
    document.getElementById('genericModalBody').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Saving changes...</div>';
    document.getElementById('genericModalFooter').innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
    
    // Send the change request
    fetch('api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=changeGroupOfFile&fileId=' + fileId + '&newGroup=' + encodeURIComponent(newGroup)
    })
    .then(response => response.json())
    .then(data => {
        console.log('changeGroupOfFile response:', data);
        if (data.success) {
            document.getElementById('genericModalBody').innerHTML = '<div class="alert alert-success">Group updated successfully!</div>';
            document.getElementById('genericModalFooter').innerHTML = '<button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="window.location.reload()">Close & Refresh</button>';
        } else {
            document.getElementById('genericModalBody').innerHTML = '<div class="alert alert-danger">Error updating group: ' + (data.error || 'Unknown error') + '</div>';
            document.getElementById('genericModalFooter').innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
        }
    })
    .catch(error => {
        console.error('Error saving file group:', error);
        document.getElementById('genericModalBody').innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
        document.getElementById('genericModalFooter').innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
    });
}
</script>
