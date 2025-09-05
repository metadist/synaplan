<?php
//==================================================================================
session_start();
//==================================================================================
// ------------------------------------------------------ lang config
// Use session fallback handler for robust session management

if(!isset($_SESSION["LANG"])) {
    $_SESSION["LANG"] = "en";
}
if(isset($_REQUEST["lang"])) {
  $_SESSION["LANG"] = $_REQUEST["lang"];
}

// ------------------------------------------------------ include files
// Use Composer autoload and new app core includes
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// ------------------------------------------------------ handle authentication actions that need redirects
// Only handle actions that require redirects BEFORE HTML output
if(isset($_REQUEST['action'])) {
    switch($_REQUEST['action']) {
        case 'oidc_login':
            OidcAuth::initiateAuth();
            exit; // Should redirect, but exit as backup
            break;
        case 'login':
            $success = Frontend::setUserFromWebLogin();
            break;
        case 'register':
            $result = UserRegistration::registerNewUser();
            $_SESSION['registration_result'] = $result;
            break;
        case 'logout':
            handleLogout();
            break;
    }
}

// ------------------------------------------------------ handle auto-redirect to OIDC
// Check if user needs to be auto-redirected to OIDC provider
if (!isset($_SESSION['USERPROFILE']) && 
    OidcAuth::isConfigured() && 
    OidcAuth::isAutoRedirectEnabled() &&
    !isset($_SESSION['oidc_error']) &&
    !isset($_REQUEST['action'])) {
    
    OidcAuth::initiateAuth();
    exit;
}

?>
<!doctype html>
<html lang="en">
  <head>
    <base href="<?php echo $GLOBALS["baseUrl"]; ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Ralfs.AI Dashboard">
    <meta name="author" content="Ralf Schwoebel, based on Bootstrap 5">
    <meta name="generator" content="Manually crafted by Ralf, Yusuf and with help from Cursor">
    <title>synaplan - digital thinking</title>
    <link href="node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/statics/fa/css/all.min.css" rel="stylesheet">
    <!-- Custom styles for this template -->
    <link href="assets/statics/css/dashboard.css?v=<?php echo date("Ymd"); ?>-1" rel="stylesheet">
    <!-- JQuery we need quickly, sorry SEO -->
    <script src="node_modules/jquery/dist/jquery.min.js"></script>
  </head>
  <body>    
    <div class="container-fluid">
      <div class="row">
        <?php \App\director::dispatch(); ?>
      </div>
    </div>
    <script src="node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="node_modules/feather-icons/dist/feather.min.js"></script>
    <script src="assets/statics/js/dashboard.js"></script>
    


    <!-- Generic Modal for various purposes -->
    <div class="modal fade" id="genericModal" tabindex="-1" aria-labelledby="genericModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="genericModalLabel">Modal Title</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="genericModalBody">
                    <!-- Modal content will be dynamically loaded here -->
                </div>
                <div class="modal-footer" id="genericModalFooter">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
  </body>
</html>
