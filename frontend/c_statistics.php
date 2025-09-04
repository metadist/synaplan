<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><?php _s("Welcome to synaplan!", __FILE__, $_SESSION["LANG"]); ?></h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/chat'">
                    <i class="fas fa-comments"></i> Chat
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/prompts'">
                    <i class="fas fa-cog"></i> Prompts
                </button>
            </div>
        </div>
    </div>

    <?php
    if(isset($_SESSION['USERPROFILE']['BUSERDETAILS'])) {
        $userDetails = json_decode($_SESSION['USERPROFILE']['BUSERDETAILS'], true);
        if(!isset($_SESSION['USERPROFILE']["BMAIL"]) AND $_SESSION['USERPROFILE']['BINTYPE'] == 'WA') {
            echo '<div class="alert alert-warning" role="alert">';
            _s("Please register your email address to use this service right: ", __FILE__, $_SESSION["LANG"]);
            echo "<a href='index.php/settings' class='alert-link'>";
            _s("Register", __FILE__, $_SESSION["LANG"]);
            echo "</a>";
            echo '</div>';
        }
    }

    // Get dashboard statistics
    $stats = Frontend::getDashboardStats();
    $latestFiles = FileManager::getLatestFiles(8);
    ?>

    <!-- Message Statistics Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-chart-bar text-primary"></i> Message Statistics
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="h3 text-primary mb-1"><?php echo number_format($stats['total_messages']); ?></div>
                        <div class="text-muted small"><?php _s("Total Messages", __FILE__, $_SESSION["LANG"]); ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="h3 text-success mb-1"><?php echo number_format($stats['messages_sent']); ?></div>
                        <div class="text-muted small"><?php _s("Messages Sent", __FILE__, $_SESSION["LANG"]); ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="h3 text-info mb-1"><?php echo number_format($stats['messages_received']); ?></div>
                        <div class="text-muted small"><?php _s("Messages Received", __FILE__, $_SESSION["LANG"]); ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="h3 text-warning mb-1"><?php echo number_format($stats['total_files']); ?></div>
                        <div class="text-muted small"><?php _s("Total Files", __FILE__, $_SESSION["LANG"]); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- File Statistics Row -->
            <div class="row mt-3">
                <div class="col-md-6 mb-3">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="h4 text-success mb-1"><?php echo number_format($stats['files_sent']); ?></div>
                        <div class="text-muted small"><?php _s("Files Sent", __FILE__, $_SESSION["LANG"]); ?></div>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="h4 text-info mb-1"><?php echo number_format($stats['files_received']); ?></div>
                        <div class="text-muted small"><?php _s("Files Received", __FILE__, $_SESSION["LANG"]); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Latest Files Card -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-file-alt text-success"></i> Latest Files
            </h5>
        </div>
        <div class="card-body">
            <?php if(count($latestFiles) > 0) { ?>
                <div class="row">
                    <?php foreach($latestFiles as $file) { ?>
                        <div class="col-lg-6 col-md-12 mb-3">
                            <div class="card border h-100">
                                <div class="card-body p-3">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0 me-3">
                                            <?php
                                            $fileIcon = 'fa-file';
                                            $fileColor = 'text-secondary';
                                            switch(strtolower($file['BFILETYPE'])) {
                                                case 'pdf':
                                                    $fileIcon = 'fa-file-pdf';
                                                    $fileColor = 'text-danger';
                                                    break;
                                                case 'docx':
                                                case 'doc':
                                                    $fileIcon = 'fa-file-word';
                                                    $fileColor = 'text-primary';
                                                    break;
                                                case 'xlsx':
                                                case 'xls':
                                                    $fileIcon = 'fa-file-excel';
                                                    $fileColor = 'text-success';
                                                    break;
                                                case 'pptx':
                                                case 'ppt':
                                                    $fileIcon = 'fa-file-powerpoint';
                                                    $fileColor = 'text-warning';
                                                    break;
                                                case 'jpg':
                                                case 'jpeg':
                                                case 'png':
                                                case 'gif':
                                                    $fileIcon = 'fa-file-image';
                                                    $fileColor = 'text-info';
                                                    break;
                                                case 'mp3':
                                                case 'wav':
                                                    $fileIcon = 'fa-file-audio';
                                                    $fileColor = 'text-primary';
                                                    break;
                                                case 'mp4':
                                                case 'avi':
                                                    $fileIcon = 'fa-file-video';
                                                    $fileColor = 'text-danger';
                                                    break;
                                                case 'txt':
                                                    $fileIcon = 'fa-file-alt';
                                                    $fileColor = 'text-secondary';
                                                    break;
                                            }
                                            ?>
                                            <i class="fas <?php echo $fileIcon; ?> fa-2x <?php echo $fileColor; ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="card-title mb-1">
                                                <a href="index.php/chat/<?php echo $file['BID']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($file['FILENAME']); ?>
                                                </a>
                                            </h6>
                                            <p class="card-text small text-muted mb-2">
                                                <i class="fas fa-<?php echo $file['BDIRECT'] == 'IN' ? 'arrow-down text-success' : 'arrow-up text-primary'; ?>"></i>
                                                <?php echo $file['BDIRECT'] == 'IN' ? _s("Received", __FILE__, $_SESSION["LANG"]) : _s("Sent", __FILE__, $_SESSION["LANG"]); ?>
                                                • <?php echo Tools::myDateTime($file['BDATETIME']); ?>
                                            </p>
                                            <?php if(!empty($file['BTEXT'])) { ?>
                                                <p class="card-text small">
                                                    <?php echo htmlspecialchars(substr($file['BTEXT'], 0, 100)); ?><?php echo strlen($file['BTEXT']) > 100 ? '...' : ''; ?>
                                                </p>
                                            <?php } ?>
                                            <?php if(!empty($file['BTOPIC']) && $file['BTOPIC'] != 'UNKNOWN') { ?>
                                                <span class="badge bg-light text-dark small"><?php echo htmlspecialchars($file['BTOPIC']); ?></span>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
                
                <?php if(count($latestFiles) >= 8) { ?>
                    <div class="text-center mt-3">
                        <a href="index.php/filemanager" class="btn btn-outline-primary">
                            <i class="fas fa-folder-open"></i> <?php _s("View All Files", __FILE__, $_SESSION["LANG"]); ?>
                        </a>
                    </div>
                <?php } ?>
            <?php } else { ?>
                <div class="text-center py-4">
                    <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                    <p class="text-muted"><?php _s("No files found", __FILE__, $_SESSION["LANG"]); ?></p>
                    <a href="index.php/chat" class="btn btn-primary">
                        <i class="fas fa-plus"></i> <?php _s("Start a Chat", __FILE__, $_SESSION["LANG"]); ?>
                    </a>
                </div>
            <?php } ?>
        </div>
    </div>
</main>