<?php
/**
 * Email Service Class
 * 
 * Centralized email service for all application email needs.
 * Provides templated emails and consistent sender configuration.
 * 
 * @package Services
 */

class EmailService {
    
    /** @var string Default sender email */
    private static $defaultSender = "info@metadist.de";
    
    /** @var string Default reply-to email */
    private static $defaultReplyTo = "noreply@synaplan.com";
    
    /**
     * Send registration confirmation email
     * 
     * @param string $email User's email address
     * @param string $pin Confirmation PIN
     * @param int $userId User ID
     * @return bool True if email sent successfully
     */
    public static function sendRegistrationConfirmation(string $email, string $pin, int $userId): bool {
        $confirmLink = $GLOBALS["baseUrl"] . "index.php/confirm/?PIN=" . $pin . "&UID=" . $userId;
        
        $htmlText = "
        <h2>Welcome to Synaplan!</h2>
        <p>Thank you for registering with Synaplan. To complete your registration, please click the confirmation link below:</p>
        <p><a href='".$confirmLink."' style='background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Confirm Email Address</a></p>
        <p>Or copy and paste this link into your browser:</p>
        <p>".$confirmLink."</p>
        <p>This link will expire once used. If you did not create this account, please ignore this email.</p>
        <p>Best regards,<br>The Synaplan Team</p>
        ";
        
        $plainText = "
        Welcome to Synaplan!
        
        Thank you for registering with Synaplan. To complete your registration, please visit this link:
        
        ".$confirmLink."
        
        This link will expire once used. If you did not create this account, please ignore this email.
        
        Best regards,
        The Synaplan Team
        ";
        
        return self::sendEmail(
            $email,
            "Synaplan - Confirm Your Email Address",
            $htmlText,
            $plainText
        );
    }
    
    /**
     * Send email confirmation notification
     * 
     * @param string $email User's email address
     * @return bool True if email sent successfully
     */
    public static function sendEmailConfirmation(string $email): bool {
        $htmlText = "
        <h2>Email Confirmed!</h2>
        <p>Your email address has been successfully confirmed.</p>
        <p>You can now use all features of Synaplan.</p>
        <p>Best regards,<br>The Synaplan Team</p>
        ";
        
        $plainText = "
        Email Confirmed!
        
        Your email address has been successfully confirmed.
        You can now use all features of Synaplan.
        
        Best regards,
        The Synaplan Team
        ";
        
        return self::sendEmail(
            $email,
            "Synaplan - Email Confirmed",
            $htmlText,
            $plainText
        );
    }
    
    /**
     * Send limit notification email
     * 
     * @param string $email User's email address
     * @param string $limitType Type of limit reached
     * @param string $details Additional details about the limit
     * @return bool True if email sent successfully
     */
    public static function sendLimitNotification(string $email, string $limitType, string $details = ""): bool {
        $htmlText = "
        <h2>Usage Limit Reached</h2>
        <p>Your account has reached the $limitType limit.</p>
        " . (!empty($details) ? "<p>Details: $details</p>" : "") . "
        <p>Please check your account settings or contact support if you need to increase your limits.</p>
        <p>Best regards,<br>The Synaplan Team</p>
        ";
        
        $plainText = "
        Usage Limit Reached
        
        Your account has reached the $limitType limit.
        " . (!empty($details) ? "Details: $details\n" : "") . "
        Please check your account settings or contact support if you need to increase your limits.
        
        Best regards,
        The Synaplan Team
        ";
        
        return self::sendEmail(
            $email,
            "Synaplan - Usage Limit Reached",
            $htmlText,
            $plainText
        );
    }
    
    /**
     * Send password reset email with the new password
     * 
     * @param string $email Recipient email
     * @param string $newPassword Newly generated password (already stored hashed)
     * @return bool
     */
    public static function sendPasswordResetEmail(string $email, string $newPassword): bool {
        $loginLink = $GLOBALS["baseUrl"] . "index.php";
        
        $htmlText = "
        <h2>Password Reset</h2>
        <p>We've generated a new password for your Synaplan account.</p>
        <p><strong>New password:</strong> <code>" . htmlspecialchars($newPassword) . "</code></p>
        <p>For your security, please log in and change this password immediately in your account settings.</p>
        <p><a href='".$loginLink."' style='background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Go to Login</a></p>
        <p>If you didn't request this, please secure your account and contact support.</p>
        <p>Best regards,<br>The Synaplan Team</p>
        ";
        
        $plainText = "
        Password Reset
        
        We've generated a new password for your Synaplan account.
        New password: " . $newPassword . "
        
        For your security, please log in and change this password immediately in your account settings.
        Login: " . $loginLink . "
        
        If you didn't request this, please secure your account and contact support.
        
        Best regards,
        The Synaplan Team
        ";
        
        return self::sendEmail(
            $email,
            "Synaplan - Your new password",
            $htmlText,
            $plainText
        );
    }
    
    /**
     * Send admin notification email
     * 
     * @param string $subject Email subject
     * @param string $message Email message
     * @param string $adminEmail Admin email (optional, uses default if not provided)
     * @return bool True if email sent successfully
     */
    public static function sendAdminNotification(string $subject, string $message, string $adminEmail = ""): bool {
        $recipient = !empty($adminEmail) ? $adminEmail : self::$defaultSender;
        
        $htmlText = "
        <h2>System Notification</h2>
        <p>$message</p>
        <p>Timestamp: " . date('Y-m-d H:i:s') . "</p>
        ";
        
        $plainText = "
        System Notification
        
        $message
        
        Timestamp: " . date('Y-m-d H:i:s') . "
        ";
        
        return self::sendEmail(
            $recipient,
            "Synaplan - $subject",
            $htmlText,
            $plainText
        );
    }
    
    /**
     * Send generic email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @param string $plainBody Plain text email body
     * @param string $replyTo Reply-to email address (optional)
     * @return bool True if email sent successfully
     */
    public static function sendEmail(string $to, string $subject, string $htmlBody, string $plainBody, string $replyTo = ""): bool {
        try {
            $replyToEmail = !empty($replyTo) ? $replyTo : self::$defaultReplyTo;
            
            return _mymail(
                self::$defaultSender,
                $to,
                $subject,
                $htmlBody,
                $plainBody,
                $replyToEmail
            );
        } catch (Exception $e) {
            if($GLOBALS["debug"]) {
                error_log("EmailService failed to send email: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Set default sender email
     * 
     * @param string $email Default sender email
     */
    public static function setDefaultSender(string $email): void {
        self::$defaultSender = $email;
    }
    
    /**
     * Set default reply-to email
     * 
     * @param string $email Default reply-to email
     */
    public static function setDefaultReplyTo(string $email): void {
        self::$defaultReplyTo = $email;
    }
}
