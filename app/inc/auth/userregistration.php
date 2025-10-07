<?php

/**
 * User Registration Management Class
 *
 * Handles user registration, email confirmation, and PIN generation.
 * Extracted from Frontend class for better separation of concerns.
 *
 * @package Auth
 */

class UserRegistration
{
    /**
     * Register a new user
     *
     * Creates a new user account with email confirmation
     *
     * @return array Array with success status and error message if applicable
     */
    public static function registerNewUser(): array
    {
        $retArr = ['success' => false, 'error' => ''];

        // Check if this is a WordPress plugin registration
        $isWordPressRegistration = isset($_REQUEST['source']) && $_REQUEST['source'] === 'wordpress_plugin';

        if (!$isWordPressRegistration) {
            // Validate Turnstile captcha for regular web registrations
            $captchaOk = Frontend::myCFcaptcha();
            if (!$captchaOk) {
                $retArr['error'] = 'Captcha verification failed. Please try again.';
                return $retArr;
            }
        }

        // Get email and password from request
        $email = isset($_REQUEST['email']) ? db::EscString($_REQUEST['email']) : '';
        $password = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';
        $confirmPassword = isset($_REQUEST['confirmPassword']) ? $_REQUEST['confirmPassword'] : '';

        // For WordPress registrations, use password as confirmPassword
        if ($isWordPressRegistration) {
            $confirmPassword = $password;
        }

        // Validate input
        if (strlen($email) > 0 && strlen($password) > 0 && $password === $confirmPassword && strlen($password) >= 6) {
            // Check if email already exists
            $checkSQL = "SELECT BID FROM BUSER WHERE BMAIL = '".$email."'";
            $checkRes = db::Query($checkSQL);
            $existingUser = db::FetchArr($checkRes);

            if ($existingUser) {
                // Email already exists
                $retArr['error'] = 'An account with this email address already exists.';
                return $retArr;
            }

            // Generate 6-character alphanumeric PIN
            $pin = self::generatePin();

            // MD5 encrypt the password
            $passwordMd5 = md5($password);

            // Create user details JSON
            $userDetails = [
                'firstName' => '',
                'lastName' => '',
                'phone' => '',
                'companyName' => '',
                'vatId' => '',
                'street' => '',
                'zipCode' => '',
                'city' => '',
                'country' => '',
                'language' => $_SESSION['LANG'] ?? 'en',
                'timezone' => '',
                'invoiceEmail' => '',
                'emailConfirmed' => false,
                'pin' => $pin
            ];

            // Insert new user
            $insertSQL = "INSERT INTO BUSER (BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID, BUSERLEVEL, BUSERDETAILS) 
                         VALUES ('".date('YmdHis')."', 'MAIL', '".$email."', '".$passwordMd5."', '".db::EscString($email)."', 'PIN:".$pin."', '".db::EscString(json_encode($userDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."')";

            db::Query($insertSQL);
            $newUserId = db::LastId();

            if ($newUserId > 0) {
                if ($isWordPressRegistration) {
                    // WordPress plugin registration - create API key in database (same process as web)
                    $random = bin2hex(random_bytes(24));
                    $api_key = 'sk_live_' . $random;
                    $now = time();

                    // Insert API key into database (same as ApiKeyManager::createApiKey)
                    $ins = 'INSERT INTO BAPIKEYS (BOWNERID, BNAME, BKEY, BSTATUS, BCREATED, BLASTUSED) 
                            VALUES (' . $newUserId . ", 'WordPress Plugin', '" . db::EscString($api_key) . "', 'active', " . $now . ', 0)';
                    db::Query($ins);

                    $widget_config = [
                        'integration_type' => 'floating-button',
                        'color' => '#007bff',
                        'icon_color' => '#ffffff',
                        'position' => 'bottom-right',
                        'auto_message' => 'Hello! How can I help you today?',
                        'auto_open' => false,
                        'prompt' => 'general'
                    ];
                    $widget_id = 'widget_' . time() . '_' . substr(md5(json_encode($widget_config)), 0, 8);

                    $retArr['success'] = true;
                    $retArr['data'] = [
                        'user_id' => 'wp_user_' . $newUserId,
                        'email' => $email,
                        'api_key' => $api_key,
                        'widget_id' => $widget_id,
                        'widget_config' => $widget_config,
                        'message' => 'User registered successfully with WordPress site verification'
                    ];
                } else {
                    // Regular web registration - send confirmation email
                    $emailSent = EmailService::sendRegistrationConfirmation($email, $pin, $newUserId);
                    if ($emailSent) {
                        $retArr['success'] = true;
                        $retArr['message'] = 'Registration successful! Please check your email for confirmation.';
                    } else {
                        // User was created but email failed - still return success but with warning
                        $retArr['success'] = true;
                        $retArr['message'] = 'Account created successfully, but confirmation email could not be sent. Please contact support.';
                    }
                }

                // For WordPress registrations, also send confirmation email (same process as web)
                if ($isWordPressRegistration) {
                    $emailSent = EmailService::sendRegistrationConfirmation($email, $pin, $newUserId);
                    if (!$emailSent) {
                        // Log the email failure but don't fail the registration
                        error_log("WordPress registration: Confirmation email failed for user ID: $newUserId, email: $email");
                    }
                }
            } else {
                $retArr['error'] = 'Failed to create user account. Please try again.';
            }
        } else {
            if (strlen($email) == 0) {
                $retArr['error'] = 'Email address is required.';
            } elseif (strlen($password) == 0) {
                $retArr['error'] = 'Password is required.';
            } elseif ($password !== $confirmPassword) {
                $retArr['error'] = 'Passwords do not match.';
            } elseif (strlen($password) < 6) {
                $retArr['error'] = 'Password must be at least 6 characters long.';
            } else {
                $retArr['error'] = 'Invalid input data.';
            }
        }

        return $retArr;
    }

