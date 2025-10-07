<?php

/**
 * WordPress Wizard Integration Handler
 *
 * Handles the complete setup process for WordPress plugin installations including:
 * - RAG file uploads and vectorization
 * - Widget configuration
 * - Prompt configuration with file search enabled
 *
 * @package Integrations
 */

class WordPressWizard
{
    /**
     * Complete WordPress wizard setup
     *
     * This method handles all three missing pieces of the wizard:
     * 1. Upload files to RAG system
     * 2. Enable file search on general prompt
     * 3. Save widget configuration
     *
     * @return array Result of the setup operation
     */
    public static function completeWizardSetup(): array
    {
        $retArr = ['error' => '', 'success' => false];

        // Verify user is authenticated
        if (!isset($_SESSION['USERPROFILE']) || !isset($_SESSION['USERPROFILE']['BID'])) {
            $retArr['error'] = 'User not authenticated';
            return $retArr;
        }

        $userId = intval($_SESSION['USERPROFILE']['BID']);

        try {
            // Step 1: Process uploaded files for RAG (if any)
            $filesProcessed = false;
            $uploadedFilesCount = 0;

            if (!empty($_FILES['files']) && !empty($_FILES['files']['name'][0])) {
                $ragResult = self::processWizardRAGFiles($userId);

                if (!$ragResult['success']) {
                    $retArr['error'] = $ragResult['error'] ?? 'Failed to process uploaded files';
                    return $retArr;
                }

                $filesProcessed = true;
                $uploadedFilesCount = $ragResult['processedCount'] ?? 0;
            }

            // Step 2: Enable file search on general prompt (only if files were uploaded)
            if ($filesProcessed && $uploadedFilesCount > 0) {
                $promptResult = self::enableFileSearchOnGeneralPrompt($userId);

                if (!$promptResult['success']) {
                    // Don't fail the entire process, just log the issue
                    error_log('WordPress Wizard: Failed to enable file search on prompt: ' . ($promptResult['error'] ?? 'Unknown error'));
                }
            }

            // Step 3: Save widget configuration
            $widgetConfig = [
                'widgetId' => intval($_REQUEST['widgetId'] ?? 1),
                'widgetColor' => db::EscString($_REQUEST['widgetColor'] ?? '#007bff'),
                'widgetIconColor' => db::EscString($_REQUEST['widgetIconColor'] ?? '#ffffff'),
                'widgetPosition' => db::EscString($_REQUEST['widgetPosition'] ?? 'bottom-right'),
                'autoMessage' => db::EscString($_REQUEST['autoMessage'] ?? 'Hello! How can I help you today?'),
                'widgetPrompt' => db::EscString($_REQUEST['widgetPrompt'] ?? 'general'),
                'autoOpen' => isset($_REQUEST['autoOpen']) && ($_REQUEST['autoOpen'] === '1' || $_REQUEST['autoOpen'] === 'true') ? '1' : '0',
                'integrationType' => db::EscString($_REQUEST['integrationType'] ?? 'floating-button'),
                'inlinePlaceholder' => db::EscString($_REQUEST['inlinePlaceholder'] ?? 'Ask me anything...'),
                'inlineButtonText' => db::EscString($_REQUEST['inlineButtonText'] ?? 'Ask'),
                'inlineFontSize' => intval($_REQUEST['inlineFontSize'] ?? 18),
                'inlineTextColor' => db::EscString($_REQUEST['inlineTextColor'] ?? '#212529'),
                'inlineBorderRadius' => intval($_REQUEST['inlineBorderRadius'] ?? 8)
            ];

            // Temporarily set request variables for Frontend::saveWidget()
            $_REQUEST['widgetId'] = $widgetConfig['widgetId'];
            $_REQUEST['widgetColor'] = $widgetConfig['widgetColor'];
            $_REQUEST['widgetIconColor'] = $widgetConfig['widgetIconColor'];
            $_REQUEST['widgetPosition'] = $widgetConfig['widgetPosition'];
            $_REQUEST['autoMessage'] = $widgetConfig['autoMessage'];
            $_REQUEST['widgetPrompt'] = $widgetConfig['widgetPrompt'];
            $_REQUEST['autoOpen'] = $widgetConfig['autoOpen'];
            $_REQUEST['integrationType'] = $widgetConfig['integrationType'];
            $_REQUEST['inlinePlaceholder'] = $widgetConfig['inlinePlaceholder'];
            $_REQUEST['inlineButtonText'] = $widgetConfig['inlineButtonText'];
            $_REQUEST['inlineFontSize'] = $widgetConfig['inlineFontSize'];
            $_REQUEST['inlineTextColor'] = $widgetConfig['inlineTextColor'];
            $_REQUEST['inlineBorderRadius'] = $widgetConfig['inlineBorderRadius'];

            $widgetResult = Frontend::saveWidget();

            if (!$widgetResult['success']) {
                $retArr['error'] = $widgetResult['error'] ?? 'Failed to save widget configuration';
                return $retArr;
            }

            // Success!
            $retArr['success'] = true;
            $retArr['message'] = 'WordPress wizard setup completed successfully';
            $retArr['filesProcessed'] = $uploadedFilesCount;
            $retArr['widget'] = [
                'widgetId' => $widgetConfig['widgetId'],
                'saved' => true
            ];

            return $retArr;

        } catch (\Throwable $e) {
            error_log('WordPress Wizard Error: ' . $e->getMessage());
            $retArr['error'] = 'An error occurred during setup: ' . $e->getMessage();
            return $retArr;
        }
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
     * @param int $userId User ID
     * @return array Result of the operation
     */
    private static function enableFileSearchOnGeneralPrompt(int $userId): array
    {
        $retArr = ['error' => '', 'success' => false];

        try {
            $promptKey = 'general';
            $groupKey = 'WORDPRESS_WIZARD';

            // Get the current general prompt details
            $promptDetails = BasicAI::getPromptDetails($promptKey);

            if (!isset($promptDetails['BID']) || !isset($promptDetails['BPROMPT'])) {
                $retArr['error'] = 'General prompt not found';
                return $retArr;
            }

            // Get current settings
            $currentSettings = [];
            if (isset($promptDetails['SETTINGS']) && is_array($promptDetails['SETTINGS'])) {
                foreach ($promptDetails['SETTINGS'] as $setting) {
                    if (is_array($setting) && isset($setting['BTOKEN'])) {
                        $currentSettings[$setting['BTOKEN']] = $setting['BVALUE'] ?? '';
                    }
                }
            }

            // Prepare the update request
            $_REQUEST['promptKey'] = $promptKey;
            $_REQUEST['promptContent'] = $promptDetails['BPROMPT'];
            $_REQUEST['promptDescription'] = $promptDetails['BSHORTDESC'] ?? '';
            $_REQUEST['saveFlag'] = 'save';
            $_REQUEST['aiModel'] = $currentSettings['aiModel'] ?? '-1';

            // Enable file search tool
            $_REQUEST['tool_internet'] = $currentSettings['tool_internet'] ?? '0';
            $_REQUEST['tool_files'] = '1'; // Enable file search
            $_REQUEST['tool_screenshot'] = $currentSettings['tool_screenshot'] ?? '0';
            $_REQUEST['tool_transfer'] = $currentSettings['tool_transfer'] ?? '0';

            // Set the group filter for file search
            $_REQUEST['tool_files_keyword'] = $groupKey;

            // Preserve screenshot settings if they exist
            if (!empty($currentSettings['tool_screenshot_x'])) {
                $_REQUEST['tool_screenshot_x'] = $currentSettings['tool_screenshot_x'];
            }
            if (!empty($currentSettings['tool_screenshot_y'])) {
                $_REQUEST['tool_screenshot_y'] = $currentSettings['tool_screenshot_y'];
            }

            // Update the prompt
            $updateResult = BasicAI::updatePrompt($promptKey);

            if ($updateResult['success']) {
                $retArr['success'] = true;
                $retArr['message'] = 'File search enabled on general prompt with WORDPRESS_WIZARD filter';
            } else {
                $retArr['error'] = $updateResult['error'] ?? 'Failed to update prompt';
            }

            return $retArr;

        } catch (\Throwable $e) {
            error_log('Enable File Search Error: ' . $e->getMessage());
            $retArr['error'] = 'Failed to enable file search: ' . $e->getMessage();
            return $retArr;
        }
    }
}
