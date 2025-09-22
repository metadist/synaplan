<?php // Lost password form ?>
<script
  src="https://challenges.cloudflare.com/turnstile/v0/api.js"
  async
  defer
></script>
<main class="col-md-12 ms-sm-auto col-lg-12 px-md-4" id="contentMain">
    <H1><?php _s("Reset your password", __FILE__, $_SESSION["LANG"]); ?></H1>
    <p>
        <?php _s("Enter your registered email address and we'll send you a new password.", __FILE__, $_SESSION["LANG"]); ?><BR>
        <?php _s("Remembered it?", __FILE__, $_SESSION["LANG"]); ?> <B><a href="index.php"><?php _s("Back to login", __FILE__, $_SESSION["LANG"]); ?></a></B>
    </p>

    <div class="alert alert-success d-none" id="successAlert" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <strong><?php _s("Email sent!", __FILE__, $_SESSION["LANG"]); ?></strong><br>
        <span id="successMessage"><?php _s("If the email exists in our system, we sent you a new password.", __FILE__, $_SESSION["LANG"]); ?></span>
    </div>

    <div class="alert alert-danger d-none" id="errorAlert" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong><?php _s("Request Failed!", __FILE__, $_SESSION["LANG"]); ?></strong><br>
        <span id="errorMessage"></span>
    </div>

    <form id="lostpwForm" target="_top">
        <div class="form-group mt-2">
            <label for="email"><?php _s("Email", __FILE__, $_SESSION["LANG"]); ?></label>
            <input type="email" class="form-control mt-2" id="email" name="email" placeholder="<?php _s("Enter your registered email", __FILE__, $_SESSION["LANG"]); ?>" required>
        </div>
        <button type="submit" class="btn btn-primary mt-2" id="submitBtn">
            <span class="spinner-border spinner-border-sm d-none me-2" id="submitSpinner" role="status" aria-hidden="true"></span>
            <?php _s("Send new password", __FILE__, $_SESSION["LANG"]); ?>
        </button>
        <BR><BR>
        <div class="cf-turnstile" data-sitekey="0x4AAAAAAB1d8VjDhX7_hJRg" data-theme="light" data-size="normal"></div>
    </form>

    <BR>
    <p><?php _s("Go to our homepage for more information: <a href=\"https://www.synaplan.com/\">https://synaplan.com/</a>", __FILE__, $_SESSION["LANG"]); ?></p>
</main>

<script>
document.getElementById('lostpwForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const email = document.getElementById('email').value;

    document.getElementById('successAlert').classList.add('d-none');
    document.getElementById('errorAlert').classList.add('d-none');

    if (!email || email.indexOf('@') < 1) {
        showError('<?php _s("Please enter a valid email address.", __FILE__, $_SESSION["LANG"]); ?>');
        return;
    }

    const submitBtn = document.getElementById('submitBtn');
    const submitSpinner = document.getElementById('submitSpinner');
    submitBtn.disabled = true;
    submitSpinner.classList.remove('d-none');

    // build FormData from the form to include Turnstile token automatically
    const formEl = document.getElementById('lostpwForm');
    const formData = new FormData(formEl);
    formData.append('action', 'lostPassword');

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess(data.message || '<?php _s("If the email exists, we sent a new password.", __FILE__, $_SESSION["LANG"]); ?>');
            document.getElementById('lostpwForm').reset();
        } else {
            showError(data.error || '<?php _s("Password reset failed. Please try again.", __FILE__, $_SESSION["LANG"]); ?>');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showError('<?php _s("An error occurred. Please try again.", __FILE__, $_SESSION["LANG"]); ?>');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitSpinner.classList.add('d-none');
    });
});

function showSuccess(message) {
    const successAlert = document.getElementById('successAlert');
    const successMessage = document.getElementById('successMessage');
    successMessage.textContent = message;
    successAlert.classList.remove('d-none');
    successAlert.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showError(message) {
    const errorAlert = document.getElementById('errorAlert');
    const errorMessage = document.getElementById('errorMessage');
    errorMessage.textContent = message;
    errorAlert.classList.remove('d-none');
    errorAlert.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>


