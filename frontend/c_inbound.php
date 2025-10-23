<?php
// -----------------------------------------------------
// Inbound configuration
// -----------------------------------------------------

require_once __DIR__ . '/../app/inc/api/_inboundconf.php';

// Handle form submission for keyword save
$keywordMessage = '';
$keywordMessageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['keyword'])) {
    $result = InboundConf::saveGmailKeyword($_POST['keyword']);
    $keywordMessage = $result['message'];
    $keywordMessageType = $result['success'] ? 'success' : 'danger';
}

// Handle keyword deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_keyword'])) {
    if (InboundConf::deleteGmailKeyword()) {
        $keywordMessage = 'Keyword deleted successfully';
        $keywordMessageType = 'success';
    } else {
        $keywordMessage = 'Error deleting keyword';
        $keywordMessageType = 'danger';
    }
}

// Get current keyword for display
$currentKeyword = InboundConf::getGmailKeyword();
?>
<link rel="stylesheet" href="assets/statics/fa/css/all.min.css">
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <B>THIS IS NOT YET WORKING, WE ARE BETA</B>
    <h1><?php _s('Inbound', __FILE__, $_SESSION['LANG']); ?></h1>
    <p>
        <?php _s('You can reach this platform via different channels.', __FILE__, $_SESSION['LANG']); ?><br>
        <?php _s('Different channels offer different features, if you set those up.', __FILE__, $_SESSION['LANG']); ?><br>
        <?php _s('Please take a look at the channels listed below:', __FILE__, $_SESSION['LANG']); ?><br>
    </p>

    <!-- WhatsApp Channel Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fab fa-whatsapp me-1"></i> WhatsApp Channel(s)</h5>
        </div>
        <div class="card-body">
            <?php
            $numArr = InboundConf::getWhatsAppNumbers();
