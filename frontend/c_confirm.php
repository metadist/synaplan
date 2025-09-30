<?php // Email confirmation page ?>
<?php
// Process confirmation
$confirmed = false;
$errorMessage = '';
$successMessage = '';

if(isset($_GET['PIN']) && isset($_GET['UID'])) {
    $pin = db::EscString($_GET['PIN']);
    $userId = intval($_GET['UID']);
    
    if(strlen($pin) == 6 && $userId > 0) {
        // Check if user exists and PIN matches
        $checkSQL = "SELECT * FROM BUSER WHERE BID = ".$userId." AND BUSERLEVEL = 'PIN:".$pin."'";
        $checkRes = db::Query($checkSQL);
        $userArr = db::FetchArr($checkRes);
        
        if($userArr) {
            // Update user status to NEW and clear PIN
            $updateSQL = "UPDATE BUSER SET BUSERLEVEL = 'NEW' WHERE BID = ".$userId;
            db::Query($updateSQL);
            
            if(db::AffectedRows() > 0) {
                $confirmed = true;
                $successMessage = "Your email has been successfully confirmed! You can now log in to your account.";
            } else {
                $errorMessage = "Failed to update account status. Please try again or contact support.";
            }
        } else {
            $errorMessage = "Invalid confirmation link. The PIN may have expired or the user ID is incorrect.";
        }
    } else {
        $errorMessage = "Invalid confirmation parameters.";
    }
} else {
    $errorMessage = "Missing confirmation parameters.";
}
?>

<main class="col-md-12 ms-sm-auto col-lg-12 px-md-4" id="contentMain">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card mt-5">
                <div class="card-body text-center">
                    <?php if($confirmed): ?>
                        <div class="text-success mb-3">
                            <i class="fas fa-check-circle fa-3x"></i>
                        </div>
                        <h2 class="card-title text-success"><?php _s("Email Confirmed!", __FILE__, $_SESSION["LANG"]); ?></h2>
                        <p class="card-text"><?php echo $successMessage; ?></p>
                        <div class="mt-4">
                            <a href="index.php" class="btn btn-primary"><?php _s("Go to Login", __FILE__, $_SESSION["LANG"]); ?></a>
                        </div>
                    <?php else: ?>
                        <div class="text-danger mb-3">
                            <i class="fas fa-exclamation-triangle fa-3x"></i>
                        </div>
                        <h2 class="card-title text-danger"><?php _s("Confirmation Failed", __FILE__, $_SESSION["LANG"]); ?></h2>
                        <p class="card-text"><?php echo $errorMessage; ?></p>
                        <div class="mt-4">
                            <a href="index.php" class="btn btn-primary"><?php _s("Back to Login", __FILE__, $_SESSION["LANG"]); ?></a>
                            <a href="index.php/register" class="btn btn-outline-secondary ms-2"><?php _s("Register Again", __FILE__, $_SESSION["LANG"]); ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <p class="text-muted">
                    <?php _s("Need help?", __FILE__, $_SESSION["LANG"]); ?> 
                    <a href="mailto:info@metadist.de"><?php _s("Contact Support", __FILE__, $_SESSION["LANG"]); ?></a>
                </p>
                <p class="text-muted">
                    <?php 
                    $homeUrl = ApiKeys::getBaseUrl();
                    _s("Go to our homepage: <a href=\"$homeUrl\">$homeUrl</a>", __FILE__, $_SESSION["LANG"]); 
                    ?>
                </p>
            </div>
        </div>
    </div>
</main>
