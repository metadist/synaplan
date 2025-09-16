<?php // User registration form ?>
<script
  src="https://challenges.cloudflare.com/turnstile/v0/api.js"
  async
  defer
></script>
<main class="col-md-12 ms-sm-auto col-lg-12 px-md-4" id="contentMain">
    <H1><?php _s("Create Account", __FILE__, $_SESSION["LANG"]); ?></H1>
    <p>
        <?php _s("Please fill in the form below to create your account.", __FILE__, $_SESSION["LANG"]); ?><BR>
        <?php _s("Already have an account?", __FILE__, $_SESSION["LANG"]); ?> <B><a href="index.php"><?php _s("Login here", __FILE__, $_SESSION["LANG"]); ?></a></B>
    </p>
    
    <!-- Success Alert -->
    <div class="alert alert-success d-none" id="successAlert" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <strong><?php _s("Registration Successful!", __FILE__, $_SESSION["LANG"]); ?></strong><br>
        <?php _s("We've sent a confirmation email to your email address. Please check your inbox and click the confirmation link to activate your account.", __FILE__, $_SESSION["LANG"]); ?>
    </div>
    
    <!-- Error Alert -->
    <div class="alert alert-danger d-none" id="errorAlert" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong><?php _s("Registration Failed!", __FILE__, $_SESSION["LANG"]); ?></strong><br>
        <span id="errorMessage"></span>
    </div>
    
    <form id="registrationForm" target="_top">
        <div class="form-group mt-2">
            <label for="email"><?php _s("Email", __FILE__, $_SESSION["LANG"]); ?></label>
            <input type="email" class="form-control mt-2" id="email" name="email" placeholder="<?php _s("Enter your email address", __FILE__, $_SESSION["LANG"]); ?>" required>
        </div>
        <div class="form-group mt-2">
            <label for="password"><?php _s("Password", __FILE__, $_SESSION["LANG"]); ?></label>
            <input type="password" class="form-control mt-2" id="password" name="password" placeholder="<?php _s("Enter password (min. 6 characters)", __FILE__, $_SESSION["LANG"]); ?>" minlength="6" required>
        </div>
        <div class="form-group mt-2">
            <label for="confirmPassword"><?php _s("Confirm Password", __FILE__, $_SESSION["LANG"]); ?></label>
            <input type="password" class="form-control mt-2" id="confirmPassword" name="confirmPassword" placeholder="<?php _s("Confirm your password", __FILE__, $_SESSION["LANG"]); ?>" minlength="6" required>
        </div>
        <button type="submit" class="btn btn-primary mt-2" id="submitBtn">
            <span class="spinner-border spinner-border-sm d-none me-2" id="submitSpinner" role="status" aria-hidden="true"></span>
            <?php _s("Create Account", __FILE__, $_SESSION["LANG"]); ?>
        </button>
        <BR><BR>
        <div class="cf-turnstile" data-sitekey="0x4AAAAAAB1d8VjDhX7_hJRg" data-theme="light" data-size="normal"></div>
    </form>
    
    <BR>
    <p><?php _s("By creating an account, you agree to our terms of service.", __FILE__, $_SESSION["LANG"]); ?></p>
    <BR>
    <p><?php _s("Go to our homepage for more information: <a href=\"https://www.synaplan.com/\">https://synaplan.com/</a>", __FILE__, $_SESSION["LANG"]); ?></p>
</main>

<script>
document.getElementById('registrationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    
    // Hide any existing alerts
    document.getElementById('successAlert').classList.add('d-none');
    document.getElementById('errorAlert').classList.add('d-none');
    
    // Basic validation
    if (password !== confirmPassword) {
        showError('<?php _s("Passwords do not match!", __FILE__, $_SESSION["LANG"]); ?>');
        return;
    }
    
    if (password.length < 6) {
        showError('<?php _s("Password must be at least 6 characters long!", __FILE__, $_SESSION["LANG"]); ?>');
        return;
    }
    
    // Show loading state
    const submitBtn = document.getElementById('submitBtn');
    const submitSpinner = document.getElementById('submitSpinner');
    submitBtn.disabled = true;
    submitSpinner.classList.remove('d-none');
    
    // Prepare form data
    const formData = new FormData();
    formData.append('action', 'userRegister');
    formData.append('email', email);
    formData.append('password', password);
    formData.append('confirmPassword', confirmPassword);
    
    // Make API call
    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess();
            // Clear the form
            document.getElementById('registrationForm').reset();
        } else {
            showError(data.error || '<?php _s("Registration failed. Please try again.", __FILE__, $_SESSION["LANG"]); ?>');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showError('<?php _s("An error occurred. Please try again.", __FILE__, $_SESSION["LANG"]); ?>');
    })
    .finally(() => {
        // Reset button state
        submitBtn.disabled = false;
        submitSpinner.classList.add('d-none');
    });
});

function showSuccess() {
    const successAlert = document.getElementById('successAlert');
    successAlert.classList.remove('d-none');
    
    // Scroll to top to show the alert
    successAlert.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showError(message) {
    const errorAlert = document.getElementById('errorAlert');
    const errorMessage = document.getElementById('errorMessage');
    
    errorMessage.textContent = message;
    errorAlert.classList.remove('d-none');
    
    // Scroll to top to show the alert
    errorAlert.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>