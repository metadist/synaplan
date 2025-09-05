<?php
/**
 * User Registration Management Class
 * 
 * Handles user registration, email confirmation, and PIN generation.
 * Extracted from Frontend class for better separation of concerns.
 * 
 * @package Auth
 */

class UserRegistration {
    
    /**
     * Register a new user
     * 
     * Creates a new user account with email confirmation
     * 
     * @return array Array with success status and error message if applicable
     */
    public static function registerNewUser(): array {
        $retArr = ["success" => false, "error" => ""];
        
        // Get email and password from request
        $email = isset($_REQUEST['email']) ? DB::EscString($_REQUEST['email']) : '';
        $password = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';
        $confirmPassword = isset($_REQUEST['confirmPassword']) ? $_REQUEST['confirmPassword'] : '';
        
        // Validate input
        if(strlen($email) > 0 && strlen($password) > 0 && $password === $confirmPassword && strlen($password) >= 6) {
            // Check if email already exists
            $checkSQL = "SELECT BID FROM BUSER WHERE BMAIL = '".$email."'";
            $checkRes = DB::Query($checkSQL);
            $existingUser = DB::FetchArr($checkRes);
            
            if($existingUser) {
                // Email already exists
                $retArr["error"] = "An account with this email address already exists.";
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
                'language' => $_SESSION["LANG"] ?? 'en',
                'timezone' => '',
                'invoiceEmail' => '',
                'emailConfirmed' => false,
                'pin' => $pin
            ];
            
            // Insert new user
            $insertSQL = "INSERT INTO BUSER (BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID, BUSERLEVEL, BUSERDETAILS) 
                         VALUES ('".date("YmdHis")."', 'MAIL', '".$email."', '".$passwordMd5."', '".DB::EscString($email)."', 'PIN:".$pin."', '".DB::EscString(json_encode($userDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."')";
            
            DB::Query($insertSQL);
            $newUserId = DB::LastId();
            
            if($newUserId > 0) {
                // Send confirmation email
                $emailSent = EmailService::sendRegistrationConfirmation($email, $pin, $newUserId);
                if($emailSent) {
                    $retArr["success"] = true;
                    $retArr["message"] = "Registration successful! Please check your email for confirmation.";
                } else {
                    // User was created but email failed - still return success but with warning
                    $retArr["success"] = true;
                    $retArr["message"] = "Account created successfully, but confirmation email could not be sent. Please contact support.";
                }
            } else {
                $retArr["error"] = "Failed to create user account. Please try again.";
            }
        } else {
            if(strlen($email) == 0) {
                $retArr["error"] = "Email address is required.";
            } elseif(strlen($password) == 0) {
                $retArr["error"] = "Password is required.";
            } elseif($password !== $confirmPassword) {
                $retArr["error"] = "Passwords do not match.";
            } elseif(strlen($password) < 6) {
                $retArr["error"] = "Password must be at least 6 characters long.";
            } else {
                $retArr["error"] = "Invalid input data.";
            }
        }
        
        return $retArr;
    }
    
    /**
     * Generate a random 6-character alphanumeric PIN
     * 
     * @return string 6-character PIN
     */
    private static function generatePin(): string {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $pin = '';
        for ($i = 0; $i < 6; $i++) {
            $pin .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $pin;
    }
    

}
