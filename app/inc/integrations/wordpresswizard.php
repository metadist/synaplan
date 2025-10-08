<?php

/**
 * WordPress Wizard Integration Handler
 *
 * Handles the complete setup process for WordPress plugin installations including:
 * - WordPress site verification via callback
 * - User creation with status 'NEW'
 * - API key generation
 * - RAG file uploads and vectorization
 * - Widget configuration
 * - Prompt configuration with file search enabled
 *
 * @package Integrations
 */

class WordPressWizard
{
    /**
     * Complete WordPress wizard setup - NEW VERSION with verification
     *
     * This is the main entry point for WordPress wizard installations
     * Handles complete flow: verification → user → API key → files → prompt → widget
     *
     * @return array Result of the setup operation
     */
    public static function completeWizardSetup(): array
    {
        $retArr = ['error' => '', 'success' => false];

        try {
            // STEP 1: Verify WordPress site
            $verification = self::verifyWordPressSite();
            if (!$verification['success']) {
                $retArr['error'] = $verification['error'] ?? 'WordPress site verification failed';
                return $retArr;
            }

            // STEP 2: Create user with status 'NEW' (not PIN)
            $userResult = self::createWordPressUser();
            if (!$userResult['success']) {
                $retArr['error'] = $userResult['error'] ?? 'User creation failed';
                return $retArr;
            }

            $userId = $userResult['user_id'];

            // STEP 3: Create API key
            $apiKeyResult = self::createUserApiKey($userId);
            if (!$apiKeyResult['success']) {
                $retArr['error'] = $apiKeyResult['error'] ?? 'API key creation failed';
                return $retArr;
            }

            $apiKey = $apiKeyResult['api_key'];

            // STEP 4: Process uploaded files for RAG (if any)
            $uploadedFilesCount = 0;
            if (!empty($_FILES['files']) && !empty($_FILES['files']['name'][0])) {
                $ragResult = self::processWizardRAGFiles($userId);

                if ($ragResult['success']) {
                    $uploadedFilesCount = $ragResult['processedCount'] ?? 0;

                    // STEP 5: Enable file search on general prompt (if files were uploaded)
                    if ($uploadedFilesCount > 0) {
                        $promptResult = self::enableFileSearchOnGeneralPrompt($userId);
                        if (!$promptResult['success']) {
                            error_log('WordPress Wizard: Failed to enable file search on prompt: ' . ($promptResult['error'] ?? 'Unknown error'));
                        }
                    }
                } else {
                    error_log('WordPress Wizard: File processing failed: ' . ($ragResult['error'] ?? 'Unknown error'));
                }
            }

            // STEP 6: Save widget configuration to BCONFIG
            $widgetResult = self::saveWidgetConfiguration($userId);
            if (!$widgetResult['success']) {
                error_log('WordPress Wizard: Widget configuration failed: ' . ($widgetResult['error'] ?? 'Unknown error'));
            }

            // Success!
            $retArr['success'] = true;
            $retArr['message'] = 'WordPress wizard setup completed successfully';
            $retArr['data'] = [
                'user_id' => $userId,
                'email' => db::EscString($_REQUEST['email'] ?? ''),
                'api_key' => $apiKey,
                'filesProcessed' => $uploadedFilesCount,
                'widget_configured' => $widgetResult['success'] ?? false,
                'site_verified' => true
            ];

            return $retArr;

        } catch (\Throwable $e) {
            error_log('WordPress Wizard Error: ' . $e->getMessage());
            $retArr['error'] = 'An error occurred during setup: ' . $e->getMessage();
            return $retArr;
        }
    }

    /**
     * Verify WordPress site by calling back to the verification endpoint
     *
     * @return array Verification result
     */
    private static function verifyWordPressSite(): array
    {
        $retArr = ['success' => false, 'error' => ''];

        // Get verification data from request
        $verificationToken = isset($_REQUEST['verification_token']) ? db::EscString($_REQUEST['verification_token']) : '';
        $verificationUrl = isset($_REQUEST['verification_url']) ? db::EscString($_REQUEST['verification_url']) : '';
        $siteUrl = isset($_REQUEST['site_url']) ? db::EscString($_REQUEST['site_url']) : '';

        if (empty($verificationToken) || empty($verificationUrl)) {
            $retArr['error'] = 'Missing verification token or URL';
            return $retArr;
        }

        // Call back to WordPress site to verify the token
        $postData = ['token' => $verificationToken];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $verificationUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $retArr['error'] = 'Verification request failed: ' . $curlError;
            return $retArr;
        }

        if ($httpCode !== 200) {
            $retArr['error'] = 'WordPress site verification failed (HTTP ' . $httpCode . ')';
            return $retArr;
        }

