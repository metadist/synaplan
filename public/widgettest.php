<?php
// Simple test script to verify widget functionality
session_start();

// Log out the user to test widget anonymously
if(isset($_SESSION['USERPROFILE'])) {
    // Store user info for later login reminder
    $loggedOutUser = $_SESSION['USERPROFILE'];
    // Clear the session to simulate anonymous access
    session_destroy();
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

$widgetId = $_REQUEST['widgetid'] ?? 1;
$uid = $_REQUEST['uid'] ?? 2; // Default to user ID 2 if not provided
?>
<html>
    <head>
        <title>Widget Test Page</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 40px;
                background-color: #f5f5f5;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            h1 {
                color: #333;
                text-align: center;
            }
            .info {
                background: #e7f3ff;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
                border-left: 4px solid #007bff;
            }
            .code {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                font-family: monospace;
                margin: 10px 0;
                border: 1px solid #dee2e6;
            }
            .alert {
                border-radius: 8px;
                border: none;
            }
            .alert-warning {
                background-color: #fff3cd;
                border-left: 4px solid #ffc107;
            }
            .alert-info {
                background-color: #d1ecf1;
                border-left: 4px solid #17a2b8;
            }
            .btn {
                border-radius: 6px;
                padding: 8px 16px;
                text-decoration: none;
                display: inline-block;
                margin: 5px 0;
            }
            .btn-primary {
                background-color: #007bff;
                color: white;
                border: 1px solid #007bff;
            }
            .btn-primary:hover {
                background-color: #0056b3;
                border-color: #0056b3;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Widget Test Page</h1>
            
            <?php if(isset($loggedOutUser)): ?>
            <div class="alert alert-warning" role="alert">
                <h4 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> You have been logged out!</h4>
                <p><strong>Important:</strong> You have been automatically logged out of your Synaplan session to test the widget anonymously.</p>
                <p>This simulates how anonymous visitors will experience the chat widget on your website.</p>
                <hr>
                <p class="mb-0">
                    <strong>To log back in after testing:</strong><br>
                    <a href="index.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-sign-in-alt"></i> Return to Login
                    </a>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="info">
                <strong>Widget Configuration:</strong><br>
                User ID: <?php echo htmlspecialchars($uid); ?><br>
                Widget ID: <?php echo htmlspecialchars($widgetId); ?>
            </div>
            
            <p>This page demonstrates the chat widget integration. The widget should appear on this page and function as an anonymous user would experience it.</p>
            
            <div class="code">
                <strong>Integration Code Used:</strong><br>
                &lt;script src="widget.php?uid=<?php echo htmlspecialchars($uid); ?>&amp;widgetid=<?php echo htmlspecialchars($widgetId); ?>"&gt;&lt;/script&gt;
            </div>
            
            <p>If you don't see the widget, please check:</p>
            <ul>
                <li>The widget configuration exists for User ID <?php echo htmlspecialchars($uid); ?> and Widget ID <?php echo htmlspecialchars($widgetId); ?></li>
                <li>The widget.php file is accessible</li>
                <li>No JavaScript errors in the browser console</li>
            </ul>
            
            <div class="alert alert-info" role="alert">
                <h5><i class="fas fa-info-circle"></i> Testing Notes:</h5>
                <ul class="mb-0">
                    <li>You are now testing as an <strong>anonymous user</strong></li>
                    <li>Chat history loading buttons should be hidden</li>
                    <li>Microphone functionality should be disabled</li>
                    <li>File uploads are limited to JPG, GIF, PNG, and PDF files</li>
                    <li>Rate limiting applies to prevent abuse</li>
                </ul>
            </div>
        </div>
        
        <!-- Synaplan Chat Widget -->
        <script>
        (function() {
            var script = document.createElement('script');
            script.src = 'widget.php?uid=<?php echo htmlspecialchars($uid); ?>&widgetid=<?php echo htmlspecialchars($widgetId); ?>';
            script.async = true;
            document.head.appendChild(script);
        })();
        </script>
    </body>
</html>