    /**
     * Generate a random 6-character alphanumeric PIN
     *
     * @return string 6-character PIN
     */
    private static function generatePin(): string
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $pin = '';
        for ($i = 0; $i < 6; $i++) {
            $pin .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $pin;
    }

    /**
     * Lost password handler
     *
     * Validates Turnstile, generates a new password if user exists,
     * writes it (MD5) to BUSER and sends an email using EmailService.
     * Always returns a generic success message (no user enumeration).
     */
    public static function lostPassword(): array
    {
        // Captcha validation (re-using existing helper)
        $captchaOk = Frontend::myCFcaptcha();
        if (!$captchaOk) {
            return ['success' => false, 'error' => 'Captcha verification failed'];
        }

        $email = isset($_REQUEST['email']) ? db::EscString(trim($_REQUEST['email'])) : '';
        if ($email === '' || strpos($email, '@') === false) {
            return ['success' => false, 'error' => 'Please provide a valid email address'];
        }

        // Lookup user
        $uSQL = "SELECT BID, BMAIL FROM BUSER WHERE BMAIL='".$email."' LIMIT 1";
        $uRes = db::Query($uSQL);
        $uArr = db::FetchArr($uRes);

        $message = 'If the email exists, we sent a new password.';

        if ($uArr && isset($uArr['BID'])) {
            // Generate new password
            $newPassword = Tools::createRandomString(10, 14);
            $newPasswordMd5 = md5($newPassword);

            // Update DB
            $upd = "UPDATE BUSER SET BPW='".$newPasswordMd5."' WHERE BID=".(int)$uArr['BID'];
            db::Query($upd);

            // Send mail (English)
            try {
                EmailService::sendPasswordResetEmail($uArr['BMAIL'], $newPassword);
            } catch (\Throwable $e) {
                if (!empty($GLOBALS['debug'])) {
                    error_log('lostPassword mail failed: '.$e->getMessage());
                }
            }
        }

        return ['success' => true, 'message' => $message];
    }
}
