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
     * Verify reCAPTCHA v3 token
     *
     * Validates a reCAPTCHA v3 token with Google's API and checks action and score
     *
     * @param string $token The reCAPTCHA response token from the client
     * @param string $expectedAction The expected action name (e.g., 'login', 'register', 'lostpw')
     * @param float $minScore Minimum score threshold (0.0 to 1.0, default 0.5)
     * @return array Result array with 'success', 'score', and optional 'reason' or 'error'
     */
    public static function verifyRecaptcha(string $token, string $expectedAction = '', float $minScore = 0.5): array
    {
        // Skip verification in debug mode
        if (!empty($GLOBALS['debug'])) {
            return ['success' => true, 'score' => 1.0];
        }

        $secret = ApiKeys::getRecaptchaSecretKey();
        if (empty($secret)) {
            return ['success' => false, 'error' => 'recaptcha-not-configured'];
        }

        if (empty($token)) {
            return ['success' => false, 'error' => 'missing-token'];
        }

        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = http_build_query(['secret' => $secret, 'response' => $token]);

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $data
            ]
        ];

        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return ['success' => false, 'error' => 'no-response'];
        }

        $res = json_decode($result, true);
        if (!isset($res['success']) || !$res['success']) {
            return $res + ['success' => false];
        }

        // Check action matches expected value
        if ($expectedAction && (!isset($res['action']) || $res['action'] !== $expectedAction)) {
            return ['success' => false, 'reason' => 'wrong-action', 'score' => $res['score'] ?? null];
        }

        // Check score meets minimum threshold
        if (($res['score'] ?? 0) < $minScore) {
            return ['success' => false, 'reason' => 'low-score', 'score' => $res['score']];
        }

        return ['success' => true, 'score' => $res['score']];
    }

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
            // Validate reCAPTCHA v3 for regular web registrations
            $recaptchaToken = isset($_REQUEST['g-recaptcha-response']) ? $_REQUEST['g-recaptcha-response'] : '';
            $captchaResult = self::verifyRecaptcha($recaptchaToken, 'register', 0.5);

            if (!$captchaResult['success']) {
                $retArr['error'] = 'Security verification failed. Please try again.';
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

            // Hash password using bcrypt (secure modern hashing)
            $passwordHash = PasswordHelper::hash($password);

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
                         VALUES ('".date('YmdHis')."', 'MAIL', '".$email."', '".db::EscString($passwordHash)."', '".db::EscString($email)."', 'PIN:".$pin."', '".db::EscString(json_encode($userDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."')";

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
     * Validates reCAPTCHA v3, generates a new password if user exists,
     * writes it (bcrypt) to BUSER and sends an email using EmailService.
     * Always returns a generic success message (no user enumeration).
     */
    public static function lostPassword(): array
    {
        // Validate reCAPTCHA v3
        $recaptchaToken = isset($_REQUEST['g-recaptcha-response']) ? $_REQUEST['g-recaptcha-response'] : '';
        $captchaResult = self::verifyRecaptcha($recaptchaToken, 'lostpw', 0.5);

        if (!$captchaResult['success']) {
            return ['success' => false, 'error' => 'Security verification failed'];
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
            $newPasswordHash = PasswordHelper::hash($newPassword);

            // Update DB with bcrypt hash
            $upd = "UPDATE BUSER SET BPW='".db::EscString($newPasswordHash)."' WHERE BID=".(int)$uArr['BID'];
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

    /**
     * Complete user deletion with all associated data
     *
     * Deletes user and all related data from all tables
     *
     * @param int $userId User ID to delete
     * @return array Result with success status and detailed log
     */
    public static function deleteUserCompletely(int $userId): array
    {
        $retArr = ['success' => false, 'error' => '', 'log' => []];

        if ($userId <= 0) {
            $retArr['error'] = 'Invalid user ID';
            return $retArr;
        }

        try {
            // Step 1: Delete API keys
            $apiKeyResult = self::deleteUserApiKeys($userId);
            $retArr['log'][] = 'API Keys: ' . ($apiKeyResult['success'] ? 'Deleted ' . $apiKeyResult['count'] . ' key(s)' : 'Failed - ' . $apiKeyResult['error']);

            // Step 2: Delete user files directory
            $filesResult = self::deleteUserFiles($userId);
            $retArr['log'][] = 'Files: ' . ($filesResult['success'] ? $filesResult['message'] : 'Failed - ' . $filesResult['error']);

            // Step 3: Delete BCONFIG entries
            $configResult = self::deleteUserConfig($userId);
            $retArr['log'][] = 'Config: ' . ($configResult['success'] ? 'Deleted ' . $configResult['count'] . ' record(s)' : 'Failed - ' . $configResult['error']);

            // Step 4: Delete BPROMPTS and BPROMPTMETA entries
            $promptsResult = self::deleteUserPrompts($userId);
            $retArr['log'][] = 'Prompts: ' . ($promptsResult['success'] ? 'Deleted ' . $promptsResult['count'] . ' prompt(s) and ' . $promptsResult['metaCount'] . ' meta record(s)' : 'Failed - ' . $promptsResult['error']);

            // Step 5: Delete BRAG entries
            $ragResult = self::deleteUserRAG($userId);
            $retArr['log'][] = 'RAG: ' . ($ragResult['success'] ? 'Deleted ' . $ragResult['count'] . ' record(s)' : 'Failed - ' . $ragResult['error']);

            // Step 6: Delete BMESSAGES and BMESSAGEMETA entries
            $messagesResult = self::deleteUserMessages($userId);
            $retArr['log'][] = 'Messages: ' . ($messagesResult['success'] ? 'Deleted ' . $messagesResult['count'] . ' message(s) and ' . $messagesResult['metaCount'] . ' meta record(s)' : 'Failed - ' . $messagesResult['error']);

            // Step 7: Delete user from BUSER
            $userResult = self::deleteUserRecord($userId);
            $retArr['log'][] = 'User Record: ' . ($userResult['success'] ? 'Deleted' : 'Failed - ' . $userResult['error']);

            // Success if user record was deleted
            $retArr['success'] = $userResult['success'];

        } catch (\Throwable $e) {
            $retArr['error'] = 'Exception during deletion: ' . $e->getMessage();
            $retArr['log'][] = 'ERROR: ' . $e->getMessage();
        }

        return $retArr;
    }

    /**
     * Delete all API keys for a user
     *
     * @param int $userId User ID
     * @return array Result with count
     */
    private static function deleteUserApiKeys(int $userId): array
    {
        $retArr = ['success' => false, 'error' => '', 'count' => 0];

        try {
            // Count API keys
            $countSQL = 'SELECT COUNT(*) as cnt FROM BAPIKEYS WHERE BOWNERID = ' . $userId;
            $countRes = db::Query($countSQL);
            $countArr = db::FetchArr($countRes);
            $retArr['count'] = $countArr['cnt'] ?? 0;

            // Delete API keys
            $deleteSQL = 'DELETE FROM BAPIKEYS WHERE BOWNERID = ' . $userId;
            db::Query($deleteSQL);

            $retArr['success'] = true;
        } catch (\Throwable $e) {
            $retArr['error'] = $e->getMessage();
        }

        return $retArr;
    }

    /**
     * Delete user files directory
     *
     * @param int $userId User ID
     * @return array Result with message
     */
    private static function deleteUserFiles(int $userId): array
    {
        $retArr = ['success' => false, 'error' => '', 'message' => ''];

        try {
            // Use standard directory pattern - need to find all matching directories
            // Pattern: XXX/YY/YYYYMM/ where XXX = last 3 chars, YY = last 2 chars of userId
            $userDirPattern = substr($userId, -5, 3) . '/' . substr($userId, -2, 2) . '/';
            $baseDir = rtrim(UPLOAD_DIR ?? '/tmp', '/');
            $userDirBase = $baseDir . '/' . $userDirPattern;

            if (!file_exists($userDirBase)) {
                $retArr['success'] = true;
                $retArr['message'] = 'No files directory found';
                return $retArr;
            }

            // Recursively delete all month directories for this user
            $deleted = self::recursiveDelete($userDirBase);

            if ($deleted) {
                $retArr['success'] = true;
                $retArr['message'] = 'Files directory deleted';
            } else {
                $retArr['error'] = 'Failed to delete files directory';
            }
        } catch (\Throwable $e) {
            $retArr['error'] = $e->getMessage();
        }

        return $retArr;
    }

    /**
     * Recursively delete directory
     *
     * @param string $dir Directory path
     * @return bool Success status
     */
    private static function recursiveDelete(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!self::recursiveDelete($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    /**
     * Delete user configuration entries
     *
     * @param int $userId User ID
     * @return array Result with count
     */
    private static function deleteUserConfig(int $userId): array
    {
        $retArr = ['success' => false, 'error' => '', 'count' => 0];

        try {
            // Count config entries
            $countSQL = 'SELECT COUNT(*) as cnt FROM BCONFIG WHERE BOWNERID = ' . $userId;
            $countRes = db::Query($countSQL);
            $countArr = db::FetchArr($countRes);
            $retArr['count'] = $countArr['cnt'] ?? 0;

            // Delete config entries
            $deleteSQL = 'DELETE FROM BCONFIG WHERE BOWNERID = ' . $userId;
            db::Query($deleteSQL);

            $retArr['success'] = true;
        } catch (\Throwable $e) {
            $retArr['error'] = $e->getMessage();
        }

        return $retArr;
    }

    /**
     * Delete user prompts and prompt meta entries
     *
     * @param int $userId User ID
     * @return array Result with counts
     */
    private static function deleteUserPrompts(int $userId): array
    {
        $retArr = ['success' => false, 'error' => '', 'count' => 0, 'metaCount' => 0];

        try {
            // Get all prompt IDs for this user
            $promptSQL = 'SELECT BID FROM BPROMPTS WHERE BOWNERID = ' . $userId;
            $promptRes = db::Query($promptSQL);
            $promptIds = [];

            while ($row = db::FetchArr($promptRes)) {
                $promptIds[] = $row['BID'];
            }

            $retArr['count'] = count($promptIds);

            // Delete prompt meta entries for these prompts
            if (count($promptIds) > 0) {
                $promptIdList = implode(',', $promptIds);

                // Count meta entries
                $countMetaSQL = 'SELECT COUNT(*) as cnt FROM BPROMPTMETA WHERE BPROMPTID IN (' . $promptIdList . ')';
                $countMetaRes = db::Query($countMetaSQL);
                $countMetaArr = db::FetchArr($countMetaRes);
                $retArr['metaCount'] = $countMetaArr['cnt'] ?? 0;

                // Delete meta entries
                $deleteMetaSQL = 'DELETE FROM BPROMPTMETA WHERE BPROMPTID IN (' . $promptIdList . ')';
                db::Query($deleteMetaSQL);
            }

            // Delete prompts
            $deletePromptsSQL = 'DELETE FROM BPROMPTS WHERE BOWNERID = ' . $userId;
            db::Query($deletePromptsSQL);

            $retArr['success'] = true;
        } catch (\Throwable $e) {
            $retArr['error'] = $e->getMessage();
        }

        return $retArr;
    }

    /**
     * Delete user RAG entries
     *
     * @param int $userId User ID
     * @return array Result with count
     */
    private static function deleteUserRAG(int $userId): array
    {
        $retArr = ['success' => false, 'error' => '', 'count' => 0];

        try {
            // Count RAG entries
            $countSQL = 'SELECT COUNT(*) as cnt FROM BRAG WHERE BUID = ' . $userId;
            $countRes = db::Query($countSQL);
            $countArr = db::FetchArr($countRes);
            $retArr['count'] = $countArr['cnt'] ?? 0;

            // Delete RAG entries
            $deleteSQL = 'DELETE FROM BRAG WHERE BUID = ' . $userId;
            db::Query($deleteSQL);

            $retArr['success'] = true;
        } catch (\Throwable $e) {
            $retArr['error'] = $e->getMessage();
        }

        return $retArr;
    }

    /**
     * Delete user messages and message meta entries
     *
     * @param int $userId User ID
     * @return array Result with counts
     */
    private static function deleteUserMessages(int $userId): array
    {
        $retArr = ['success' => false, 'error' => '', 'count' => 0, 'metaCount' => 0];

        try {
            // Get all message IDs for this user
            $messageSQL = 'SELECT BID FROM BMESSAGES WHERE BUSERID = ' . $userId;
            $messageRes = db::Query($messageSQL);
            $messageIds = [];

            while ($row = db::FetchArr($messageRes)) {
                $messageIds[] = $row['BID'];
            }

            $retArr['count'] = count($messageIds);

            // Delete message meta entries for these messages
            if (count($messageIds) > 0) {
                $messageIdList = implode(',', $messageIds);

                // Count meta entries
                $countMetaSQL = 'SELECT COUNT(*) as cnt FROM BMESSAGEMETA WHERE BMESSID IN (' . $messageIdList . ')';
                $countMetaRes = db::Query($countMetaSQL);
                $countMetaArr = db::FetchArr($countMetaRes);
                $retArr['metaCount'] = $countMetaArr['cnt'] ?? 0;

                // Delete meta entries
                $deleteMetaSQL = 'DELETE FROM BMESSAGEMETA WHERE BMESSID IN (' . $messageIdList . ')';
                db::Query($deleteMetaSQL);
            }

            // Delete messages
            $deleteMessagesSQL = 'DELETE FROM BMESSAGES WHERE BUSERID = ' . $userId;
            db::Query($deleteMessagesSQL);

            $retArr['success'] = true;
        } catch (\Throwable $e) {
            $retArr['error'] = $e->getMessage();
        }

        return $retArr;
    }

    /**
     * Delete user record from BUSER
     *
     * @param int $userId User ID
     * @return array Result
     */
    private static function deleteUserRecord(int $userId): array
    {
        $retArr = ['success' => false, 'error' => ''];

        try {
            // Delete user
            $deleteSQL = 'DELETE FROM BUSER WHERE BID = ' . $userId;
            db::Query($deleteSQL);

            $retArr['success'] = true;
        } catch (\Throwable $e) {
            $retArr['error'] = $e->getMessage();
        }

        return $retArr;
    }
}