        $decoded = json_decode($response, true);
        if (!$decoded || !isset($decoded['verified']) || $decoded['verified'] !== true) {
            $retArr['error'] = 'Invalid verification response from WordPress site';
            return $retArr;
        }

        $retArr['success'] = true;
        $retArr['site_info'] = $decoded['site_info'] ?? [];

        return $retArr;
    }

    /**
     * Create WordPress user with status 'NEW' (auto-activated)
     *
     * @return array User creation result with user ID
     */
    private static function createWordPressUser(): array
    {
        $retArr = ['success' => false, 'error' => '', 'user_id' => 0];

        // Get and sanitize email and password
        $email = isset($_REQUEST['email']) ? db::EscString($_REQUEST['email']) : '';
        $password = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';
        $language = isset($_REQUEST['language']) ? db::EscString($_REQUEST['language']) : 'en';

        // Validate input
        if (empty($email) || empty($password)) {
            $retArr['error'] = 'Email and password are required';
            return $retArr;
        }

        if (strlen($password) < 6) {
            $retArr['error'] = 'Password must be at least 6 characters';
            return $retArr;
        }

        // Check if email already exists
        $checkSQL = "SELECT BID FROM BUSER WHERE BMAIL = '" . $email . "'";
        $checkRes = db::Query($checkSQL);
        $existingUser = db::FetchArr($checkRes);

        if ($existingUser) {
            $retArr['error'] = 'An account with this email address already exists';
            return $retArr;
        }

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
            'language' => $language,
            'timezone' => '',
            'invoiceEmail' => '',
            'emailConfirmed' => true,
            'wordpressVerified' => true
        ];

        // Insert new user with BUSERLEVEL = 'NEW' (auto-activated, no email confirmation needed)
        $insertSQL = "INSERT INTO BUSER (BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID, BUSERLEVEL, BUSERDETAILS) 
                     VALUES ('" . date('YmdHis') . "', 'MAIL', '" . $email . "', '" . $passwordMd5 . "', '" . $email . "', 'NEW', '" . db::EscString(json_encode($userDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "')";

        db::Query($insertSQL);
        $newUserId = db::LastId();

        if ($newUserId > 0) {
            $retArr['success'] = true;
            $retArr['user_id'] = $newUserId;
            $retArr['email'] = $email;
        } else {
            $retArr['error'] = 'Failed to create user account';
        }

        return $retArr;
    }

    /**
     * Create API key for the WordPress user
     *
     * @param int $userId User ID
     * @return array API key creation result
     */
    private static function createUserApiKey(int $userId): array
    {
        $retArr = ['success' => false, 'error' => '', 'api_key' => ''];

        // Generate API key (same format as ApiKeyManager)
        $random = bin2hex(random_bytes(24));
        $apiKey = 'sk_live_' . $random;
        $now = time();

        // Insert API key into database
        $insertSQL = 'INSERT INTO BAPIKEYS (BOWNERID, BNAME, BKEY, BSTATUS, BCREATED, BLASTUSED) 
                     VALUES (' . $userId . ", 'WordPress Plugin', '" . db::EscString($apiKey) . "', 'active', " . $now . ', 0)';

        db::Query($insertSQL);
        $apiKeyId = db::LastId();

        if ($apiKeyId > 0) {
            $retArr['success'] = true;
            $retArr['api_key'] = $apiKey;
        } else {
            $retArr['error'] = 'Failed to create API key';
        }

        return $retArr;
    }

    /**
     * Save widget configuration to BCONFIG table
     *
     * @param int $userId User ID
     * @return array Save result
     */
    private static function saveWidgetConfiguration(int $userId): array
    {
        $retArr = ['success' => false, 'error' => ''];

        try {
            // Get widget configuration from request with defaults
            $widgetId = intval($_REQUEST['widgetId'] ?? 1);
            $group = 'widget_' . $widgetId;

            $widgetSettings = [
                'color' => db::EscString($_REQUEST['widgetColor'] ?? '#007bff'),
                'iconColor' => db::EscString($_REQUEST['widgetIconColor'] ?? '#ffffff'),
                'position' => db::EscString($_REQUEST['widgetPosition'] ?? 'bottom-right'),
                'autoMessage' => db::EscString($_REQUEST['autoMessage'] ?? 'Hello! How can I help you today?'),
                'prompt' => db::EscString($_REQUEST['widgetPrompt'] ?? 'general'),
                'autoOpen' => isset($_REQUEST['autoOpen']) && ($_REQUEST['autoOpen'] === '1' || $_REQUEST['autoOpen'] === 'true') ? '1' : '0',
                'integrationType' => db::EscString($_REQUEST['integrationType'] ?? 'floating-button'),
                'inlinePlaceholder' => db::EscString($_REQUEST['inlinePlaceholder'] ?? 'Ask me anything...'),
                'inlineButtonText' => db::EscString($_REQUEST['inlineButtonText'] ?? 'Ask'),
                'inlineFontSize' => intval($_REQUEST['inlineFontSize'] ?? 18),
                'inlineTextColor' => db::EscString($_REQUEST['inlineTextColor'] ?? '#212529'),
                'inlineBorderRadius' => intval($_REQUEST['inlineBorderRadius'] ?? 8)
            ];

            // Delete existing widget configuration for this user and widget ID
            $deleteSQL = 'DELETE FROM BCONFIG WHERE BOWNERID = ' . $userId . " AND BGROUP = '" . db::EscString($group) . "'";
            db::Query($deleteSQL);

            // Insert new widget configuration
            foreach ($widgetSettings as $setting => $value) {
                $insertSQL = 'INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) 
                             VALUES (' . $userId . ", '" . db::EscString($group) . "', '" . db::EscString($setting) . "', '" . db::EscString((string)$value) . "')";
                db::Query($insertSQL);
            }

            $retArr['success'] = true;
            $retArr['message'] = 'Widget configuration saved successfully';

        } catch (\Throwable $e) {
            error_log('Widget configuration error: ' . $e->getMessage());
            $retArr['error'] = 'Failed to save widget configuration: ' . $e->getMessage();
        }

        return $retArr;
    }

    /**
     * Process RAG files uploaded via WordPress wizard
     *
     * @param int $userId User ID
     * @return array Processing result
     */
    private static function processWizardRAGFiles(int $userId): array
    {
        $retArr = ['error' => '', 'success' => false, 'processedCount' => 0];

        // Use the standard group key for WordPress wizard uploads
        $groupKey = 'WORDPRESS_WIZARD';

        // Validate file uploads exist
        if (empty($_FILES['files']) || empty($_FILES['files']['name'][0])) {
            $retArr['error'] = 'No files uploaded';
            return $retArr;
        }

        // Rate limiting: max 5 files per wizard session
        $file_count = count($_FILES['files']['name']);
        if ($file_count > 5) {
            $retArr['error'] = 'Maximum 5 files allowed per wizard session';
            return $retArr;
        }

        $filesArr = [];

        // Process each uploaded file
        for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
            $tmpName = $_FILES['files']['tmp_name'][$i];
            $originalName = $_FILES['files']['name'][$i];
            $fileSize = $_FILES['files']['size'][$i];
            $fileError = $_FILES['files']['error'][$i];

            // Skip if no file or error
            if ($fileError !== UPLOAD_ERR_OK || empty($tmpName)) {
                continue;
            }

            // Validate file size (max 10MB)
            $maxSize = 10 * 1024 * 1024;
            if ($fileSize > $maxSize) {
                $retArr['error'] .= "File too large: $originalName (max 10MB)\n";
                continue;
            }

            // Get file extension
            $pathInfo = pathinfo($originalName);
            $fileExtension = strtolower($pathInfo['extension'] ?? '');

            // Validate file type
            $allowedExtensions = ['pdf', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'mp3', 'mp4'];
            if (!in_array($fileExtension, $allowedExtensions)) {
                $retArr['error'] .= "Invalid file type: $fileExtension\n";
                continue;
            }

            // Generate unique filename
            $newFileName = time() . '_' . $userId . '_' . uniqid() . '.' . $fileExtension;

            // Create user-specific directory
            $userSubDir = 'uid_' . $userId;
            $userDir = rtrim(UPLOAD_DIR, '/') . '/' . $userSubDir;

            if (!file_exists($userDir)) {
                mkdir($userDir, 0755, true);
            }

            $targetPath = $userDir . '/' . $newFileName;
            $userRelPath = $userSubDir . '/';

            // Move uploaded file
            if (move_uploaded_file($tmpName, $targetPath)) {
                // Create message entry for this file
                $inMessageArr = [];
                $inMessageArr['BUSERID'] = $userId;
                $inMessageArr['BTEXT'] = 'RAG file: ' . $originalName;
                $inMessageArr['BUNIXTIMES'] = time();
                $inMessageArr['BDATETIME'] = date('YmdHis');
                $inMessageArr['BTRACKID'] = (int) (microtime(true) * 1000000);
                $inMessageArr['BLANG'] = 'en';
                $inMessageArr['BTOPIC'] = 'RAG';
                $inMessageArr['BID'] = 'DEFAULT';
                $inMessageArr['BPROVIDX'] = session_id();
                $inMessageArr['BMESSTYPE'] = 'RAG';
                $inMessageArr['BFILE'] = 1;
                $inMessageArr['BFILEPATH'] = $userRelPath . $newFileName;
                $inMessageArr['BFILETYPE'] = $fileExtension;
                $inMessageArr['BDIRECT'] = 'IN';
                $inMessageArr['BSTATUS'] = 'NEW';
                $inMessageArr['BFILETEXT'] = '';

                // Save to database
                $resArr = Central::handleInMessage($inMessageArr);

                if ($resArr['lastId'] > 0) {
                    $filesArr[] = [
                        'BID' => $resArr['lastId'],
                        'BFILEPATH' => $userRelPath . $newFileName,
                        'BFILETYPE' => $fileExtension,
                        'BTEXT' => 'RAG file: ' . $originalName
                    ];
                }
            } else {
                $retArr['error'] .= "Failed to move file: $originalName\n";
            }
        }

        if (count($filesArr) == 0) {
            $retArr['error'] = 'No files were successfully uploaded';
            return $retArr;
        }

        // Process files through RAG system
        $ragResult = Central::processRAGFiles($filesArr, $userId, $groupKey, false);

        $retArr['success'] = $ragResult['success'];
        $retArr['processedFiles'] = $ragResult['results'];
        $retArr['processedCount'] = $ragResult['processedCount'];
        $retArr['totalFiles'] = $ragResult['totalFiles'];
        $retArr['groupKey'] = $groupKey;

        return $retArr;
    }

    /**
     * Enable file search tool on general prompt with WORDPRESS_WIZARD group filter
     *
     * Creates a user-specific copy of the "general" prompt with file search enabled
     * This mimics what happens when a user enables file search in the UI
     *
     * @param int $userId User ID
     * @return array Result of the operation
     */
    private static function enableFileSearchOnGeneralPrompt(int $userId): array
    {
        $retArr = ['error' => '', 'success' => false];

        try {
            $promptKey = 'general';
            $groupKey = 'WORDPRESS_WIZARD';

            // Get the default general prompt (BOWNERID = 0)
            $sql = "SELECT BID, BPROMPT, BSHORTDESC, BLANG FROM BPROMPTS 
                    WHERE BTOPIC = '" . db::EscString($promptKey) . "' AND BOWNERID = 0 LIMIT 1";
            $res = db::Query($sql);
            $defaultPrompt = db::FetchArr($res);

            if (!$defaultPrompt) {
                $retArr['error'] = 'Default general prompt not found';
                return $retArr;
            }

            // Check if user already has a custom prompt
            $checkSql = "SELECT BID FROM BPROMPTS 
                         WHERE BTOPIC = '" . db::EscString($promptKey) . "' AND BOWNERID = " . $userId . ' LIMIT 1';
            $checkRes = db::Query($checkSql);
            $existingPrompt = db::FetchArr($checkRes);

            if ($existingPrompt) {
                // User already has a custom prompt, delete it and its settings
                $existingPromptId = $existingPrompt['BID'];
                db::Query('DELETE FROM BPROMPTMETA WHERE BPROMPTID = ' . $existingPromptId);
                db::Query('DELETE FROM BPROMPTS WHERE BID = ' . $existingPromptId);
            }

            // Create user-specific copy of the prompt
            $insertPromptSql = 'INSERT INTO BPROMPTS (BOWNERID, BLANG, BTOPIC, BPROMPT, BSHORTDESC) 
                                VALUES (' . $userId . ", 
                                        '" . db::EscString($defaultPrompt['BLANG']) . "', 
                                        '" . db::EscString($promptKey) . "', 
                                        '" . db::EscString($defaultPrompt['BPROMPT']) . "', 
                                        '" . db::EscString($defaultPrompt['BSHORTDESC']) . "')";
            db::Query($insertPromptSql);
            $newPromptId = db::LastId();

            if ($newPromptId <= 0) {
                $retArr['error'] = 'Failed to create user-specific prompt';
                return $retArr;
            }

            // Insert settings into BPROMPTMETA
            $settings = [
                'aiModel' => '-1',          // Automatic model selection
                'tool_internet' => '0',     // Internet search off
                'tool_files' => '1',        // File search ON
                'tool_screenshot' => '0',   // Screenshot off
                'tool_transfer' => '0',     // Transfer off
                'tool_files_keyword' => $groupKey  // Filter by WORDPRESS_WIZARD group
            ];

            foreach ($settings as $token => $value) {
                $insertSettingSql = 'INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) 
                                     VALUES (' . $newPromptId . ", 
                                             '" . db::EscString($token) . "', 
                                             '" . db::EscString($value) . "')";
                db::Query($insertSettingSql);
            }

            $retArr['success'] = true;
            $retArr['message'] = 'File search enabled on general prompt with WORDPRESS_WIZARD filter';
            $retArr['promptId'] = $newPromptId;

            return $retArr;

        } catch (\Throwable $e) {
            error_log('Enable File Search Error: ' . $e->getMessage());
            $retArr['error'] = 'Failed to enable file search: ' . $e->getMessage();
            return $retArr;
        }
    }
}
