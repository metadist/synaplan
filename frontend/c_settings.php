<?php
// -----------------------------------------------------
// Form processing for profile updates
// -----------------------------------------------------
if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'updateProfile') {
    // Get current user ID from session
    $userId = $_SESSION["USERPROFILE"]["BID"] ?? 0;
    
    if ($userId > 0) {
        // Clean and collect form data
        $userDetails = [];
        
        // Personal information
        $userDetails['firstName'] = db::EscString($_REQUEST['firstName'] ?? '');
        $userDetails['lastName'] = db::EscString($_REQUEST['lastName'] ?? '');
        $userDetails['phone'] = db::EscString($_REQUEST['phone'] ?? '');
        
        // Company information
        $userDetails['companyName'] = db::EscString($_REQUEST['companyName'] ?? '');
        $userDetails['vatId'] = db::EscString($_REQUEST['vatId'] ?? '');
        
        // Billing address
        $userDetails['street'] = db::EscString($_REQUEST['street'] ?? '');
        $userDetails['zipCode'] = db::EscString($_REQUEST['zipCode'] ?? '');
        $userDetails['city'] = db::EscString($_REQUEST['city'] ?? '');
        $userDetails['country'] = db::EscString($_REQUEST['country'] ?? 'DE');
        
        // Account settings
        $userDetails['language'] = db::EscString($_REQUEST['language'] ?? 'en');
        $userDetails['timezone'] = db::EscString($_REQUEST['timezone'] ?? 'Europe/Berlin');
        $userDetails['invoiceEmail'] = db::EscString($_REQUEST['invoiceEmail'] ?? '');
        
        // Update email in BMAIL field (clear text)
        $email = db::EscString($_REQUEST['email'] ?? '');
        if (!empty($email)) {
            $updateEmailSQL = "UPDATE BUSER SET BMAIL = '" . $email . "' WHERE BID = " . $userId;
            db::Query($updateEmailSQL);
        }
        
        // Update password if provided
        $currentPassword = $_REQUEST['currentPassword'] ?? '';
        $newPassword = $_REQUEST['newPassword'] ?? '';
        
        if (!empty($currentPassword) && !empty($newPassword)) {
            // Verify current password
            $checkSQL = "SELECT BPW FROM BUSER WHERE BID = " . $userId;
            $checkRes = db::Query($checkSQL);
            $checkArr = db::FetchArr($checkRes);
            
            if ($checkArr && $checkArr['BPW'] == md5($currentPassword)) {
                // Update password with MD5 hash
                $updatePwSQL = "UPDATE BUSER SET BPW = '" . md5($newPassword) . "' WHERE BID = " . $userId;
                db::Query($updatePwSQL);
            }
        }
        
        // Update user details JSON
        $userDetailsJson = json_encode($userDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $updateDetailsSQL = "UPDATE BUSER SET BUSERDETAILS = '" . db::EscString($userDetailsJson) . "' WHERE BID = " . $userId;
        db::Query($updateDetailsSQL);   
    }
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <form id="profileForm" method="POST" action="index.php/settings">
        <input type="hidden" name="action" id="action" value="updateProfile">
        
        <!-- Personal Information Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-user"></i> Personal Information</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="firstName" class="col-sm-2 col-form-label"><strong>First Name:</strong></label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="firstName" id="firstName" placeholder="Enter your first name" required>
                        <div class="form-text">Your given name</div>
                    </div>
                    <label for="lastName" class="col-sm-2 col-form-label"><strong>Last Name:</strong></label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="lastName" id="lastName" placeholder="Enter your last name" required>
                        <div class="form-text">Your family name</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="email" class="col-sm-2 col-form-label"><strong>Email:</strong></label>
                    <div class="col-sm-4">
                        <input type="email" class="form-control" name="email" id="email" placeholder="your@email.com" required readonly>
                        <div class="form-text">Your login email (cannot be changed)</div>
                    </div>
                    <label for="phone" class="col-sm-2 col-form-label"><strong>Phone:</strong></label>
                    <div class="col-sm-4">
                        <input type="tel" class="form-control" name="phone" id="phone" placeholder="+49 123 456789">
                        <div class="form-text">Optional contact number</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Company Information Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-building"></i> Company Information (Optional)</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="companyName" class="col-sm-2 col-form-label"><strong>Company Name:</strong></label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="companyName" id="companyName" placeholder="Your Company GmbH">
                        <div class="form-text">Leave empty if you're a private person</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="vatId" class="col-sm-2 col-form-label"><strong>VAT ID:</strong></label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="vatId" id="vatId" placeholder="DE123456789">
                        <div class="form-text">German: Umsatzsteuer-ID</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Billing Address Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-map-marker-alt"></i> Billing Address</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="street" class="col-sm-2 col-form-label"><strong>Street & Number:</strong></label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="street" id="street" placeholder="Musterstraße 123" required>
                        <div class="form-text">Street name and house number</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="zipCode" class="col-sm-2 col-form-label"><strong>ZIP Code:</strong></label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="zipCode" id="zipCode" placeholder="12345" required>
                        <div class="form-text">Postal code</div>
                    </div>
                    <label for="city" class="col-sm-2 col-form-label"><strong>City:</strong></label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="city" id="city" placeholder="Berlin" required>
                        <div class="form-text">City name</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="country" class="col-sm-2 col-form-label"><strong>Country:</strong></label>
                    <div class="col-sm-10">
                        <select class="form-select" name="country" id="country" required>
                            <option value="">Select your country...</option>
                            <option value="DE">Germany</option>
                            <option value="AT">Austria</option>
                            <option value="CH">Switzerland</option>
                            <option value="FR">France</option>
                            <option value="IT">Italy</option>
                            <option value="ES">Spain</option>
                            <option value="NL">Netherlands</option>
                            <option value="BE">Belgium</option>
                            <option value="LU">Luxembourg</option>
                            <option value="DK">Denmark</option>
                            <option value="SE">Sweden</option>
                            <option value="NO">Norway</option>
                            <option value="FI">Finland</option>
                            <option value="PL">Poland</option>
                            <option value="CZ">Czech Republic</option>
                            <option value="SK">Slovakia</option>
                            <option value="HU">Hungary</option>
                            <option value="SI">Slovenia</option>
                            <option value="HR">Croatia</option>
                            <option value="PT">Portugal</option>
                            <option value="IE">Ireland</option>
                            <option value="GB">United Kingdom</option>
                            <option value="US">United States</option>
                            <option value="CA">Canada</option>
                            <option value="AU">Australia</option>
                            <option value="JP">Japan</option>
                            <option value="OTHER">Other</option>
                        </select>
                        <div class="form-text">Required for proper tax calculation</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Settings Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-cog"></i> Account Settings</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="language" class="col-sm-2 col-form-label"><strong>Language:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="language" id="language">
                            <option value="en">English</option>
                            <option value="de">Deutsch</option>
                            <option value="fr">Français</option>
                            <option value="es">Español</option>
                            <option value="it">Italiano</option>
                        </select>
                        <div class="form-text">Interface language</div>
                    </div>
                    <label for="timezone" class="col-sm-2 col-form-label"><strong>Timezone:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="timezone" id="timezone">
                            <option value="Europe/Berlin">Europe/Berlin (GMT+1)</option>
                            <option value="Europe/London">Europe/London (GMT+0)</option>
                            <option value="Europe/Paris">Europe/Paris (GMT+1)</option>
                            <option value="Europe/Rome">Europe/Rome (GMT+1)</option>
                            <option value="Europe/Madrid">Europe/Madrid (GMT+1)</option>
                            <option value="America/New_York">America/New_York (GMT-5)</option>
                            <option value="America/Los_Angeles">America/Los_Angeles (GMT-8)</option>
                            <option value="Asia/Tokyo">Asia/Tokyo (GMT+9)</option>
                        </select>
                        <div class="form-text">Your local timezone</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="invoiceEmail" class="col-sm-2 col-form-label"><strong>Invoice Email:</strong></label>
                    <div class="col-sm-10">
                        <input type="email" class="form-control" name="invoiceEmail" id="invoiceEmail" placeholder="billing@yourcompany.com">
                        <div class="form-text">Alternative email for invoices (leave empty to use main email)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Password Change Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-lock"></i> Change Password</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="currentPassword" class="col-sm-2 col-form-label"><strong>Current Password:</strong></label>
                    <div class="col-sm-4">
                        <input type="password" class="form-control" name="currentPassword" id="currentPassword" placeholder="Enter current password">
                        <div class="form-text">Leave empty to keep current password</div>
                    </div>
                    <label for="newPassword" class="col-sm-2 col-form-label"><strong>New Password:</strong></label>
                    <div class="col-sm-4">
                        <input type="password" class="form-control" name="newPassword" id="newPassword" placeholder="Enter new password">
                        <div class="form-text">Minimum 8 characters</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="confirmPassword" class="col-sm-2 col-form-label"><strong>Confirm Password:</strong></label>
                    <div class="col-sm-4">
                        <input type="password" class="form-control" name="confirmPassword" id="confirmPassword" placeholder="Confirm new password">
                        <div class="form-text">Must match new password</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="card">
            <div class="card-body text-center">
                <div class="btn-group" role="group" aria-label="Profile actions">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save"></i> Save Profile
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="loadProfile()">
                        <i class="fas fa-refresh"></i> Reset Form
                    </button>
                </div>
                <div class="mt-3">
                    <small class="text-muted">All data is stored securely and used only for invoicing purposes according to German law (DSGVO).</small>
                </div>
            </div>
        </div>

    </form>
</main>

<script>
    // Load user profile data when page loads
    document.addEventListener('DOMContentLoaded', function() {
        loadProfile();
    });

    // Function to load current profile data
    function loadProfile() {
        const formData = new FormData();
        formData.append('action', 'getProfile');

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Error loading profile:', data.error);
                // Don't show alert for missing profile data, just use empty form
            } else {
                // Populate form fields with existing data
                document.getElementById('email').value = data.BMAIL || '';
                
                if (data.BUSERDETAILS) {
                    const details = typeof data.BUSERDETAILS === 'string' ? JSON.parse(data.BUSERDETAILS) : data.BUSERDETAILS;
                    
                    document.getElementById('firstName').value = details.firstName || '';
                    document.getElementById('lastName').value = details.lastName || '';
                    document.getElementById('phone').value = details.phone || '';
                    document.getElementById('companyName').value = details.companyName || '';
                    document.getElementById('vatId').value = details.vatId || '';
                    document.getElementById('street').value = details.street || '';
                    document.getElementById('zipCode').value = details.zipCode || '';
                    document.getElementById('city').value = details.city || '';
                    document.getElementById('country').value = details.country || 'DE';
                    document.getElementById('language').value = details.language || 'en';
                    document.getElementById('timezone').value = details.timezone || 'Europe/Berlin';
                    document.getElementById('invoiceEmail').value = details.invoiceEmail || '';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }

    // Real-time validation for password confirmation
    document.getElementById('confirmPassword').addEventListener('input', function() {
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = this.value;
        
        if (confirmPassword && newPassword !== confirmPassword) {
            this.classList.add('is-invalid');
        } else {
            this.classList.remove('is-invalid');
        }
    });

    // Remove validation classes when user starts typing
    document.querySelectorAll('.form-control, .form-select').forEach(field => {
        field.addEventListener('input', function() {
            this.classList.remove('is-invalid');
        });
    });
</script>

<style>
    .card-header h5 {
        color: #495057;
    }
    .form-control.is-invalid, .form-select.is-invalid {
        border-color: #dc3545;
    }
    .btn-group .btn {
        min-width: 150px;
    }
    .text-muted {
        font-size: 0.875rem;
    }
</style>