foreach ($numArr as $num) {
    print '+'.$num['BWAOUTNUMBER'].': default handling<br>';
}
?>
            <!-- Add your WhatsApp channel form here if needed -->
        </div>
    </div>

    <!-- Email Channel Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-envelope"></i> Email Channel(s)</h5>
        </div>
        <div class="card-body">
            <a href="mailto:smart@synaplan.com">smart@synaplan.com</a>: default handling<br>
            
            <?php if (!empty($keywordMessage)): ?>
                <div class="alert alert-<?php echo $keywordMessageType; ?> alert-dismissible fade show mt-3" role="alert">
                    <?php echo htmlspecialchars($keywordMessage); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($currentKeyword !== null): ?>
                <div class="alert alert-info mt-3">
                    <strong>Your current keyword:</strong> 
                    <code>smart+<?php echo htmlspecialchars($currentKeyword); ?>@synaplan.com</code>
                    <form action="index.php/inbound" method="post" class="d-inline ms-2">
                        <input type="hidden" name="delete_keyword" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete your keyword?');">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <form action="index.php/inbound" method="post" class="mt-3" id="keywordForm">
                <label for="keyword" class="form-label">
                    <?php echo $currentKeyword !== null ? 'Update your keyword:' : 'Set a keyword to add your own handling:'; ?>
                </label>
                <div class="input-group mb-2">
                    <span class="input-group-text">smart+</span>
                    <input 
                        type="text" 
                        name="keyword" 
                        id="keyword" 
                        class="form-control" 
                        placeholder="keyword" 
                        value="<?php echo htmlspecialchars($currentKeyword ?? ''); ?>"
                        pattern="[a-zA-Z0-9_-]+"
                        minlength="4"
                        required
                    >
                    <span class="input-group-text">@synaplan.com</span>
                    <button type="button" class="btn btn-outline-secondary" id="checkKeyword">
                        <i class="fas fa-search"></i> Check
                    </button>
                </div>
                <div id="keywordFeedback" class="mb-2"></div>
                <small class="form-text text-muted d-block mb-2">
                    Minimum 4 characters. Only letters, numbers, underscores (_), and hyphens (-) allowed. 
                    Must be unique system-wide.
                </small>
                <button type="submit" class="btn btn-primary" id="saveKeywordBtn">
                    <i class="fas fa-save"></i> <?php echo $currentKeyword !== null ? 'Update keyword' : 'Save keyword'; ?>
                </button>
            </form>
        </div>
    </div>

    <script>
    // Real-time keyword validation and availability checker via API
    document.addEventListener('DOMContentLoaded', function() {
        const keywordInput = document.getElementById('keyword');
        const checkBtn = document.getElementById('checkKeyword');
        const feedbackDiv = document.getElementById('keywordFeedback');
        const saveBtn = document.getElementById('saveKeywordBtn');
        
        // Check keyword availability via API
        function checkKeyword() {
            const keyword = keywordInput.value.trim().toLowerCase();
            
            if (!keyword) {
                feedbackDiv.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Please enter a keyword</div>';
                return;
            }
            
            // Client-side format validation first
            if (keyword.length < 4) {
                feedbackDiv.innerHTML = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Keyword must be at least 4 characters long</div>';
                return;
            }
            
            if (!/^[a-zA-Z0-9_-]+$/.test(keyword)) {
                feedbackDiv.innerHTML = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Keyword can only contain letters, numbers, underscores, and hyphens</div>';
                return;
            }
            
            // Show loading state
            feedbackDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Checking availability in database...</div>';
            checkBtn.disabled = true;
            
            // Make AJAX call to API to check database
            fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'checkGmailKeyword',
                    keyword: keyword
                })
            })
            .then(response => response.json())
            .then(data => {
                checkBtn.disabled = false;
                
                if (data.available === true) {
                    feedbackDiv.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' + 
                        (data.message || 'Keyword "' + keyword + '" is available!') + '</div>';
                } else {
                    let iconClass = 'fa-times-circle';
                    let alertClass = 'danger';
                    
                    if (data.type === 'format') {
                        iconClass = 'fa-exclamation-triangle';
                        alertClass = 'warning';
                    }
                    
                    feedbackDiv.innerHTML = '<div class="alert alert-' + alertClass + '"><i class="fas ' + iconClass + '"></i> ' + 
                        (data.message || 'Keyword is not available') + '</div>';
                }
            })
            .catch(error => {
                checkBtn.disabled = false;
                console.error('Error checking keyword:', error);
                feedbackDiv.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error checking keyword. Please try again.</div>';
            });
        }
        
        // Check button click handler
        checkBtn.addEventListener('click', checkKeyword);
        
        // Real-time validation on input (client-side only)
        keywordInput.addEventListener('input', function() {
            const keyword = this.value.trim();
            
            // Clear feedback when typing
            feedbackDiv.innerHTML = '';
            
            // Show instant validation feedback
            if (keyword.length > 0 && keyword.length < 4) {
                feedbackDiv.innerHTML = '<small class="text-warning">Minimum 4 characters required</small>';
            } else if (keyword && !/^[a-zA-Z0-9_-]+$/.test(keyword)) {
                feedbackDiv.innerHTML = '<small class="text-danger">Only letters, numbers, underscores, and hyphens allowed</small>';
            } else if (keyword.length >= 4) {
                feedbackDiv.innerHTML = '<small class="text-success"><i class="fas fa-check"></i> Format looks good - click "Check" to verify availability</small>';
            }
        });
        
        // Normalize to lowercase on blur
        keywordInput.addEventListener('blur', function() {
            this.value = this.value.trim().toLowerCase();
        });
    });
    </script>

    <!-- Repeat the card pattern for API Channel and Web Widget below -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-code"></i> API Channel</h5>
        </div>
        <div class="card-body">
            Simple API calls with your personal API key:<br>
            https://synawork.com/api.php<br><br>
            Example:<br><br>
            <code>
                curl -X POST https://synawork.com/api.php \<br>
                -H "Authorization: Bearer YOUR_API_KEY" \<br>
                -H "Content-Type: application/json" \<br>
                -d '{"number": "1234567890", "message": "Hello, world!"}'<br>
            </code>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-window-maximize"></i> Web Widget</h5>
        </div>
        <div class="card-body">
            <strong>To activate the widget, please enter your domain name like "yourdomain.net" in the field below.</strong>
            <BR>
             
            It is hidden by default, until the collapse plugin adds the appropriate classes that we use to style each element. 
            These classes control the overall appearance, as well as the showing and hiding via CSS transitions. 
        </div>
    </div>
</main>