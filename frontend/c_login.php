<?php // https://oauth2-client.thephpleague.com/providers/league/?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _s('Please login', __FILE__, $_SESSION['LANG']); ?> | Synaplan</title>
    <link rel="icon" type="image/x-icon" href="assets/statics/img/favicon.ico">
    <link href="assets/statics/css/auth-pages.css?v=2" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo htmlspecialchars(ApiKeys::getRecaptchaSiteKey()); ?>"></script>
</head>
<body>
    <!-- Header with Logo -->
    <header class="auth-header">
        <a href="<?php echo ApiKeys::getBaseUrl(); ?>" class="auth-logo">
            <img src="assets/statics/img/synaplan_logo_ondark.svg" alt="Synaplan">
        </a>
    </header>

    <!-- Main Content -->
    <main class="auth-container">
        <div class="auth-form-box">
            <h1 class="auth-form-title"><?php _s('Please login', __FILE__, $_SESSION['LANG']); ?></h1>
            
            <?php if (OidcAuth::isConfigured() && OidcAuth::isAutoRedirectEnabled()): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> <?php _s("Automatic login redirect is enabled. If you weren't automatically redirected, please try the SSO login below.", __FILE__, $_SESSION['LANG']); ?>
            </div>
            <?php endif; ?>
            
            <div class="auth-form-description">
                <?php _s('You may login with your email address and password.', __FILE__, $_SESSION['LANG']); ?><br>
                <?php _s('Registration is free', __FILE__, $_SESSION['LANG']); ?>: <a href="index.php/register" class="auth-link"><?php _s('Register', __FILE__, $_SESSION['LANG']); ?></a>
            </div>
            
            <form action="index.php" method="post" target="_top" id="loginForm">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                <div class="form-group">
                    <label for="email"><?php _s('Email', __FILE__, $_SESSION['LANG']); ?></label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="<?php _s('Enter your registered email', __FILE__, $_SESSION['LANG']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="password"><?php _s('Password', __FILE__, $_SESSION['LANG']); ?></label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="<?php _s('Enter password', __FILE__, $_SESSION['LANG']); ?>" required>
                </div>
                <button type="submit" class="btn btn-primary" id="loginBtn"><?php _s('Login', __FILE__, $_SESSION['LANG']); ?></button>
            </form>
            
            <?php if (OidcAuth::isConfigured()): ?>
            <div class="sso-section">
                <p><?php _s('Or sign in with SSO', __FILE__, $_SESSION['LANG']); ?></p>
                <form action="index.php" method="post" target="_top">
                    <input type="hidden" name="action" value="oidc_login">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="fas fa-sign-in-alt"></i> <?php _s('Sign in with SSO', __FILE__, $_SESSION['LANG']); ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['oidc_error'])): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($_SESSION['oidc_error']); ?>
                <?php unset($_SESSION['oidc_error']); ?>
            </div>
            <?php endif; ?>
            
            <div class="text-center mt-3">
                <a href="index.php/lostpw" class="auth-link"><?php _s('Forgot your password?', __FILE__, $_SESSION['LANG']); ?></a>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="auth-footer">
        <?php _s('Go to our homepage for more information: <a href="https://www.synaplan.com/">https://www.synaplan.com/</a>', __FILE__, $_SESSION['LANG']); ?>
    </footer>

    <script>
    const RECAPTCHA_SITE_KEY = '<?php echo htmlspecialchars(ApiKeys::getRecaptchaSiteKey()); ?>';

    // Handle form submission with reCAPTCHA v3
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Execute reCAPTCHA v3
        grecaptcha.ready(function() {
            grecaptcha.execute(RECAPTCHA_SITE_KEY, {action: 'login'}).then(function(token) {
                // Set the token in the hidden field
                document.getElementById('g-recaptcha-response').value = token;
                // Submit the form
                e.target.submit();
            });
        });
    });
    </script>
</body>
</html>