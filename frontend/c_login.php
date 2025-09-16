<?php // https://oauth2-client.thephpleague.com/providers/league/ ?>
<main class="col-md-12 ms-sm-auto col-lg-12 px-md-4" id="contentMain">
    <H1><?php _s("Please login", __FILE__, $_SESSION["LANG"]); ?></H1>
    
    <?php if (OidcAuth::isConfigured() && OidcAuth::isAutoRedirectEnabled()): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> <?php _s("Automatic login redirect is enabled. If you weren't automatically redirected, please try the SSO login below.", __FILE__, $_SESSION["LANG"]); ?>
    </div>
    <?php endif; ?>
    <p>
        <?php _s("You may login with your email address and password.", __FILE__, $_SESSION["LANG"]); ?><BR>
        <?php _s("Registration is free", __FILE__, $_SESSION["LANG"]); ?>: <B><a href="index.php/register"><?php _s("Register", __FILE__, $_SESSION["LANG"]); ?></a></B>
    </p>
    <form action="index.php" method="post" target="_top">
        <input type="hidden" name="action" value="login">
        <div class="form-group mt-2">
            <label for="email"><?php _s("Email", __FILE__, $_SESSION["LANG"]); ?></label>
            <input type="email" class="form-control mt-2" id="email" name="email" placeholder="<?php _s("Enter your registered email", __FILE__, $_SESSION["LANG"]); ?>">
        </div>
        <div class="form-group mt-2">
            <label for="password"><?php _s("Password", __FILE__, $_SESSION["LANG"]); ?></label>
            <input type="password" class="form-control mt-2" id="password" name="password" placeholder="<?php _s("Enter password", __FILE__, $_SESSION["LANG"]); ?>">
        </div>
        <button type="submit" class="btn btn-primary mt-2"><?php _s("Login", __FILE__, $_SESSION["LANG"]); ?></button>
    </form>
    <?php if (OidcAuth::isConfigured()): ?>
    <div class="mt-4 pt-3 border-top">
        <p class="text-center mb-3"><?php _s("Or sign in with SSO", __FILE__, $_SESSION["LANG"]); ?></p>
        <form action="index.php" method="post" class="text-center" target="_top">
            <input type="hidden" name="action" value="oidc_login">
            <button type="submit" class="btn btn-outline-primary">
                <i class="fas fa-sign-in-alt"></i> <?php _s("Sign in with SSO", __FILE__, $_SESSION["LANG"]); ?>
            </button>
        </form>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['oidc_error'])): ?>
    <div class="alert alert-danger mt-3" role="alert">
        <?php echo htmlspecialchars($_SESSION['oidc_error']); ?>
        <?php unset($_SESSION['oidc_error']); ?>
    </div>
    <?php endif; ?>
    
    <BR>
    <p><?php _s("Forgot your password?", __FILE__, $_SESSION["LANG"]); ?> <B><a href="forgotpw.php"><?php _s("Reset password", __FILE__, $_SESSION["LANG"]); ?></a></B></p>
    <BR>
    <p><?php _s("Go to our homepage for more information: <a href=\"https://www.synaplan.com/\">https://synaplan.com/</a>", __FILE__, $_SESSION["LANG"]); ?></p>
</main>